# Follow-up round from a frozen clone, pausable — 2026-10-13 (rev 2)

Status: **revised after Codex's review of rev 1** (findings in §9). Not set up. Rev 2 adds
the following:

- distinct Redis databases for PHPUnit and Dusk;
- a recovery route for the content-held runs;
- a way to withdraw a run's unfinished round;
- explicit routes with parked runs excluded;
- a complete Compose override;
- a staged start.

## 1. Why

The follow-up round is about 28–35 h of machine time (§8). The operator does not need its
exact cuts to make progress on the rest of the historic defect plan, so the round should run
beside other work, and pause and resume at will. While it runs from the working checkout,
other work is unsafe:

- **Any commit restarts every worker.**
  - `WorkerCode::isStale()` compares the checkout's commit with the boot commit.
  - `AppServiceProvider::registerStaleWorkerExit()` sets `shouldQuit` before the worker's
    next reservation, and `restart: unless-stopped` brings it back on the new checkout. A
    job already running finishes.
  - Documentation-only commits count, because this check reads the commit identity, not
    `CodeRevision`. Seen 13-10: all seven worker processes asked to exit at 07:46:55, as
    b712ef11d landed.
- **Changed code runs queued work silently.**
  - Rev 1 said queued chains would be refused, which is wrong.
  - `CodeRevision` guards dispatch (`CorpusRerunGuard::snapshotRefusal`) and the acceptance of
    evidence. No check at execution time stops a queued job from running the changed code.
  - A restart onto changed code therefore produces evidence stamped with the old revision but
    made by the new code. `CodeRevision` hashes the file contents of `app/`, `config/`,
    `database/`, `resources/`, `routes/`, `bootstrap/app.php`, `bootstrap/providers.php` and
    `composer.lock`, uncommitted edits included.
- **Tests and Dusk touch shared state.**
  - `sail dusk` swaps `.env` for its run, so processes that start meanwhile read the swapped
    file.
  - `CacheQueueRedisIsolationTest` flushes the Redis cache store. `.env.testing` leaves
    `REDIS_CACHE_DB` unset, so it flushes database 1, the workers' cache, which holds the
    queue pause flags.
  - `DuskTestCase` flushes `.env.dusk.local`'s cache database 2.
  - Local Dusk and serial PHPUnit share MySQL `testing`.
- **The machine is busy:** CPU, Staging I/O, the media engines, whisper.

## 2. Already done (13-10)

- master b712ef11d, pushed:
  - VideoToolbox encoding (887efbc84). The host service LaunchAgent
    `com.crockenhill.ffmpeg-host` runs on :2024 with `--jobs 2`; the cut suites pass 121/121
    on the real encoder.
  - The watcher stops only for its own runs' failed jobs, and reports `parked` from
    `watch-state.json`.
- Main `.env` has the VideoToolbox encoder, `HISTORIC_MEDIA_WORKERS_FFMPEG=2` and `_LLM=1`.
  Replica counts change only at `compose up`.
- The operator's rulings on the decision page (17) were collected 13-10 and are not yet
  applied (§6).
- No scheduler, autotick or front-loop process is running. The watchers have been stopped
  since 12-10.

## 3. Isolation

### 3.1 The round runs from a frozen clone

`../crockenhill-round` is a full `git clone` (not a worktree: `RepositoryCommit` needs a
`.git` directory) at the round's commit. Its HEAD never moves, so its workers never restart
for a commit, and `CodeRevision` never moves under queued work.

**A fixed HEAD does not mean fixed contents.** `.env`, `vendor/`, `scripts/` and generated
caches sit outside `CodeRevision`. Rules for the clone: nothing in it is edited during the
round, `bootstrap/cache` is cleared at setup, and no `config:cache` is run. The round's
helpers live **in the clone** (`round-tools/`, untracked), not in shared
`storage/scratch`, so main cannot alter what the round executes. Workers can still restart
for memory, timeout or a restart signal; they then boot the same frozen contents.

