# Controlling a historic-video pass

How to start, watch, stop and resume a bounded historic-video pass, written from
what the 2026-08-29 pilot actually did rather than from what was expected.

## Before every pass

1. **Mount the drive.** The historic mounts are opt-in:

   ```
   COMPOSE_FILE=docker-compose.yml:docker-compose.drive.yml vendor/bin/sail up -d
   ```

2. **Restart the queues after mounting.** Docker Desktop hands a worker started
   before the mount a stale view of it. `vendor/bin/sail artisan queue:restart`
   avoids that without a full `down`/`up`.

3. **Measure free space on the host, not in the container.** `df` inside the
   container reports the host's boot volume, not the bind-mounted drive: it said
   30 GiB free of 461 GiB while the drive held 444 GiB free of 1.8 TiB. The plan
   this work came from was sized against that wrong number.

   ```
   df -h "$CBC_HISTORIC_WORK_PATH"
   ```

   `sermons:import-historic-videos` prints what the pass needs and says plainly
   that it cannot measure what is there. Compare the two yourself.

4. **Dry-run the pass.** `--dry-run` verifies every selected source's existence,
   size and SHA-256 against the approved manifest before anything is dispatched.

## Selecting a pass

Name immutable manifest keys with `--only`. Never use `--limit`: it selects a
different corpus and leaves no trace of having done so, and a definitive run
refuses it. `--only` is a checkpoint selector — the manifest and plan hashes are
computed from the manifest's entries, so a bounded pass belongs to the same
approved round as a full one, and everything it does not touch stays pending.

## Stopping a pass

**Killing the wrapper does not stop the work.** Backgrounding `docker exec …` and
killing the local process leaves the command running inside the container. This
happened during the pilot and is the single most important thing on this page.

The real sequence, in order:

1. **Stop new dispatch.** Find and signal the in-container process:

   ```
   docker exec crockenhill-laravel.test-1 \
     sh -c "ps aux | grep '[s]ermons:import-historic-videos'"
   docker exec crockenhill-laravel.test-1 kill -TERM <pid>
   ```

2. **Let the workers finish what they hold.** `queue:restart` asks each worker to
   exit after its current job rather than mid-FFmpeg:

   ```
   vendor/bin/sail artisan queue:restart
   ```

3. **Verify.** Nothing should remain queued and nothing should be mid-flight:

   ```
   docker exec crockenhill-laravel.test-1 sh -c \
     "ps aux | grep '[a]rtisan queue:work'"
   vendor/bin/sail artisan tinker --execute \
     'echo App\Models\MediaProcessingLog::whereIn("status",["pending","started","processing"])->count();'
   ```

Record the dispatch process id and the worker container names in the pass log. A
stop that names neither cannot be verified afterwards.

## Resuming

Re-run the same `--only` keys. The importer resumes on the manifest's own dedup
keys: an identity already completed under this manifest is counted as resumed
rather than dispatched again, one still in flight is left alone, and a failed
exact run is retried. Re-running a pass costs no additional model spend for the
identities it already finished.

## A stale drive mid-pass

If the drive stops being readable while a pass is running, dispatch stops at the
first item whose sources it cannot read and the command exits with
`aborted_stale_mount`. Nothing already dispatched is disturbed and nothing is
marked failed — one mount problem must not become one permanent failure per
remaining item, which is what happened twice during the pilot.

Remount, restart the queues, re-run the same `--only` keys.

## After a pass

The summary distinguishes dispatched, resumed, retried, each class of skip, and
each terminal outcome the pass did not ask for — the last named individually with
its processing id, identity and the stage it stopped at. An empty queue is not
evidence of successful completion; the terminal-outcomes table is what to read.
