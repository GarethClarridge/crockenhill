# Historic round runbook

How a historic round runs: a re-cut, recompose, re-detection or re-transcription over a snapshot of
runs, from a frozen clone, pausable, with a watcher. **What** a round is for and **which** runs it takes
are in the [plan, §4](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md). The rulings behind
it are in the [rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md).

Distilled on 2026-10-16 from the procedure parts of the follow-up round plan (§1, §3, §7–§9 of the
[archived plan](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md), quoted word for word
where marked), the full pass's execution record
([archived boundary plan §6.3.9](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md#639-full-pass-execution-record-2026-10-10--the-one-current-instruction-set))
and the lessons logged during the rounds of 10-10 to 10-16. Section numbers here are this runbook's
own. Code comments that cite "FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13 §4/§5" mean the archived plan.

## 1. Why a frozen clone

While a round runs from the working checkout, other work is unsafe (archived plan §1):

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

**Never run tests in the clone.** It has no `.env.testing` (untracked, so a clone does not get one)
and shares MySQL `crockenhill` with main, so `RefreshDatabase` drops every table. That happened on
2026-10-15 (recovery in §9). Run tests in main only.

## 2. Isolation

### 2.1 The round runs from a frozen clone

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
artifacts in one place. It also leaves main able to alter them, which the rules in §2.4
cover.

**Moving the clone to a new round commit** (as on 2026-10-16):
1. keep the old line as a branch (`round-YYYYMMDD-line`);
2. reset the clone's `master` to the round commit;
3. if the lockfiles are unchanged, `vendor/` stands; otherwise copy it again;
4. clear `bootstrap/cache/*.php`;
5. check that the hashed directories match main's. Main's untracked `.DS_Store` files are the only
   expected difference.

### 2.2 What runs from the clone

| Piece | Round |
|---|---|
| All six worker services. `queue.worker` is needed: `QueueScriptureEnrichment` dispatches `FetchBibleTextForSermon` to `default`, and `API_BIBLE_ENABLED=true`. `queue.worker-video` is kept because no audit has shown round work never reaches `video`. | compose project `crockenhill-round` |
| `round-tools`: a long-running container for the watcher, autotick and every round command | same project |
| Host ffmpeg script | the clone's copy; the LaunchAgent is restarted onto it |

**front-loop is not used.** It does not check for a pause, and its separate LREM and LPUSH
leave a window where an interrupted process loses a payload. The round has few fresh
detections for it to jump ahead of anyway.

### 2.3 Redis databases

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
settings, so setup lists the live processes first (§3, step 1).

Outside a round, main runs on 0/1 with its own workers. Keep a copy of main's `.env` from before each
round in the session scratchpad: the closing step restores it (§8).

### 2.4 Rules while the round runs

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

Allowed: code, commits, pushes, the PHPUnit suite and Dusk (after §2.3), and reads.

Also: run no tests in the clone (§1), and nothing that reads a composition with `compose()` or a plan
with `resolve()` on a member run. Those calls write the composition back (`sermon_composition` and the
section's composition flag), which is the pipeline's own recompute, but it is still a write.

## 3. Setup and staged start

1. **Main first.** List the live processes (`ps` in every container). PHPUnit and Dusk already run on
   their own Redis databases (`phpunit.xml`, `.env.dusk.local`; §2.3).
2. **Clone.** Make it, or move it to the round commit (§2.1). Copy `.env` (round DBs 0/1, the
   VideoToolbox settings) and check that `CodeRevision::compute()` equals main's.
3. **Compose override.** `docker-compose.round.yml` is tracked:

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
4. **Stop main's workers.** Remove main's worker containers, set its replica variables to 0, move it
   to DBs 4/5 and restart `laravel.test`.
5. **Host ffmpeg.** Restart the LaunchAgent `com.crockenhill.ffmpeg-host` (:2024) onto the clone's
   `scripts/ffmpeg_host_server.py`, then `curl` it from a round worker.
   - The LaunchAgent's Python needs macOS removable-volume access to read Staging. On 10-13 the first
     encode hung in `open()` until the permission was granted.
6. **Settle earlier rounds** that the new one replaces: `historic-import:rerun-withdraw` (§4.4).
7. **Snapshot.** Take it from `round-tools`.
   - Start the watcher once, so it writes `watch-state.json` with its membership hash and baseline.
   - Add `parked` runs, then restart the watcher: it reads its state at start.
   - Use a fresh directory name. On 10-15 a snapshot named like an existing file was cached by
     Docker's mount and had to be renamed.
8. **Staged start.**
   - (a) Dispatch one representative run. Follow it through every detached job: edges,
     `ExtractSermon`, `EnhanceAudio`, `AssessSermonVideoQuality`, thumbnail,
     `PrepareSectionPublicationCandidates`, `PromoteHistoricAssets`. Check the watcher's accounting.
   - (b) `round.sh pause` mid-cut, check idle and the flags, then `round.sh resume`.
   - (c) A batch of 10–15 runs to measure per-cut time.
   - (d) The full route lists, then autotick.

## 4. Commands and routes

### 4.1 The route a run takes

| Step | Command | What it does |
|---|---|---|
| Snapshot | `historic-import:rerun-snapshot {runs} --routing=… --routing-sha256=…` | Membership, code revision, each run's state and listening routes |
| Tier A | `historic-import:rerun-retranscribe {snapshot} [runs]` | Whisper afresh; reuses RMS and audio timeline; stamps `detection: none` |
| Tier B | `historic-import:rerun-redetect {snapshot} [runs]` | Four draws composed; any question holds the run for review |
| Review | `structure:ensemble-export-questions {runs} --sample-decided=… --sample-cuts=… --seed=…` | Review page: questions, talk-edge checks, samples, skim list |
| Answers | `structure:ensemble-apply-answers {export} {answers} --execute` | Banks each answer; records sampled cuts in `sample-results.json` |
| Recompose | `historic-import:rerun-recompose {snapshot} [runs]` | The answered draws composed again under current rules; no draw |
| Held text | `historic-import:held-transcript-report` then `service:release-content-hold` | Proposes held talks whose new text reads restored; the operator releases |
| Tier C | `historic-import:rerun-extract {snapshot} [runs]` | Cuts the media once |
| Diff | `historic-import:rerun-diff {snapshot}` | What changed; attention fails the batch |
| Backup | `rsync -a --exclude '._*'` of `storage/app/private/service-artifacts/` and `…/detection-rulings/` to `/Volumes/Sonnics/` | After every batch |
| Learning | `structure:ensemble-batch-report {runs} --export={dir} --report=…` | Questions, answers, samples, review minutes |

Every dispatch is a dry run without `--execute` and is bounded by `--max`.

### 4.2 What each route needs

- **Name the runs.** `rerun-recompose`, `-redetect`, `-extract` and `-retranscribe` refuse no run IDs
  unless given `--all`, and the round helper refuses an empty list. Name runs on every
  `rerun-redetect`: left unnamed, it skips runs whose banked draws still fit.
- **Tier A, then Tier B, needs a second snapshot.** The guard does not admit a finished
  transcription round on the same snapshot. Snapshot the transcribed runs (no `--routing`), start a
  watcher on it, and re-detect them there.
- **One cut per code revision per snapshot.** A run cut on a snapshot is not cut again on the same
  code. A changed composition (an answer, a recomputed review) needs a fresh snapshot.
- **A recompose replays banked draws and answers and re-syncs sections.** It would undo hand-made
  structural edits, so runs edited through the admin actions are never recomposed: **942, 974 and
  1363** (2026-10-15). Their route is `ProcessingRunOrchestrator::reExtract`, which resumes at
  `TranscribeOutputEdges` with the staging context recorded.
- **Runs whose banked edge evidence is invalid on current code** (for example a superseded run whose
  sermon another run adopted: 1126, 1378) also go through `reExtract`. `sermons:re-extract` plans
  before it decodes, so it resolves no segments for them.
- **Held sermons.** A run parked on a sermon content hold is cut with
  `sermons:re-extract <processing-id> --held-section=<§> [--dry-run]`. The hold stays, and the
  operator confirms on a release page afterwards (2026-10-13).
- **Weekly runs cannot enter a round snapshot.** They retry through `UnifiedMediaProcessor::retry`
  (the admin Retry path).

### 4.3 Replay harnesses (read-only, rolled back)

- Banked edge evidence is bound to the media version the plan was made under, and its identity
  hashes the encoder. Pin both: version 8 runs with `libx264`, version 9 with `.env`'s VideoToolbox.
- A resolver missing its evidence returns `edge_word_timings_error` in the plan's metadata **without
  throwing**, leaving an empty audit. Count errors explicitly.
- The pre-flight compares sections by position: one dropped section reads as every later section
  re-typed. Compare by title and position.

### 4.4 Withdrawing an unfinished round

`historic-import:rerun-withdraw {snapshot} {runs*}` (built `5571521c8`, `399691d54`). It closes a run's unfinished
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

Codex review (13-10), applied: "sections unchanged, no new media" is now proved, not assumed.
An extraction is refused when a section was made or cut after it was dispatched, when a song
video was bound after it was dispatched, or when the composition inputs no longer match the
identity the round composed from (the stamp's deferred plan, else the run's composition). Step
rows the round started and left active, with no job behind them, are cancelled with the round
(929's interrupted `transcribe_full_service`). Older active rows are left as the snapshot
recorded them (1250's `analyzing` from 02:08 on 10-10). Dry run after the change: all 105 still
ready.

## 5. Watching

### 5.1 The watcher

`historic-import:rerun-watch {snapshot}` runs in `round-tools`, without Claude (archived boundary plan
§6.3.9):
- It looks every 5 minutes.
- It stops on:
  - anything public;
  - a live hold gone;
  - extracted media lost;
  - a run superseded;
  - a published or quarantined file missing that was there when the watch began (staging files
    under their run's batch root);
  - any failed job since it began;
  - lost draws above 10% once 10 ensembles have run;
  - speaker identification enabled or queued;
  - a historic disk probe failing;
  - internal free space under 10 GB.
- A stop pauses every round queue: each worker finishes its job and takes no other, and nothing queued
  is lost. It writes `watch-stop.json` and exits.
- Held runs are progress, not stops. A stale song clip on a non-song section reads as pending, not
  lost (`7a69aac5d`).
- `--stop="listening: <item> <answer>"` raises a listening verdict through the same door.
- Each look writes `watch-status.json`, and `watch-state.json` keeps its start.

Resume: fix the cause, archive the stop file, `round.sh resume`, restart the watcher.

### 5.2 `round.sh` and autotick

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
uses an explicit run list of the round's members not yet dispatched, refuses an empty list, and
does not use front-loop.

Start autotick with `ROUND_SNAPSHOT=<snapshot>` and an explicit run list (for example the round's
`extract-runs.txt`).

### 5.3 Listening

- Build each listening page from the last working one (`build.py` clones it). Pills wrap, so the
  page never scrolls sideways.
- Ask which text matches, show service time, and play at 3× by default.
- One question per clip.
- Rulings live in the page's database (`rulings`) and survive a republish. Export them with
  `ArtifactData list`.
- Every edge a round moves by ≥ 1 s goes on the page, with the cut plus 12 s of the recording around
  it.

## 6. Preflight for every dispatch

1. Freeze exact membership in the snapshot; settle transcript and structure dependencies before
   cutting; retain or exclude unresolved cases explicitly rather than widening the batch.
2. Queues empty (queued, reserved, delayed); every worker started after the last code commit;
   staging and temp mounts readable and writable; free space for the batch on `/Volumes/Staging`
   and at least 10 GB on the internal disk (service artifacts live there); the previous batch
   backed up.
3. Reuse the canary's evidence for paths it covered; check representative first outputs of any
   path it did not; stop expansion on a new regression or an unassessable result. Outputs stay
   quarantined pending the plan's §4.5.
4. Also check live:
   - the Staging drive's port and link speed: USB 3, about 158 MB/s; on USB 2 a cut's timing check
     read at 36 MB/s;
   - `SPEAKER_IDENTIFICATION_ENABLED=false`;
   - `git status` clean on the round commit;
   - free space on Staging, the internal disk (≥ 10 GB) and Sonnics.
5. **Back up before a round:**
   - a database dump and the private app storage, to `/Volumes/Sonnics/_pre-<round>-backup-<date>/`
     with SHA-256 sums (as on 2026-10-10 and 2026-10-16);
   - the MySQL binlogs since the last base dump, which are ROW format and expire after 30 days.

## 7. Time

Measured on 2026-10-15, VideoToolbox:
- about 3.9 min a run for a recompose and cut;
- about 6 min each for held-sermon re-cuts; they can run in parallel (checked 2026-10-18: the command holds no lock or shared state). Canary one first after code changes.

The full pass on libx264 averaged 8.9 min a cut. Time spent paused adds its own length.

## 8. Closing a round

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
If a fix cannot wait: pause, withdraw (§4.4) the runs that are not finished, end the round,
and start a new round with a new snapshot. Keep the original snapshots: a new baseline must
not swallow earlier damage.

Also:
- rsync the service artifacts to `/Volumes/Sonnics/_service-artifacts-backup/` (checksum, no deletions);
- check that the ffmpeg LaunchAgent is reached from a main worker after it is pointed back.

## 9. Recovery

- **Internal disk low** (the watcher pauses under 10 GB):
  1. rsync the service artifacts to Sonnics, keeping the local copies, because records point at them;
  2. with the operator's approval, free old Docker images and the build cache;
  3. archive the stop file and resume.

  On 2026-10-14 this took free space from 10 GB to 28 GB.
- **Database lost** (2026-10-15):
  1. Restore the last base dump into a side database.
  2. Copy the binlogs to Sonnics.
  3. Replay them into the side database (`mysqlbinlog --rewrite-db`, then `--database` on the
     rewritten name, in a throwaway `mysql:8.0` container), up to the event before the first
     destructive statement.
  4. Verify the latest rows.
  5. Swap with one `RENAME TABLE`, keeping the damaged tables under another name.
  6. Restart the workers and resume.
- **A fix that cannot wait:** §8's last paragraph. Withdraw what is unfinished, end the round and
  start a new one on a new snapshot.
