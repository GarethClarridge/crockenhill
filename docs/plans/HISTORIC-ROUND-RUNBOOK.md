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
| Host ffmpeg script | the clone's copy; the LaunchAgent is restarted onto it. **`--jobs 2`, not 3** (11-03): three concurrent VideoToolbox encodes on the M1 Max's two engines intermittently truncate a clip's video while ffmpeg exits 0 (≈ 20 timing-check failures an hour; none at 2). More ffmpeg *workers* than host slots add nothing: the engines are the limit. |

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
- restart the host services (ffmpeg :2024, whisper :2022, aligned whisper :2025, classifier :2023), or load them
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
- **One recompose and one cut per run per commit, across snapshots** (corrected 11-03: a fresh snapshot at the same
  commit refuses "already re-run on this commit"). A run whose round recompose went wrong waits for a code commit; a
  run with only an answer to apply goes through `ProcessingRunOrchestrator::reExtract` (resumes at
  `TranscribeOutputEdges`) when its projection is current.
- **The recompose dry run cannot see hand edits:** it reads 942, 974 and 1363 "ready". Take them off the recompose list
  by hand.
- **Runs with no banked ensemble draws** (1377, 1378 on 11-02) go through `rerun-redetect`, after
  `historic-import:classify-audio` if they lack a timeline (then take a new snapshot).
- **Re-imports** (`sermons:import-historic-videos` under an operation) check the *whole* corpus root against the
  manifest before dispatching any `--only` item: every candidate recording on disk must be listed, and every
  *included* item must be present at its size (excluded items are not checked). Check the drive against the manifest
  before the round. Re-imports are not snapshot members: the watcher only warns on their failures.
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
- **Section clips alone.** For a named finished run whose song or talk clip needs a new cut but
  whose sermon does not, `sections:recut <processing-id> --dry-run` checks eligibility and
  `sections:recut <processing-id>` dispatches candidate preparation. It does not re-cut the
  sermon; use the full route when sermon media or composition also changes. Bind this exception
  to the round's reviewed membership and verify the resulting clip hash and hold state.