The live `storage/` stays main's, mounted at `/var/www/html/storage` in every round
container. That keeps snapshots, watch state, logs, the `local` disk and the service
artifacts in one place. It also leaves main able to alter them, which the rules in §3.4
cover.

### 3.2 What runs from the clone

| Piece | Round |
|---|---|
| All six worker services. `queue.worker` is needed: `QueueScriptureEnrichment` dispatches `FetchBibleTextForSermon` to `default`, and `API_BIBLE_ENABLED=true`. `queue.worker-video` is kept because no audit has shown round work never reaches `video`. | compose project `crockenhill-round` |
| `round-tools`: a long-running container for the watcher, autotick and every round command | same project |
| Host ffmpeg script | the clone's copy; the LaunchAgent is restarted onto it |

**front-loop is not used.** It does not check for a pause, and its separate LREM and LPUSH
leave a window where an interrupted process loses a payload. The round has few fresh
detections for it to jump ahead of anyway.

### 3.3 Redis databases

`FLUSHDB` ignores key prefixes, so each consumer gets its own database. Redis has 16.

| Consumer | Queue DB | Cache DB | Where it is set |
|---|---|---|---|
| Round (clone `.env`, unchanged) | 0 | 1 | clone `.env` |
| Main dev app, and main's own workers if wanted | 4 | 5 | main `.env` during the round |
| PHPUnit, including `CacheQueueRedisIsolationTest`'s flush | 6 | 7 | `phpunit.xml` `<env … force="true">` (tracked, so every checkout gets it) |
| Dusk (`DuskTestCase` flushes its cache DB) | 8 | 9 | `.env.dusk.local` |

`queue:pause` and `queue:restart` write to the default cache store, so main (cache 5) can no
longer pause or restart the round (cache 1), and the round's own commands can. Main's
`laravel.test` restarts after the `.env` change. Any process booted before it keeps the old
settings, so setup lists the live processes first (§5.1).

### 3.4 Rules while the round runs

Nothing in main may:

- write to a member run or anything hanging off it: sections, sermons, service items, song
  videos, holds, corrections, publication records, review state. The dev UI's review actions
  on those runs count. Reading is fine;
- run migrations, restores, seeds or global maintenance against the shared MySQL;
- edit the song or lyric catalogue (including an OpenLP sync), scripture passages or
  speakers. Jobs read these live, and `SongCatalogue` resolution is cached per process while
  lyric checks re-read rows, so a mid-round edit gives inconsistent inputs;
- run cleanup or reclamation commands against shared storage, or touch
  `storage/app/private/<round snapshot>/`, staged sources or the round's
  `section-publications/`;
- retry, forget or flush `failed_jobs`. The watcher reads it, and an unidentifiable failure
  stops it;
- restart the host services (ffmpeg :2024, whisper :2022, classifier :2023), or load them
  heavily, without pausing the round first.

Allowed: code, commits, pushes, the PHPUnit suite and Dusk (after §3.3), and reads.

## 4. Settling the full pass's unfinished rounds (new code)

The new snapshot accepts only completed runs, plus the failed cases the guard admits.
Saved state on 13-10 (`followup-runs.json`, `processing-steps.php`):

| Left by the pass | Runs | State |
|---|---|---|
| Cut chain pulled for the amended-D1 hold list after edge decoding | 84 (incl. 1250, 1304) | `processing` at `transcribe_output_edges`, extract stamp `media=extracted` on the old revision |
| Tier A re-transcription dispatched 10-10, never finished | 15 (929, 971, 977, 1168, 1195, 1196, 1208, 1209, 1265, 1329, 1342, 1350, 1353, 1359, 1366) | `processing` at `segmenting` or `transcribe_full_service`, no `transcribed_at` |
| Failed for a fault now fixed | 1010, 1023, 1077 (edges), 1136, 1219 (bounds), 1035 (Matroska duration) | `failed`, no manual-review reason the guard admits |

**Build `historic-import:rerun-withdraw {snapshot} {runs*}`.** It closes a run's unfinished
or failed round on that snapshot, so the run reads as it did before the round. It refuses
unless:

- no job anywhere references the run: ready lists, `:delayed` and `:reserved` sorted sets on
  every queue, and the run's own step rows not `running`;
