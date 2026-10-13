# Follow-up round from a frozen clone, pausable — 2026-10-13

Status: **proposed, for Codex review.** Nothing below has been set up yet, except where
§2 says so.

## 1. Why

The follow-up round to the full historic pass is about 28–35 h of machine time (§6). The
time itself is not the problem. While a round runs from the working checkout, the operator
cannot work:

- **Committing restarts every worker.** `WorkerCode::isStale()` compares the checkout's
  commit with the one the worker booted on. `AppServiceProvider::registerStaleWorkerExit()`
  makes a worker whose checkout has moved on exit before its next job, and
  `restart: unless-stopped` brings it back on whatever the checkout now holds. Any commit
  does this, even a documentation-only one (seen today: every worker restarted at 07:46:56
  UTC, two seconds after b712ef11d).
- **Code changes strand the round.** Dispatched rounds bind `CodeRevision`, a hash of
  `app/`, `config/`, `database/`, `resources/`, `routes/`, `bootstrap/app.php`,
  `bootstrap/providers.php` and `composer.lock`. After a restart onto changed code, every
  chain still queued is refused. That is true whether the change was committed or not: a
  worker that restarts for any other reason boots on the uncommitted files.
- **No Dusk.** `sail dusk` swaps `.env` for its whole run, so workers and round commands see
  the empty `testing_dusk` database (memory: dusk-repoints-db-during-run).
- **The machine is busy:** CPU, Staging disk I/O and the media engines.

The operator does not need the exact new cuts to make progress on the rest of the historic
defect plan. The round should therefore run beside other work, and the operator should be
able to pause it and resume it at will.

## 2. Already done (13-10, on master b712ef11d, pushed)

- VideoToolbox encoding (887efbc84). Host service LaunchAgent `com.crockenhill.ffmpeg-host`
  on :2024, `--jobs 2`, mapping `/mnt/historic-work` and `/var/www/html/storage`. Reachable
  from the worker containers. The cut suites pass 121/121 on the real encoder.
- Main `.env` has `VIDEO_EXTRACTION_ENCODER=h264_videotoolbox`,
  `HOST_FFMPEG_URL=http://host.docker.internal:2024`, `HISTORIC_MEDIA_WORKERS_FFMPEG=2` and
  `HISTORIC_MEDIA_WORKERS_LLM=1`. The workers restarted onto this `.env` at 07:46:56, but
  their replica counts are still the old ones (ffmpeg 1, LLM 2) until `compose up`. That
  does no harm while the queues are empty.
- The watcher stops only for its own runs' failed jobs, and reports a `parked` bucket from
  `watch-state.json` (b712ef11d).
- The run list for the round: `storage/scratch/endings-rule/followup-runs.php` writes
  `followup-runs.json` (§5).

## 3. Design

### 3.1 The round runs from a frozen clone

`../crockenhill-round` is a real clone (not a worktree: `RepositoryCommit` needs a `.git`
directory) at the round's commit. Its HEAD never moves during the round, so:

- its workers never see a commit change and never restart themselves;
- its `CodeRevision` never moves, so no queued chain is ever refused;
- its `.env` is its own, so Dusk in the main checkout cannot reach it.

The live `storage/` stays the main checkout's, mounted over the clone's
`/var/www/html/storage`. Snapshots, watch state, logs, scratch tooling, the `local` disk and
the service artifacts all stay where they are today. The watchers ran this way, from
`../crockenhill-webm` with the live storage, through the whole full pass.

### 3.2 What runs from the clone

Everything that dispatches, executes or judges round work:

| Piece | Today | Round |
|---|---|---|
| The six worker services (`queue.worker`, `-video`, `-historic-ffmpeg`, `-whisper`, `-llm`, `-orchestration`) | compose project `crockenhill`, mount `.` | compose project `crockenhill-round`, mount the clone, plus main `storage/` |
| Watchers (main snapshot only; Tier B is a subset, so one is enough) | `docker run` from a clone | `docker run` from the round clone |
| `autotick.sh` (rerun-extract every 10 min) and `front-loop.sh` (reorders queued follow-ups) | `sail exec` in `laravel.test`, which is **main code** | a `round-tools` container from the round clone |
| `rerun-*` dispatch commands, `sermons:re-extract` | `sail artisan` (main code) | `docker exec round-tools php artisan …` |
| Host ffmpeg script | main `scripts/ffmpeg_host_server.py` | the clone's copy (LaunchAgent argument) |

The helper loops must move too. Run from `laravel.test`, they compute `CodeRevision` from
the main checkout, so they would dispatch with the wrong revision, or be refused, the
moment main changes.

