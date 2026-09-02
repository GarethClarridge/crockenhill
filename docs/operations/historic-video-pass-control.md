# Controlling a historic-video pass

How to start, watch, stop and resume a bounded historic-video pass, written from
what the 2026-08-29 pilot and the 2026-09-02 learning batch actually did rather
than from what was expected.

## Before every pass

1. **Mount the drive.** The historic mounts are opt-in:

   ```
   COMPOSE_FILE=docker-compose.yml:docker-compose.drive.yml vendor/bin/sail up -d
   ```

2. **Restart the queues after mounting, then verify the workers actually
   restarted.** Docker Desktop hands a worker started before the mount a stale
   view of it. `vendor/bin/sail artisan queue:restart` avoids that without a full
   `down`/`up` — **when it works**. On 2026-09-02 three `queue:restart` calls were
   silently ignored: the restart key was newer than the worker boot, the queue was
   empty, and the daemons sat idle for 19 minutes without exiting. Every run in
   the pass then failed at its first job on
   `Historic staging context does not match this worker storage identity`, which
   reads like a mount fault and is not one.

   `queue:restart` is a request, not a guarantee. Check that PID 1 is actually
   younger than the request:

   ```
   for c in $(docker ps --format '{{.Names}}' | grep queue.worker); do
     echo "$c $(docker exec "$c" ps -o etimes= -p 1 | tr -d ' ') seconds old"
   done
   ```

   If the age did not drop, restart the containers —
   `docker restart crockenhill-queue.worker-historic-*` — and re-verify the mount
   afterwards. Nothing downstream is diagnosable until the workers are known good.

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

   Verify it took effect using the PID-1 age check above. A worker that ignores
   the request looks identical to one that has nothing to do.

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

## When the provider refuses a call

**A logged "Request rate limit has been exceeded." does not mean you were rate
limited.** That string is a hardcoded constant in `openai-php/client`, applied to
any HTTP 429 whatever its cause. On 2026-09-02 it was read as rate limiting for a
day; it was `service_tier: flex` capacity, with 99.98% of the account's request
and token budgets unused at the moment of every refusal.

The pipeline now handles this itself. `App\Support\OpenAiFlexFallback` re-sends a
`flex_unavailable` 429 on `service_tier: default` and rethrows any other 429
untouched, so no operator action is needed for the ordinary case. What it also
does is log the truth, which is what you read:

```
docker exec crockenhill-laravel.test-1 sh -c \
  "grep 'refused a request with 429' storage/logs/laravel.log | tail -20"
```

Read `error_code` first, and treat the two cases as unrelated problems:

- **`flex_unavailable`** — the shared flex pool for that model is full. It has
  nothing to do with this pass's volume, is **per model** (one model can be
  refused outright while another is fine), and cannot be relieved by pacing calls
  or lowering worker width. The fallback already paid the standard rate and
  carried on; the only thing to note is the extra spend.
- **`rate_limit_exceeded` / `insufficient_quota`** — this one *is* about the
  account. `x-ratelimit-remaining-requests` and `-tokens` in the same line will be
  near zero, and stopping dispatch is the right response.

To check a model's flex availability directly — worth doing before sizing a pass,
since a starved pool means every call pays the standard rate — probe it. This
costs a few tokens:

```
vendor/bin/sail artisan tinker --execute '
foreach (["gpt-5.6-luna", "gpt-5.6-terra", "gpt-5.4-mini"] as $model) {
    $ok = 0;
    $codes = [];
    for ($i = 0; $i < 3; $i++) {
        try {
            \OpenAI\Laravel\Facades\OpenAI::chat()->create(App\Support\OpenAiChatPayload::forModel([
                "model" => $model,
                "messages" => [["role" => "user", "content" => "ping"]],
                "service_tier" => "flex",
                "max_completion_tokens" => 16,
            ], reasoningEffort: "minimal"));
            $ok++;
        } catch (Throwable $e) {
            $codes[] = App\Support\OpenAiFlexFallback::errorCode($e) ?? get_class($e);
        }
    }
    echo "{$model}: flex {$ok}/3 ".implode(",", array_unique($codes)).PHP_EOL;
}'
```

**Use the fully-qualified facade and print what was caught.** A bare
`OpenAI::chat()` resolves to the SDK class rather than the Laravel facade and
throws `Call to undefined method`; swallowed by a bare `catch (Throwable) {}` that
reports a clean `0/3` for every model — a broken probe indistinguishable from a
starved pool. Every failure must name itself, which is the same lesson as the
429 message above.

On 2026-09-02 this returned `gpt-5.6-luna: flex 0/3 flex_unavailable` while both
other models returned `3/3` — one starved pool, everything else healthy. Those
three are the models the pipeline was using that day
(`SERVICE_STRUCTURE_MODEL`, `ANALYSIS_MODEL`, `SONG_MATCHING_OCR_MODEL`); check
the current values before trusting the list.

Successful calls carry the same budget figures: `grep 'chat completion usage'` and
read `remaining_tokens` / `token_limit` to see the real headroom a pass ran with.

## After a pass

The summary distinguishes dispatched, resumed, retried, each class of skip, and
each terminal outcome the pass did not ask for — the last named individually with
its processing id, identity and the stage it stopped at. An empty queue is not
evidence of successful completion; the terminal-outcomes table is what to read.

Read the database-owned dispositions rather than the queue:

```
vendor/bin/sail artisan historic-import:video-pass-status \
  --operation=<operation-id> --only=<keys> --performance
```

### `degraded` is not `completed`

A run whose analysis stage exhausted its retries falls back to substituted
analysis: no scripture reference, no summary, placeholder points and a
filename-derived title. It reaches `completed` in the database, so **judged on
completed count a pass that met more provider refusals can look better than one
that met fewer** — the 2026-09-02 batch reported six hollow sermons as its only
successes.

The report now refuses to call that completed:

- the disposition is **`degraded`**, and `mixed_terminal` where only some of an
  identity's runs degraded;
- the command **names each degraded identity** with its processing id;
- `--performance` excludes degraded runs from `clean_first_attempt`, so the
  throughput figure is of real work only. This is why the performance report is
  version 2 — a version 1 `clean_first_attempt` counted them, and the two cannot
  be compared.

**A degraded run is unresolved for the Phase 8 exit gate.** It needs re-analysis
from its surviving transcript before release — an LLM re-run, not reprocessing.
Do not close a pass with degraded runs unnamed in its report.