- the round's effect is limited to what it reverts:
  - an extract round: sections unchanged since its detection round, no new media bound;
  - a Tier A round with no `transcribed_at`: the transcript's hash still equals the
    snapshot's before-state.

It then marks the stamp `withdrawn_at`/`withdrawn_reason` (kept as history; the guard skips
withdrawn stamps) and sets status to `completed` with `current_step` as the pre-round
before-state recorded. It writes nothing else. Tests cover each refusal.

**1220** is cut but its cleanup was refused, because a `prepare-section-publication-candidates`
step row was left `running`. It needs its own look (Codex: settling rows is not done by
removing messages). Parked until then.

## 5. Routes on the new snapshot S1

S1 is taken from `round-tools` after the rulings are applied and committed (§6) and the
clone is made. Its membership is all 300 runs to touch (that keeps the watcher's accounting
whole). Every dispatch names its runs; **the round helper refuses an empty list**, because
`rerun-*` treats omitted IDs as every member. Also build: `rerun-recompose`, `rerun-redetect`
and `rerun-extract` refuse to run without run IDs unless given `--all` (small code change,
with tests).

| Route | Runs | Steps |
|---|---|---|
| R1 recompose → extract | 63 detected/completed; 84 withdrawn (§4); 5 withdrawn fixed failures (1010, 1023, 1077, 1136, 1219); 12 composition-review (guard already admits `sermon_composition_review`); 973; the 38 finished-run repairs (26 legacy song-video bindings, 16 marker-flagged songs, overlapping) once decided | `rerun-recompose` (banked draws, current rules and answers), then `rerun-extract` |
| R2 redetect → extract | 940, 954, 1073, 1268 (admitted: fewer than two valid draws) | `rerun-redetect`, then `rerun-extract` |
| R3 Tier A, then a second snapshot S2 for detection | the 15 unfinished Tier A runs, plus 1035 (withdrawn) | `rerun-retranscribe` on S1; once transcribed, S2 for those runs, then `rerun-redetect`, then `rerun-extract` on S2 (runbook: Tier A to Tier B needs a second snapshot) |
| R4 content holds | 37 | **Code first:** the guard admits a run that a round parked for its held sermon (`sermon_section_content_held`, failed, with round stamps), as it admits composition review. Then R1. Extraction decodes the edges, stamps `media=extracted`, and parks at the held sermon again. Then `sermons:re-extract <id> --held-section=<§> --yes`, which keeps the stamp (Codex: the watcher counts it; the diff supports held parking). The holds stay. After that, a release page. |
| Parked (excluded from every list) | decision page 36 + 1202 until §6 lands; 1223, 1314 (operator); 1220 (§4); 945 (validation failed on coverage, 64% against a 70% floor; the guard does not admit it, and nothing yet shows it is fixed) | listed under `parked` in `watch-state.json` |

The decision-page runs move into R1 when their rulings are applied. Answers are applied
before S1 where possible, so they are not parked at all.

**The 38 finished-run repairs** go through R1 and are re-cut. The operator ruled to leave
finished runs alone; these are the exceptions, because their current media or song identity
is wrong. Correction to rev 1: the pass's main snapshot holds 438 runs (Tier B's 136 are a
subset), so **138 finished runs stay untouched**, not 219.

## 6. The decision-page rulings (13-10)

Applied as code (rules, with tests and a replay against the answer key) or as corrections,
committed before the clone is made:

- **Rules:**
  - pattern A: sermons end where the singing starts;
  - pattern B: a recording that is only a sermon is published as that sermon;
  - pattern C: a sermon interrupted by its reading stays one sermon;
  - pattern D: low-confidence readings directly before the sermon are kept;
  - readings: every reading directly before the sermon, except carol-service lessons;
  - talks: include the reading when the talk is about it. That needs one short page on the
    14 talks first.
- **Corrections:**
  - 1014: the prayer stays out;
  - 1026 and 1194: their readings go in;
  - 1344: closing music, question closed;
  - 930: the reading split is right;
  - 944: it is a sermon;
  - 1066 and 997: right as they stand.