### 3.3 Keeping main away from the round's queues

Main and the round share MySQL and Redis. Three ways main could still reach the round:

1. **Main's workers.** `sail up -d` in main starts main's own worker services again. They
   read the same Redis queues on main's code, and would take round jobs. The fix: stop and
   remove main's worker containers for the round, and set main's
   `HISTORIC_MEDIA_WORKERS_*` to 0. `queue.worker` and `queue.worker-video` have no replicas
   variable: add `replicas: ${QUEUE_WORKERS_DEFAULT:-1}` / `${QUEUE_WORKERS_VIDEO:-1}` to
   `docker-compose.yml` and set those to 0 too. Compose files are outside `CodeRevision`.
2. **Main's dispatches.** The dev app has `QUEUE_CONNECTION=redis`. Anything it dispatches
   lands on the round's queues and runs on round code. A `queue:restart` or `queue:pause`
   from main would also reach the round's workers, since both are cache keys. The fix:
   point main's `.env` at its own Redis databases while a round runs (`REDIS_DB=2`,
   `REDIS_CACHE_DB=3`; the round keeps 0 and 1). Main can then run its own workers on its
   own queues for dev work.
3. **Database writes.** These cannot be separated. The rule for the round: nothing in main
   writes to a member run (a corrections applier, `sermons:re-extract`, the dev UI's review
   actions on those runs). Reading is fine. Tests use `testing` and `testing_N`, and Dusk
   uses `testing_dusk`, all with array cache and sync queues (phpunit.xml), so the test
   suite and Dusk are safe.

### 3.4 Pause and resume

`storage/scratch/followup-round/round.sh` (shared through storage; runs on the host):

- `round.sh pause`:
  - runs `queue:pause redis:<queue>` in `round-tools` for every queue in
    `HistoricRerunWatch::queues()`;
  - each worker finishes the job it holds and takes no other. An ExtractSermon can take
    10–15 min; the command waits until nothing is reserved, then reports;
  - autotick already dispatches nothing while any queue is paused (`progress.php` prints
    `paused`). front-loop only reorders, which is harmless, but it should skip while
    paused too.
- `round.sh resume`: `queue:resume` for the same queues.
- `round.sh status`: whether paused, queue depths, reserved jobs, and progress from
  `watch-status.json` (with the new `parked` bucket), plus the last cut's time.
- `round.sh stop`: pause, wait for idle, then `docker stop` the round workers. For a full
  machine. `round.sh start` reverses it.

Pause costs nothing. Each run's progress lives on its own row and stamps, and Redis keeps
the queued jobs. `retry_after` only applies to reserved jobs, and a paused worker reserves
none.

### 3.5 Moving the round onto new code mid-round

This is not the normal case: new code waits for the next round. When it cannot wait:

1. `round.sh pause`, then wait for idle.
2. Pull the queued chain heads, saving their payloads, as `hold-jobs.php --remove` did for
   the hold list.
3. Fast-forward the clone, `docker restart` the round workers and `round-tools`, then check
   `ps` ELAPSED.
4. Take a new snapshot of the members that are not finished, and point the watcher and
   autotick at it.
5. Dispatch with explicit run lists, then `round.sh resume`.

This costs the in-flight chains, and the edge decodes if the change moves
`MediaProcessingVersion::signature()`.

### 3.6 After the round

Stop and remove `crockenhill-round`. Restore main's Redis DBs and worker replicas, then run
`sail up -d`. Point the LaunchAgent back at main's script. Keep the clone until the round's
listening is accepted.

## 4. Setup steps

1. **Clone.**
   - `git clone -b master crockenhill crockenhill-round`, a full clone so it does not
     depend on main's object store, at b712ef11d (or the round's commit).
   - `cp -cR` `vendor` and `public/build`, and copy `.env`.
   - `mkdir -p bootstrap/cache`.
   - Check: `CodeRevision::compute()` in the clone equals main's at the same commit.
2. **Compose override** `docker-compose.round.yml`, in the clone:
   - each worker service gets `- '${MAIN_CHECKOUT}/storage:/var/www/html/storage'`;
   - the default network is the external `crockenhill_sail`, so `mysql`, `redis` and
     `whisper` resolve.

   Bring it up with
   `docker compose -p crockenhill-round -f docker-compose.yml -f docker-compose.drive.yml -f docker-compose.round.yml up -d --no-deps <six worker services> round-tools`.
   `--no-deps` and the separate project name mean main's `mysql`, `redis`, `laravel.test`
   and `whisper` are never recreated.