- **Edge evidence for the rest of the corpus is the round's first stage (decided 11-07).** The 11-07 final-code
  replay could not assess 430 sermon ends: their K16 answers and sentence checks were never asked under the
  current processing signature (version 16), though most of their word decodes were already banked. Only the 42 runs whose cuts the replay changed were prepared before
  the freeze (`storage/scratch/edge-step-20261107/`). After the freeze, before any recompose or cut, run
  `historic-import:prepare-output-edges {runs}` on the remaining members: the routes in
  `storage/scratch/review-inventory-20261107/routes.json` marked "edge step first", minus those 42. Recount
  first: evidence is keyed by window, model and signature, so it carries into the round only while those hold,
  and pre-freeze work that moves an edge (RA3, K20, K21, E27) needs that window decoded again. It decodes on
  host Whisper and asks the structure model (K16 and the sentence check). It never changes a run's status or
  cuts, so a run that fails it stays as it was. **Since RA3 v5/RA3e (11-07/08) it also decodes 8 song-tail
  windows per song on the aligned server (:2025) and separates stems at fade cuts: measured 11-09 on 91 runs,
  140–370 s a run and about 0.85 runs a minute across 4 processes (the one aligned server is the limit: the
  M1 Max GPU reads 98–100 % busy while CPU and containers idle, so a second server on this Mac only splits
  the same GPU), so roughly 8 hours for 400 runs. Plan the round's first stage for that.** Measured 11-07 on the 42: about 21 s a run, almost all of
  it the K16 and sentence-check model calls (the v16 decodes were mostly banked already). Runs are independent,
  so 3–4 processes over disjoint run lists are worthwhile; the host Whisper server decodes one window at a
  time, so more processes than that gain nothing on runs that need decodes. Pass run IDs as separate
  arguments: in zsh an unquoted `$RUNS` is one argument and the command prepares only the first run.
  Then rerun the replay and the inventory on the frozen
  revision, so the round starts from a complete end comparison. Stopped runs (945, 1019 and the rest of
  the inventory's stopped list) are routed through their own failure first.
- **Weekly runs cannot enter a round snapshot.** They retry through `UnifiedMediaProcessor::retry`
  (the admin Retry path).

### 4.3 Replay harnesses (read-only, rolled back)

- Banked edge evidence is bound to the media version the plan was made under, and its identity
  hashes the encoder. Pin both: version 8 runs with `libx264`, version 9 with `.env`'s VideoToolbox.
- A resolver missing its evidence returns `edge_word_timings_error` in the plan's metadata **without
  throwing**, leaving an empty audit. Count errors explicitly.
- **A database rollback is not enough on its own** (11-07): a deadlock ends InnoDB's transaction underneath
  Laravel, and later statements autocommit. Four rows on two runs leaked that way from parallel replay shards. Guard each
  replay with `SET SESSION autocommit = 0`, a savepoint checked at the end, read-only disk wrappers and a
  binlog check afterwards (`storage/scratch/final-replay-20261107/replay.php`).
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
  - a run superseded — once its own chain has settled. While a member's chain is still running,
    supersession is a warning marked "pending" (11-02): a re-run projects with no confirmed songs
    yet, so an older run of its service can win until the member's song matching reconciles again,
    and a stop would pause the queues that settle it (10-17, seven members, 5½ h);
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

**Parking a failed run (operator permission, 11-02).** A failed job on a member stops the watcher again on restart. Add
the run to `parked` and its stop's exact text to `acknowledged` in `watch-state.json` (back the file up first); the
watcher then reports it as a warning and every other check stays live. Build the text with the watcher's own format
(`job failed on <queue>: <displayName> — <first exception line>`, `storage/scratch/round-20261102-ackgen.php`) so
failures that land after the stop are covered too. Start the watcher, confirm its first look says "no stop", then
resume. Retried jobs go to the tail, so exhausted failures bunch at the end of a stage.

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
- Use normal speed for the final audible judgment about a word edge, fade or join. Record whether
  acceptance came from operator hearing, model/text review or mechanical evidence; text alone
  does not establish audible quality (register Q14).
- One question per clip.
- Rulings live in the page's database (`rulings`) and survive a republish. Export them with
  `ArtifactData list`.
- Every edge a round moves by ≥ 1 s goes on the page, with the cut plus 12 s of the recording around
  it.

## 6. Preflight for every dispatch

For the next round, first complete the [video plan §4.1a](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md#41a-before-the-next-round-regression-coverage-and-combined-verification-2026-10-29).
Its combined measurement supplies current membership and prerequisite routes; older round lists
are not a fresh census. The staged canary in §3 must check actual encoded transitions and internal
sermon joins for affected paths, as well as the planned timestamps and pause/resume behaviour.
For the representative canary's local acceptance journey and agent-first checking, follow the
[post-round audit §0](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md#sequence-and-confidence-report).

**G8 amended 2026-11-06:** full eligible-corpus comparison remains required. Before dispatch,
record each member's reuse/reprocess/unassessable disposition, affected stages and the evidence
reason. Reuse unchanged valid outputs and answers; repeated model work, rendering and listening
must address changed dependencies, missing/invalid evidence or declared independent sampling.
E11 still requires rendering a changed executed plan; E12 covers absent cut records; actual
whole-job retries still follow D10. This is not permission to skip a required prerequisite or
change frozen membership mid-pass. State the round's purpose and stopping condition using the
[audit's existing rule](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md#stopping-rule).

1. Freeze exact membership in the snapshot; settle transcript and structure dependencies before
   cutting; retain or exclude unresolved cases explicitly rather than widening the batch.
   Include every eligible sermon output in the K16 check, including runs whose last selected
   section is a prayer: the 10-28 sermon-section-only batch missed such runs (video plan §5).
2. Queues empty (queued, reserved, delayed); every worker started after the last code commit;
   staging and temp mounts readable and writable; free space for the batch on `/Volumes/Staging`
   and at least 10 GB on the internal disk (service artifacts live there); the previous batch
   backed up. Measure free space and the proposed backup's size on Sonnics immediately before
   the freeze. A prior binlog purge or Docker cleanup is not proof of current headroom.
3. Reuse the canary's evidence for paths it covered; check representative first outputs of any
   path it did not; stop expansion on a new regression or an unassessable result. Outputs stay
   quarantined pending the plan's §4.5.
4. Also check live:
   - the Staging drive's port and link speed: USB 3, about 158 MB/s; on USB 2 a cut's timing check
     read at 36 MB/s;
   - `SPEAKER_IDENTIFICATION_ENABLED=false`;
   - `git status` clean on the round commit;
   - free space on Staging, the internal disk (≥ 10 GB) and Sonnics;
   - the two causes of the 10-16 round's overnight stops (added 11-02; tool and first results in the video
     plan §4.1a step 5, "Stop-cause checks"): every member has a recorded `audio` service artifact that
     exists on its disk, and no member's service has another segmentation run with sections that is
     neither excluded nor retired (a re-run member projects with 0 confirmed songs, so such a run takes
     the service until song matching re-runs, and the watcher stops on "a run superseded").
5. **Back up before a round:**
   - a database dump and the private app storage, to `/Volumes/Sonnics/_pre-<round>-backup-<date>/`
     with SHA-256 sums (as on 2026-10-10 and 2026-10-16);
   - the MySQL binlogs since the last base dump, which are ROW format and expire after 30 days.
   - **Private storage goes to Sonnics as one tar, never a file-by-file copy** (11-02). Sonnics is exFAT
     with 512 KiB clusters and macOS adds a `._` file beside each copied file, so a file costs at least
     1 MiB there: an `rsync` of 13 GiB in 48,814 files needed over 50 GiB and was stopped with 8.8 GiB
     left. Stream it straight to the drive, nothing staged on the internal disk (10-21 filled it to
     184 MB): `COPYFILE_DISABLE=1 tar --no-mac-metadata --exclude '._*' -cf <dir>/private-app-storage.tar
     -C storage/app private` (4 min), then verify every member's SHA-256 read back from the archive
     against the source (`storage/scratch/freeze-checks-20261102/verify-tar.py`, 5 min). The
     per-batch backup row in §4.1 copies two small trees and is unaffected.

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
- back up the service artifacts and detection rulings to Sonnics **as one tar each**, verified with
  `verify-tar.py` (§6 step 5), not file by file: on 11-03 an incremental rsync would have copied 16,831 files costing
  ≈ 16 GiB on exFAT;
- check that the ffmpeg LaunchAgent is reached from a main worker after it is pointed back;
- run `historic-import:verify-output-generations` (seconds; add `--hash` before acceptance): every
  completed run's sermon must serve the main video the run recorded. After the 11-02 round ten
  section-failure repairs read as completed while quarantine held each video's previous generation;
- check output readability from a container started after the round (`docker run --rm` with the
  staging bind), not only from `laravel.test`: on 11-05 a two-day-old `laravel.test` read 21 of the
  round's clip directories as stale files while every fresh container read them correctly. Recreate it
  (`sail up -d --force-recreate --no-deps laravel.test`) if they differ.

## 9. Recovery

- **Internal disk low** (the watcher pauses under 10 GB):
  1. rsync the service artifacts to Sonnics, keeping the local copies, because records point at them;
  2. with the operator's approval, free old Docker images and the build cache;
  3. archive the stop file and resume.

  On 2026-10-14 this took free space from 10 GB to 28 GB. It will not again (measured 10-24): every
  image left is in use and the build cache reports almost nothing reclaimable. Run `docker system df`
  before counting on it.
- **The big lever is MySQL's binlogs** (ROW format, 30-day expiry), which live inside `Docker.raw`. Binlogs older
  than the newest full dump are redundant once that dump is shown to restore. On 2026-10-25 a restore check
  of the newest dump, then `PURGE BINARY LOGS TO` the file named in the dump's `CHANGE MASTER` coordinate,
  freed 23 GB and **`Docker.raw` shrank with it** (53 → 30 GB; the Mac 5.2 → 27 GiB free). The 10-24 note
  that it does not shrink was wrong. Steps: verify the dump's SHA-256, `gzip -t` and its `Dump completed`
  line; confirm it has no `USE`/`CREATE DATABASE`; restore it into a new side database with
  `sql_log_bin=0`; compare per-table counts with live; drop the side database (also `sql_log_bin=0`); purge.
  Keep the file holding the coordinate. Look at the Mac's own folders too (plan §5, "10-24").
- **The floor and a detection-only round.** The 10 GB floor protects cuts. A round that defers media writes
  little to the internal disk; the watcher's `--min-free-gb` can be lowered for it with the operator's
  agreement, recorded in the plan. Keep 10 GB for any round that cuts.
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