- **Still open:**
  - 1281: "I bow before" starts at the section start. The operator heard "The Lord's my
    shepherd" as the second song, outside the clip, so find it in the audio.
  - 1363: the "Good morning" sits wrongly after the reading, and "So God did…" is a
    different speaker.
  - 943: the operator asked for a plainer explanation.

## 7. Setup and staged start

1. **Main first.**
   - List the live processes (`ps` in every container).
   - Set `phpunit.xml` and `.env.dusk.local` to DBs 6/7 and 8/9, and commit.
   - Prove it: run `CacheQueueRedisIsolationTest` and check that only DB 7 was flushed.
2. **Clone.**
   - `git clone -b master crockenhill crockenhill-round` at the round commit.
   - `cp -cR vendor`, copy `.env` (round DBs 0/1, VideoToolbox settings), `rm -rf bootstrap/cache/*.php`.
   - Check that `CodeRevision::compute()` equals main's at that commit.
3. **Compose override** `crockenhill-round/docker-compose.round.yml`:

   ```yaml
   x-round-mounts: &round-mounts
       - '.:/var/www/html'
       - './docker/php/php.ini:/etc/php/8.4/cli/conf.d/99-livestream.ini'
       - '${MAIN_CHECKOUT}/storage:/var/www/html/storage'
       - '${CBC_SERVICE_VIDEOS_PATH}:/mnt/cbc-service-videos:ro'
       - '${CBC_HISTORIC_WORK_PATH}:/mnt/historic-work'
   services:
       queue.worker:                         { volumes: *round-mounts }
       queue.worker-video:                   { volumes: *round-mounts }
       queue.worker-historic-ffmpeg:         { volumes: *round-mounts }
       queue.worker-historic-whisper:        { volumes: *round-mounts }
       queue.worker-historic-llm:            { volumes: *round-mounts }
       queue.worker-historic-orchestration:  { volumes: *round-mounts }
       round-tools:
           image: 'sail-8.4/app'
           restart: unless-stopped
           volumes: *round-mounts
           environment: { WWWUSER: '${WWWUSER}', LARAVEL_SAIL: 1 }
           command: ['sleep', 'infinity']
           networks: [sail]
   networks:
       sail:
           external: true
           name: crockenhill_sail
   ```

   - Volume lists merge with the base file's. The base `.:/var/www/html` resolves against the
     project directory, which is the clone.
   - Check with `docker compose -p crockenhill-round -f docker-compose.yml -f docker-compose.drive.yml -f docker-compose.round.yml config`.
   - Then `up -d --no-deps --no-build --pull never <six workers> round-tools`, after confirming
     `docker image inspect sail-8.4/app`.
   - Separate project and `--no-deps`: main's `mysql`, `redis`, `laravel.test` and `whisper`
     are never recreated.
4. **Stop main's workers.**
   - Stop and remove main's six worker containers, and set main's replica variables to 0. Add
     `replicas:` variables for `queue.worker` and `queue.worker-video` to `docker-compose.yml`;
     compose files are outside `CodeRevision`.
   - Move main to DBs 4/5 and restart `laravel.test`.
5. **LaunchAgent:** restart it onto the clone's `scripts/ffmpeg_host_server.py`, then `curl`
   it from a round worker.
6. **Settle the pass's rounds:** `rerun-withdraw` for the §4 runs on the old snapshot, from
   `round-tools`.
7. **Snapshot S1.**
   - Start the watcher once, so it writes a valid `watch-state.json` with its membership hash
     and baseline.
   - Add `parked`, then restart the watcher. It reads its state at start; any later change
     to `parked` also needs a restart.