3. **Main.**
   - Stop and remove main's six worker containers.
   - Set the worker replica variables to 0 (and add the two missing replica variables).
   - Move main to `REDIS_DB=2` / `REDIS_CACHE_DB=3`.
   - Restart `laravel.test` so it reads that `.env`.
4. **LaunchAgent:** point it at `../crockenhill-round/scripts/ffmpeg_host_server.py`,
   restart it, and `curl` it from a round worker.
5. **Round tooling** in `storage/scratch/followup-round/`:
   - copies of `autotick.sh`, `front-loop.sh`, `progress.php` (with the `parked` column)
     and `extract-runs.php`, pointed at the new snapshot;
   - `round.sh`.
6. **Snapshot:** `historic-import:rerun-snapshot` from `round-tools`, for the round's
   members (§5). Write `watch-state.json` with `parked` = the members not yet released (the
   decision-page runs and the operator runs).
7. **Watcher, autotick, front-loop:** start them from `round-tools`.
8. **Dispatch.**
   - Explicit run lists only (runbook rule): rerun-redetect, rerun-recompose, rerun-extract,
     each by route.
   - Watch the first VideoToolbox cut through `AssessSermonVideoQuality`.
   - After the first 10–15 cuts, measure per-cut time at width 2 and give the firm ETA.
9. **Prove the isolation** before leaving it unattended:
   - make a commit in main and see no round worker restart;
   - run `sail artisan queue:restart` in main and see no round worker restart;
   - run one Dusk test in main and see that the round's `progress.php` still reads the real
     database.

## 5. Round contents (followup-runs.json, 13-10)

438 runs in the full pass's snapshots (Tier B is a subset of main). 300 to touch, and the
219 finished runs are left as they are (operator 13-10).

| Group | Runs | Route |
|---|---|---|
| Ready | ~189 | amended-D1 hold list 140, detected-not-cut 12, processing 10, recompose 12, fixed failures 9 (1010, 1023, 1035, 1077, 1136, 1219, 1220, 973 and 945), fresh draws 4 (940, 954, 1073, 1268) |
| Content holds | 37 | cut with `sermons:re-extract --held-section` (operator 13-10); the holds stay; then a release page |
| Decision page | 37 | parked until the page's rulings are applied as rules and corrections |
| Operator | 2 | 1223 (custody stop on a song asset), 1314 (prayer hold with no section) |
| Finished-run repairs | 37 | 26 bound to a legacy song video (re-promote or re-cut); 16 marker-flagged songs (recompose and re-match) |

## 6. Time

The full pass's ffmpeg queue averaged 8.9 min per cut (ExtractSermon 6.9, EnhanceAudio 1.7,
Assess 0.8, small jobs). The spike gives VideoToolbox at width 2 about 6.1 min (not measured
for EnhanceAudio and Assess overlapping), and at width 1 about 7.7 min. For about 263 sermon
cuts plus the repairs: **~28 h at width 2, ~35 h at width 1, against ~40 h on libx264.**
Pauses add their own length and nothing more.

## 7. Open questions for review

1. **Content holds and edge decodes.** `sermons:re-extract` dispatches the cut directly and
   writes no corpus-rerun stamp. After the version bump to 9, a plan without current edge
   word timings resolves to `edge_word_timings_missing`, and re-extract refuses a plan that
   requires review. Proposed: the 37 go through the round's normal route first (edges
   decoded, plan parked for the held sermon), then `--held-section`. Does that path stamp the
   cut so the watcher counts it, and does `HistoricRerunDiff` treat the parked-then-re-cut
   run correctly?
2. **Which queues round work uses.** Do any round jobs land on `default`, `video`,
   `livestream` or `audio` (served by `queue.worker` and `queue.worker-video`), or only on
   the `historic-*` queues? If only `historic-*`, main could keep its two general workers
   and the round would run only the four historic services.
3. **`queue:pause` scope.** Laravel stores the paused flag in the default cache store. Check
   that with main on `REDIS_CACHE_DB=3`, main's `queue:pause` / `queue:restart` cannot touch
   the round, and the round's can.
4. **Separate compose project on the external network.** Check that the clone's relative
   paths (`./docker/php/php.ini`, `./docker/8.4` build context) resolve inside the clone, and
   that the image `sail-8.4/app` is reused, not rebuilt.
5. **The `testing` database is shared** between main's suite and anything else using it.
   Nothing in the round should, but the round's `.env.testing` (copied) must never be used
   by round tooling.
6. **Anything that writes outside a member run** but still affects one: the song catalogue
   (OpenLP sync), scripture passages, speakers. Should these freeze for the round, or are
   they read once per job and safe?