8. **Staged start.**
   - (a) Dispatch **one** representative R1 run. Follow it through every detached job:
     edges, ExtractSermon on VideoToolbox, EnhanceAudio, Assess, thumbnail,
     PrepareSectionPublicationCandidates and PromoteHistoricAssets. Check the watcher's
     accounting.
   - (b) `round.sh pause` mid-cut, check idle and the flags, then `round.sh resume`.
   - (c) The isolation proofs:
     - a commit in main, with no round worker restart;
     - `queue:restart` in main, with no round worker restart;
     - `CacheQueueRedisIsolationTest` in main, with the round's pause flag intact;
     - one Dusk test, with the round's `progress.php` still reading the real database.
   - (d) 10–15 runs to measure per-cut time and give the firm ETA.
   - (e) The full route lists, and autotick.

### 7.1 `round.sh` (in `crockenhill-round/round-tools/`)

- `pause`: `queue:pause` on every queue in `HistoricRerunWatch::queues()`, then wait until
  every `:reserved` set is empty. A cut can take 10–15 min.
- `resume`:
  - if `watch-stop.json` exists, refuse and print it. A watcher stop is handled (archive the
    file, fix or acknowledge the cause, restart the watcher) before resuming;
  - otherwise `queue:resume` on the same queues.
- `status`: paused or not, queue depths (ready, delayed, reserved), watcher progress
  including `parked`, the last cut and failed jobs since the start.
- `stop` / `start`: pause and go idle, then `docker stop` / `docker start` the round workers.

autotick: the same loop as the pass, on S1. It dispatches nothing while paused or stopped,
uses an explicit run list of the R1 members not yet dispatched, refuses an empty list, and
does not use front-loop.

## 8. Time

The full pass's ffmpeg queue averaged 8.9 min per cut (ExtractSermon 6.9, EnhanceAudio 1.7,
Assess 0.8, small jobs). The spike estimates VideoToolbox at width 2 at about 6.1 min, but
the overlap of EnhanceAudio and Assess was not measured. Width 1 is about 7.7 min. For about
263 sermon cuts plus the repairs: **~28 h at width 2, ~35 h at width 1, against ~40 h on
libx264.** Stage (d) replaces this with a measured figure. Time spent paused adds its own
length.

## 9. After the round

Before restoring main's databases and workers:

- the queues, including delayed and reserved, hold no round work, `default` and `video`
  included;
- no pause flags remain;
- the watcher and autotick are stopped;
- the round's `failed_jobs` are reviewed.

Then stop and remove `crockenhill-round`, move main back to 0/1, restore its replicas, run
`sail up -d`, and point the LaunchAgent back at main. Keep the clone until the round's
listening is accepted.

**Mid-round code changes are not supported unattended.** New code waits for the next round.
If a fix cannot wait: pause, withdraw (§4) the runs that are not finished, end the round,
and start a new round with a new snapshot. Keep the original snapshots: a new baseline must
not swallow earlier damage.

## 10. Codex review of rev 1 (13-10), and what changed

| Finding | Rev 2 |
|---|---|
| PHPUnit flushes cache DB 1 (the round's pause flags) | §3.3: PHPUnit on 6/7 via `phpunit.xml` force |
| Dusk flushes DB 2, rev 1's main queue DB; local Dusk uses MySQL `testing` | §3.3: Dusk 8/9, main 4/5; §1 corrected |
| The 37 held runs are refused by the guard; `--held-section` alone fails without current edges | §5 R4: guard admission, then R1, then `--held-section` |
| `parked` only reports; an empty run list means every member | §5: helper refuses empty lists; `--all` required in code |
| Compose: the network key is `sail`; `round-tools` was undefined | §7.3: full override |
| "Queued chains are refused" was wrong | §1 corrected: changed code runs silently |
| `FetchBibleTextForSermon` goes to `default` | §3.2: `queue.worker` runs from the clone |
| front-loop ignores pauses and can lose a payload | §3.2: not used |
| The watcher reads its state once; a stop needs a restart | §7.1 and §7 step 7 |
| The mid-round upgrade steps were incomplete | §9: not supported unattended; withdraw and start a new round |
| Teardown must reconcile first | §9 |
| Shared catalogues are live inputs | §3.4: frozen for the round |
| Wider routes from main into the round | §3.4 |
| Membership: 138 untouched, not 219; 1202 parked; 945 not substantiated; processing runs need settling (99, not 10) | §4, §5 |
