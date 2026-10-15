# Follow-up round from a frozen clone, pausable — 2026-10-13 (rev 3)

Status: **rev 3: everything §3–§7 needs is built (§11), for Codex's review before setup.**
Nothing is set up: no clone, no round containers, the working checkout's workers and `.env`
are unchanged. Rev 2 added the following after Codex's review of rev 1 (findings in §10):

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

Codex review (13-10), applied: "sections unchanged, no new media" is now proved, not assumed.
An extraction is refused when a section was made or cut after it was dispatched, when a song
video was bound after it was dispatched, or when the composition inputs no longer match the
identity the round composed from (the stamp's deferred plan, else the run's composition). Step
rows the round started and left active, with no job behind them, are cancelled with the round
(929's interrupted `transcribe_full_service`). Older active rows are left as the snapshot
recorded them (1250's `analyzing` from 02:08 on 10-10). Dry run after the change: all 105 still
ready.

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

### 9.1 Clean-up pass: stale song clips on sections that are not songs (found 14-10)

**What.** Older structures left song clips attached to section rows that are no longer songs.
Sections are synced by order (see the "section insert deletes later media" memory), so when a
re-detection or recomposition re-types or re-spans a row, the row keeps its old
`extracted_video_path` and its `SongVideo` binding. The clip then describes a section that no
longer exists.

**How it was found.** At 06:39 UTC on 14-10 the watcher stopped the round on run 1002. Its
recomposition had inserted a section, so the later rows were re-created under new IDs:
- the 21-second "Introduction to the next song" (§1229 before, §211588 now) had carried song
  859's clip;
- the sermon (§1231 before, §211589 now) had carried song 159's clip.

The new rows have no media, so the watcher read this as lost media. Both files are still in
quarantine, along with `SongVideo` 166 and 167. The run's real song clips were promoted at
06:36. The stop was acknowledged in `watch-state.json`, and its stop file was archived as
`watch-stop-20261014T0639Z-run1002-stale-clips.json`.

**How many** (counted 14-10 at about 06:50 UTC, mid-round; recount after the round):

| Residue | Count | Round members |
|---|---|---|
| Non-song sections whose `extracted_video_path` is a song clip (`sermons/songs/…`) | 177 | 133 |
| `SongVideo` rows bound to a non-song section (all quarantined) | 184 | 140 |
| Historic `SongVideo` rows whose section is gone (quarantined) | 15 | — |

- By section type, the 177 are: prayer 44, bible reading 40, other 39, sermon 23, short talk
  15, welcome 9, notices 7.
- All 184 files are in quarantine.
- In 88 of the 184 cases, the same song also has a clip on a real song section in that run.
  The other 96 are the only clip of that song in that run.
- Out of scope: 22 published `SongVideo` rows with no section. These come from routine (not
  historic) processing, where having no section is normal.

**Why it matters.** Nothing here is public, but:
- the stale rows make the watcher stop the round falsely whenever IDs move;
- they misdescribe the service, for example a prayer section claiming song 859's clip;
- a later release step that reads `SongVideo` by section could act on the wrong clip.

**The pass, in order** (repair through the pipeline, not by hand):
1. **Fix the mechanism first.** When a sync re-types a row away from `song`, or moves its span,
   clear its `extracted_video_path` and detach its `SongVideo` in the same transaction. Test it
   with a failing test reproducing 1002: insert a section and check the later rows. Otherwise
   every future re-detection recreates the residue.
2. **Then a command for the existing rows,** dry run by default. It finds the three groups above
   and, for each:
   - un-points the section's path;
   - re-binds the `SongVideo` to the run's song section for the same song when there is exactly
     one, or otherwise retires it, keeping its file;
   - writes a report per run.
3. **Quarantine is single-copy:** retire, never delete files. Deleting a stale clip whose song has
   no other clip is a separate decision for the operator, made from the report.
4. **Recount** (the table above should read zero), then re-run the watcher's diff on the round's
   runs to confirm no new "lost media" lines.

**Open for the operator:** whether the 96 clips that are the only copy of their song in their
run should be listened to before they are retired. They are probably mis-cut, since they were
cut for a section that turned out not to be that song.

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

## 11. Built 13-10 (rev 3), on local master

| Piece | Where | Commit |
|---|---|---|
| Superseded runs are left out of service reconciliation (943's failure; the operator asked that the two-recordings case be impossible: it was a superseded run being re-detected) | `ChurchServiceReconciliationDispatcher`, `ReconcileServiceSections` | e15426dc2 |
| PHPUnit on Redis 6/7 (`phpunit.xml`, forced); local Dusk on 8/9 (`.env.dusk.local`, untracked). Verified: the flushing test leaves 0/1 alone | `phpunit.xml` | 0a67d5811 |
| Guard admits a run a round parked for its held sermon (§5 R4) | `CorpusRerunGuard` | 89f2b25d8 |
| `rerun-recompose`, `-redetect`, `-extract`, `-retranscribe` refuse no run IDs without `--all` | `HistoricRerunSnapshot::select()` | 4b2260d41 |
| `historic-import:rerun-withdraw` (§4), with `QueuedRunJobs` (waiting, delayed and reserved jobs naming a run). Dry run on the pass's runs: all 105 ready (66 against the main snapshot, 38 against Tier B, 1209 after its stale flag was ignored) | `WithdrawCorpusRerunRound` | 5571521c8, 399691d54 |
| Compose override and round tools: `round.sh` (status, pause until idle, resume refused while stopped, stop/start, watch, artisan), `queue-state.php`, autotick (explicit list less parked) | `docker-compose.round.yml`, `scripts/historic-round/` | 3ef104e44 |
| `queue.worker` and `queue.worker-video` take `QUEUE_WORKERS_DEFAULT` / `QUEUE_WORKERS_VIDEO` | `docker-compose.yml` | 3ef104e44 |

The decision page's rulings, applied:

- **Rules (code, tests, replay):**
  - pattern A: a sermon beside a song ends where the singing starts (`CueSafeExtractionPlan::spokenThrough`, 6b8dc6165). Replay of the 354 ruled outputs: no edge moves;
  - pattern B: a sermon the recording opens with (first section, ≤ 5 s in);
  - pattern D: a reading cut with its sermon. Low confidence holds neither (6333fbafd);
  - pattern C: a reading the sermon stops for and resumes after is its own; prayers, and parts preaching different passages, stay questions (`SilenceSnapService`, 40d5a4736). Recompose replays the merge;
  - readings: every reading directly before the sermon, except a carol service's lessons (December, four or more readings), plus passage matches further back (7d0f7e671). Across the corpus, 11 runs gain readings and none loses one.
- **Run answers through `AnswerServiceStructureEnsembleQuestion`:**
  - 944: the Psalm 77 exposition is a talk after its reading. The run's sermon is a separate item, so a second sermon was refused;
  - 1066 and 997 right as they stand;
  - 1344 accepted (sections synced);
  - 1281 "I bow before the God" from the start. The second song is sung from 1121.5 s, which matches the existing split;
  - 1363 split into the reader's greeting (other, 888.9–893.8 s, out of order in the source upload) and the second speaker's talk.

  The answers on runs with media are banked and reach their sections when the round recomposes them.
- **Composition reviews:** 1014 (the prayer stays out) and 1194 (Matthew 2:13–23 in). A review is keyed to its sections' identity, so re-run `storage/scratch/decisions-20261013/apply-compositions.php` if a recompose moves them. 1026 is now settled by the readings rule.
- **Parked:** 930. Its drafts give the pre-service music and the welcome one OoS item, so no answer validates. The Luke correction also overlaps the stretch the drafts call a carol.
- **Talks after a reading (answered 13-10 on https://claude.ai/artifact/2VFMAuoe1YLx4iC5LZRAyq):** built as `TalkReadingPairing`. A short talk directly after a reading (gap ≤ 15 s) is cut with it when answered so; the answer is kept on the talk (`reading_pairing`), holds while that reading is still directly before it, survives a re-sync that moves the talk's edges, and enters the talk's media and approval signatures, so a new answer re-cuts the candidate and asks for approval again. Recorded with `service:answer-talk-reading`:
  - with the reading: 962 §841, 1009 §1273, 1052 §1602, 1263 §3299, 1280 §4782, 1286 §3582, 1290 §3631, 1363 §4532;
  - alone: 975, 1146, 1198, 1246, 1315, 1321;
  - **944 not yet:** §190427 is `other` until the round's recompose applies its correction. Between its `rerun-recompose` and `rerun-extract`, run `service:answer-talk-reading --include=<944's talk> --evidence=… --execute`;
  - **1286 joins R1** as a repair: a finished run outside the round, whose talk is now cut with Isaiah 35. None of the eight is approved or published, and no paired reading is also in its sermon's cut.
  - Codex review (13-10), applied: a held reading holds the talk cut with it (candidate preparation sends the talk to review, approval and publication refuse); the answer follows its talk through a re-sync that changes the order as well as the edges; the reading's opening is the talk clip's start for the sentence-boundary check.
- **For the listening round:** 1271's sermon end runs through a line the classifier hears as neither speech nor music ("sovereign"), which may be quiet singing.

## 12. Setup log (13-10)

- Pushed d18b53ab7. Main's seven workers removed; main `.env` on Redis 4/5, every worker replica 0 (old file in the session scratchpad as `env.before-round`).
- Clone `../crockenhill-round` at d18b53ab7, vendor copied, same `.env` (Redis 0/1, VideoToolbox). Its code revision differs from main's only by ten untracked `.DS_Store` files in main; every tracked file is identical, and the round records the clone's own revision.
- Round project up: eight containers (ffmpeg ×2), Redis 0/1, encoder `h264_videotoolbox`, host ffmpeg reachable.
- Withdrawn: 67 on the main snapshot, 38 on Tier B, none refused; 929's interrupted `transcribe_full_service` cancelled.
- S1 `followup-round-20261013`: 301 runs (the 300 plus 1286), Tier A routes from `listening-20260924/routing-bound.json`. Watcher running; parked 930, 945, 1220, 1223, 1314. 1202 not parked: §6 pattern B covers it.
- Stage (a), 1263: recompose clean (talk 3299 kept its Psalm 19 answer). Its first encode hung in `open()` on the Staging drive: the LaunchAgent's Python had never been granted removable-volume access (every earlier host run was on project files). The LaunchAgent is unloaded; the same server runs from the operator's session until the permission is granted, then the LaunchAgent comes back (round paused for the swap).
- Stage (d) batch (938 942 947 950 951 958 960 963 969 974 986 987): 10 recomposed; 942 and 974 failed validation on `duplicate_oos_item`, from 3e03c0b0a's kept voter links. Replaying every R1 run's recompose: 7 failed on d18b53ab7 (942, 974, 1002, 1030, 1096, 1217, 1240), all pass on the fix 7e5168cb4 and nothing else changed (1143 and 1262 have no banked draws). Clone fast-forwarded to 7e5168cb4 (not yet pushed: no SSH key in the session); workers restarted on the revision change. Nothing needed withdrawing. S1b `followup-round-20261013b` taken: same 301 members and routing, S1 kept; watcher moved to S1b with the same five parked. Round paused.
- Batch D on S1b: 10 recomposed; 960 and 987 refused at extraction (open ensemble disputes; 19 R1 runs carry some, 1281 and 1363 among them with answers banked); 942 and 974 have no route back (failed validation with no questions; the guard keeps them out). 7 cut in 86 min (12.3 min/run, 0 failed jobs); 969 failed on a sermon content hold (new, not on the R4 list). ExtractSermon 6.2 and PrepareSectionPublicationCandidates 4.2 min/run, each scanning the whole 3-12 GB source for the timing check; Staging had been on USB 2.0 (36 MB/s), hardware encoders busy < 28%.
- 075006548: the timing check reads the source in windows. Replay on batch D: 8 sermons identical (old 14-300 s, new 3-21 s), 29 section clips pass. Operator swapped the cables: Staging and Sonnics both USB 3, Staging 158 MB/s in the Mac and in the workers. Clone fast-forwarded, S1c `followup-round-20261013c`, watcher on S1c with the same five parked. Batch E (928 933 941 948 949 953 956 962 964 966) recomposed and dispatched for extraction at 19:13 UTC.

### 14-10

- R3 on its own snapshot S2 `followup-round-20261014-r3` (15 runs, 1035 excluded), own watcher. All 15 detected; 8 dispatched for cutting (1265 and 1353 promoted; 929, 1168, 1208, 1209, 1359 stopped on content holds; 1366 refused: unresolved sermon sections and uncovered speech); 7 refused for open disputes. 0 failed jobs.
- Split sermons (operator: join them). Corpus scan of 436 runs: 1073 and 1268 publish only part of their sermon (the rest is `other`); 940, 1148, 1224, 1335 and 1363 only had a minority draw split. Join rule not yet built. 954's "Report from Men's Event" is a talk (operator); fix for a detection-only item type not yet built.
- Open questions: 45, of which 25 had answers from 10-11. 9 had never applied (refused: exact-time matching after the edge rules moved an edge, 1004/1281/1307; a chosen unwritten version left a sliver, 1042; 1154 a real new question, what is sung 17:24–20:26); 16 clashed between answers.
- Code on round branch `round-ruling-overlaps` from 7a69aac5d (not on master's three unpushed commits; cherry-picked to master): 4f0a20c12 settles overlaps the operator's rules decide (an item answered as its own inside a sermon; a reading inside a longer reading). Clone fast-forwarded; snapshot `followup-round-20261014-answers` (987 1027 1096 1269 1285 1305 1343 1363) recomposed, 6 cut.
- Rulings page https://claude.ai/artifact/SWkPADUDoSWwDy1zSX3R7b (21 questions, 11 runs): 11 applied; snapshot `followup-round-20261014-answers2` (960 971 977 1195 1272 1350) recomposed. Also applied 1329-q0 (either), 1342-q1 (O Lord, My Rock and My Redeemer), 1363-q0 (the 3:19 "join" is a loop: other 199–209 s, talk from 209 s).
- Operator rulings: 940 is a rehearsal, exclude it (no reason fitted: new `rehearsal`); a seam two answers share stays in both clips (1096: the validator already allowed it; F06's clash rule now only for gross overlap); a looped pre-recorded segment is a flag to listen to, never a trim (https://claude.ai/artifact/HRHvUj2PDqETpddeaC5nni: 1342, 1341, 1363 ×2 all loops); a shared `other` OoS item (slide deck, "Reading" placeholder) is a flag, not `duplicate_oos_item` (942, 974).
- Built, awaiting the replay check before commit: seam rule; closest-fit question matching (`ServiceStructureEnsembleRulingApplier::sectionFor`); `LoopedSegmentChecks` (corpus: 1342, 1341, 1299 asked; 1299 is Romans 5's own repeat); `rehearsal` exclusion reason; shared-`other` flag. Replay: 155 answered runs old/new; loop check alone on all 436; validator change bounded to 942, 974, 1299 (the only invalid drafts linking an `other` item twice).
- Rehearsal search (duplicated speech, rehearsal talk, draft labels): no new rehearsals; 1345 "Technical difficulties" worth a listen.
- 1126 is superseded by failed weekly run 894 (one confirmed song, full-service source) under the confirmed-songs-first rule; `services:supersede-duplicate-runs --service=607` dry run changes nothing. Proposed: retry 894. 1143 never had ensemble draws (two 20-minute speech blocks). 1262 is a held sermon.
- Isolation re-checked: PHPUnit on Redis 6/7 and Dusk on 8/9 (`testing` DB); the round shares MySQL `crockenhill` with main. Only restoring main's workers (§9) waits for the round to end.
- 894 retried (operator: B). Its source was restored byte-identical from the Sonnics scratch archive, at first to main's storage, which the round does not read, so the run failed again on "file not found". The source was moved to the Staging temp folder and the run retried.
- The watchers paused every round queue at 20:58Z on internal disk below 10 GB. Service artifacts rsynced to `/Volumes/Sonnics/_service-artifacts-backup/`: 12,730 files, checksum-identical. Local artifacts are kept, since records point at them. Freed instead, operator-approved: old Playwright images v1.60.0 and v1.55.0, `crockenhill:php85-opcache-fix`, and the Docker build cache. Free space went from 10 to 28 GB. Stop files archived as `watch-stop-20261014T2058Z-internal-disk-low.json`, queues resumed, and the r3, answers and answers2 watchers restarted (no stop).
- Replay of the 155 answered runs, old then new: 6 differ and all are explained. 1096: seam. 1299: loop question. 1036: the remove answer now lands; the old exact-time match missed it after a cue edge moved. 1343: the talk keeps its chosen end across the song seam. 1304: the composed flag is kept, by design. 987: a stale `structure_missing_preached_reading` flag is kept from the composed sermon; it is demoted because the passage is known, and is to be recomputed after answers in this batch.
- Batch committed: branch `round-ruling-overlaps` 5ddf7c4f4 (12b16f5e1 cherry-picked), a7350fbc2, e911741ad, cf0d49430, ae312aa82; master 9a2ef0b2a..ecdf0f3c3; clone fast-forwarded to ae312aa82. In a dry recompose validation, 942 and 974 pass on both old and new code. The shared-item rule adds its flag (974: slide deck on `other` and sermon; 1299: three Remembrance `other` sections) and lets the previously failing draws vote. That changes 1299 slightly: a 20 s song intro dropped, a 27 s reflection added, sermon unchanged.
- 894 retry stopped at sermon creation: sermon 1051 is 1126's and in historic custody, and the guard refused replacement from the weekly lane. Compared: 1126 is the local 1080p capture at 2.66 Mbps (35 min, sermon only); 894 is the 720p livestream at 0.49 Mbps (68 min, full service). 1126 ran on the full-pass code on 10-10, so a re-import would gain nothing. Operator: keep 1126. `exclude-run` now sets a weekly run aside as `duplicate_recording` by processing ID alone (fa5fb75d1 / master a30def747). 894 excluded; 607 reconciled: 1126 wins and 894 is superseded. 607 has no song clip for that Sunday.
- Operator (2026-10-15): use the longer recording for the service, but never let its sermon replace a better recording's. Built:
  - `BetterSermonRecording` sets the rule: a higher resolution, then a higher bitrate; the held sermon must be ≥ 95% of this run's sermon length; a run that already made its own sermon never adopts.
  - `ExtractSermon` and `SubmitToProcessing` adopt that sermon (`sermon_adopted` metadata, no sermon row, no cut or video) and finish through song candidates, promotion and cleanup.
  - A superseded run whose sermon a standing run adopted stays exportable (readiness) and re-cuttable (`ExtractForCorpusRerun`). The adopting run is not "missing" its sermon.
  - `historic-import:rescind-exclusion` takes back an operator exclusion and keeps it on record. It refuses silent-source exclusions and those that removed a service.
- Bounded check, read-only, five services. 546: 893 adopts 1189's 1080p sermon 1108. 607: 894 adopts 1126's 1051. 790: 889 and 890 adopt 1378's 869. 583 unchanged: 1080p run 943 has 794 s of a 1,232 s sermon. 795 unchanged: 1080p run 1377 never cut a sermon, and 909's 720p sermon 857 stands.
- Adoption committed: branch 0f231f402, ae08dd13a, 07cb3fa43; master 657d97acd, f300269f8, d1d5068ae; clone at 07cb3fa43. 607 applied:
  - 894's exclusion rescinded and the run retried; it adopted 1126's sermon 1051 at sermon creation.
  - The first retry failed at song candidates: last night's failure cleanup had deleted the working copy. The source was restored byte-identical (251,732,985 bytes) and the run retried.
  - The resumed main chain reached the completion notice, which failed for want of a sermon (now skipped, d1d5068ae). Its step label stays `notification_failed`; its status is completed.
  - 607: 894 wins (4 confirmed songs, 3 clips published, 1 awaiting approval); 1126 is superseded but holds sermon 1051 and stays exportable.
- Open, not part of this change: 13 of 15 sampled completed historic runs fail export readiness on "unclassified identity field: church_service_item_id"; 1126's sermon has no thumbnail.
- Next for adoption: 546 (retry 893) and 790 (retry 890; 889 completed without a sermon). Both were stranded in July and need their sources restored. Awaiting the operator.

**2026-10-15, 546 settled, and weekly runs' output held (operator: "720p with the reading", "Go ahead", "Build it").**
- Weekly master (branch f5f2476ef, master 0a50de4c3): a historic duplicate may be set aside for a weekly run. 1189 was excluded (`duplicate_recording`, `--duplicates=893`) and retired. Its sermon 1108 was withdrawn and its three assets (588 MiB) moved to `superseded/`.
- 893's source was restored byte-identical (232,834,621 bytes) and the run retried. It cut a new sermon 1310, "Persevering in faith": 1,944 s including the reading (plan segments 1309.8–1423.21 and 1969.11–3800). 546: 893 wins; 1189 is excluded and superseded.
- Found after: the weekly runs 890, 893 and 894 created their output **published** with `asset_disk` null. That was sermon 1310 and song videos 1233–1242, with every file on `historic_staging`. Custody was decided only by `historic_import_operation_id`.
  - Thumbnail generation for 1310 resolved the empty disk to `THUMBNAIL_STORAGE_DISK`, which is `do_spaces`. It wrote five public-read plain candidates (about 51 KB) to the production bucket: `sermons/thumbnails/sermon_1310_2026-10-15_candidate-{1..5}_plain.webp`, at 08:26 UTC. A scan of `sermons/` for objects modified since 2026-10-12 found only those five. **Not deleted: deleting from the bucket is the operator's call.**
- Fix (branch adc56d620, master 7799bfb78; clone at adc56d620): custody follows the disk.
  - `MediaAssetPath::holdingDisk()`: when the sermon disk is historic staging or quarantine, new sermons and song clips are quarantined and name that disk, whatever the lane. Production publishes, so it is unchanged.
  - `historic-import:hold-run-output` applies the rule to existing rows. It refuses unless every referenced file is on the holding disk.
  - Applied to 890, 893 and 894: 11 rows held. None remain published without a disk since 2026-10-12.
- Open: 1310 has no thumbnail; a regeneration would now write to `historic_staging`. 790 / 890 is waiting on ensemble answers.

**2026-10-15 afternoon: questions, group A, recompose and cut (operator: "Build the question page", "Do 3-6", "Leave the 5 images").**
- The five stray thumbnails in the bucket are left in place (operator).
- Question page https://claude.ai/artifact/FyeQQAfXu3pvqRZhty6ALZ (`rulings`), export `storage/scratch/round-questions-20261015`.
  - 1154's three questions were removed before any answer was saved: group A already answered them on 11 October.
  - 1341 and 1342 were left off the page: they were already answered as loops on HRHvUj2.
  - Answers: 1299-q0 keep (applied); 890-q0 none (refused, see below).
- 940: the rehearsal exclusion dry run was clean; it removes service 653, described only by 940's own livestream record. The apply was blocked by the permission check, so it is the operator's to run. Exclusion leaves sermon 879; retiring it is the operator's call.
- Group A (`groupA-round.json`, 8 answers) applied to 1004, 1042, 1154, 1281 and 1307. The 1341 and 1342 loop rulings were applied as alt0 through the with-loops export (`loops-1341-1342.json`).
- Snapshot `followup-round-20261015-settled-r` (12 runs at adc56d620; `-settled` was written as a file, and Docker's mount cached it, so it was renamed) recomposed 10.
  - 942 and 974 refused: they failed validation on 10-13 and no answer fixed them. The shared-item rule now passes them, but the guard has no case for that. A guard change is for the operator to decide.
- answers2's six refused to cut on 4f0a20c12 code, so they were re-snapshotted as `followup-round-20261015-answers2b` and recomposed on adc56d620.
- Cut 16, 12:39–13:41Z (3.9 min/run, no watcher stops):
  - 11 completed, every sermon re-cut, quarantined, on `historic_quarantine`: 1004, 1042, 1154, 1281, 1307, 1329, 1341, 1342, 960, 977, 1272.
  - Content holds as designed (held-sermon re-cuts): 971 (§1093), 1195 (§2450), 1350 (§4423).
  - Composition review:
    - 1096 is `sermon_reading_membership_unresolved`: two readings, 17:23 and 30:23; the September plan joined the second. It needs an operator answer on the service page.
    - 1363's composition is now clean (recomputed: no risks), so a fresh snapshot is needed to cut it. One cut per commit per snapshot.
  - Reading the composition with `compose()` writes it back (`sermon_composition` and the section's composition flag). It was done for 1096 and 1363; it is the same recompute the pipeline runs.
- Group B check: 987, 1027, 1269, 1285 and 1305 were re-cut on 10-14 18:24–18:34Z, quarantined, videos present, readings joined. 1343 is parked on a content hold.
- 1299: snapshot `followup-round-20261015-q1299`, recomposed with its answer.
- 890's answer was refused: the run's own structure write at 07:45 linked OoS item 5280 "Ezekiel", changing its type from `other` to `short_talk` 2 min after the bundle input (07:43). `oosItemsMatch` compares type, so the evidence reads as changed. The written structure already omits the 16 s reading. It is the only held run in this state. Options for the operator: re-detect, or a gate change after the cuts.
- 1299's cut was refused at 13:43:59, seconds after dispatch: `EnsembleReviewGate` still read slot 0's stored `invalid`, but answering had replayed it valid under the shared-item rule, so the composition was not degraded and could never be marked reviewed. 1299 was the only one of 439 ensemble runs in that state.
- Operator 10-15 (accepted recommendations): run 940's exclusion and retire 879, after a duplicate check; the guard re-checks rather than gaining an exception; re-detect 890; 1096 after its sermon text is read.
- Guard change `6e826d9d5` (branch `cca6636b7`, clone fast-forwarded): the recompose guard admits a round's failed validation when the banked draws, with their answers, compose valid under the running rules; the gate asks no review of a draw recorded invalid when the composition counted it (not degraded). Replay on the real runs: 942, 974 and 1299 pass with no questions.
- **Database wiped and recovered, 15:58–16:25Z.** I ran two test files inside the clone's container to check the cherry-pick. The clone has no `.env.testing` and shares `crockenhill-mysql-1`, so RefreshDatabase dropped every table in `crockenhill` (both stacks). The watchers stopped the pass and paused 10 queues, as designed. Recovered with nothing lost:
  - Base: `/Volumes/Sonnics/_pre-full-pass-backup-20261010/crockenhill-20261010.sql.gz` (finished 17:12:06; latest row 14:20:31; no `crockenhill` writes 14:20:31–17:33, so replay starts at `binlog.000112` 715442329).
  - Binlogs 112–120 copied to `/Volumes/Sonnics/_db-binlogs-20261015/` and replayed into `crockenhill_recovered` up to `binlog.000120` 48060661, the event before the first `use crockenhill; DROP TABLE` (`mysqlbinlog --rewrite-db` then `--database` on the rewritten name, in a throwaway `mysql:8.0` container).
  - Verified before swapping: last write 13:43:59 (1299's refusal); 1362 runs, 1270 sermons; 439 ensemble runs; today's run states, 1310 and its 10 clips held; latest migration present.
  - Swapped by one `RENAME TABLE`; the wiped tables are kept in `crockenhill_wiped`. Workers restarted and the 10 queues resumed.
  - Never run tests in the clone (memory `never_run_tests_in_the_round_clone`).
- 879 (run 940, 2025-05-18 evening, preacher recorded as the operator) shares its title with 1015 (run 1088, 25 May morning, the real service, Visiting Speaker) and 1016 (run 1089, Saturday 24 May evening, no service; not on the duplicate-pairs list).
- 1096: sermon 1023 opens by reading Luke 24:1–12 and says "I'm not following a particular passage". Recommendation: "The women at the empty tomb" (30:23), as in September.
- 890 has no route to re-detection: its hold came from `ExtractSermon`, where the ordinary retry resumes, and re-detection exists only for recovered transcripts and corpus re-runs; a weekly run cannot enter a round snapshot.
- Snapshot `followup-round-20261015-guard` (942, 974, 1299, 1363) on cca6636b7: 4 recomposed 16:28–16:38, cut dispatched 16:47.
- The watchers' 16:01–16:05 stops had paused 10 queues; resumed after recovery. Cut 16:47–16:52Z, none completed:
  - 1299 passed the ensemble gate (the fix holds) and parked on its content hold (§214776), as designed: `sermons:re-extract 99c83934-abec-48be-a9dc-7be8735f89a4 --held-section=214776`.
  - 942: composition risk `sermon_prayer_membership_unresolved`, an operator answer on the service page.
  - 1363: no composition risk; its sermon §215988 carries `structure_low_confidence`, which blocks auto-extraction for a sermon that does not open the recording (with `structure_missing_preached_reading`, which does not). My 13:41 note that 1363 "should now go through" read the composition only; the section flags were the hold. Operator review of the section.
  - 974: no composition risk; its sermon §996 carries `structure_oos_shared_item`, the 10-14 flag, which is not in `NON_DISQUALIFYING_REVIEW_FLAGS`, so the flag added to stop a failure now holds the cut. Of 5 flagged sections (974 §994 §996, 1299 §4743 §4751 §4753), only 974's sermon is blocked. Whether the flag should block extraction is the operator's call.
  - Reading the plans with `resolve()` for 974 and 1363 rewrote `sermon_composition`, the pipeline's own recompute.
- Operator 10-15: the shared-item flag keeps blocking the cut (974 needs its sermon section reviewed); the staleness check should ignore a type the run itself projected.
- `8681a89c7` (branch `73db97568`, clone fast-forwarded): `EnsembleReviewGate` leaves out of the OoS comparison a section type that this run's livestream projection wrote onto the item. Of 439 banked runs, only 890's verdict changes (stale → current); none is stale now. 890-q0 applied ("none", accept), 0 questions open, gate clear; 890 waits for the operator's ordinary retry, which resumes at extraction.
- 879 and 1016 are the same sermon (both open on Esther Rantzen and the assisted suicide debate; Luke 20:19/20–26): 879 is 25 min (run 940, dated 18 May evening, preacher recorded as the operator), 1016 is 37 min (run 1089, dated Saturday 24 May evening, no service). 1015 (run 1088, 25 May morning, Visiting Speaker) is a different sermon on the same passage.
- Correction: 1015 (run 1088, 25 May morning) is the same sermon as 879 and 1016. Its transcript opens with the live introduction and reading, and the rehearsed sermon follows ("Last Thursday Esther Ranson…", Tiberius, "long live the king"). Operator 10-15: 879 and 1016 are both rehearsals for 1015.
  - 1089 was already excluded on 2025-09-15 as `rehearsal_duplicate` of 1088 (its service, 1021, removed then); a dry run confirms "excluded (unchanged)".
  - 940 cannot be `rehearsal_duplicate`: the kept run must belong to the same operation, and 1088 is in another. It stays `rehearsal`; the operator runs its exclusion.
  - 1015 is recorded as "Visiting Speaker" while 879 names the operator: a preacher question for the operator.
  - An exclusion leaves the run's sermon quarantined. 4 such sermons today (977/run 1043 and 1016/run 1089 `rehearsal_duplicate`; 985/run 1051 and 1025/run 1098 `private_occasion`), 5 with 879. Release publishes only records a signed authorisation names, so none is published unless named; nothing withdraws them either. The release list must leave them out.
- 940 excluded 17:31Z (`rehearsal`), service 653 removed. 890 retried through `UnifiedMediaProcessor::retry` (the admin Retry path; plan: livestream chain from `ExtractSermon`, nothing reset): completed 17:32, extraction skipped because 1378's sermon 869 holds the service, as the 10-14 duplicate check found (790: 889 and 890 adopt 1378's 869).
- Sermon question page https://claude.ai/artifact/XXq5Xn5KdcZcTGFwEsb5XP (`storage/scratch/sermon-questions-20261015/`, `answers-map.json` maps each choice to section IDs): 942 closing prayer, 1096 reading (John 20 at 17:23 or Luke 24:1–12 at 30:23), 1363, 974. Answers apply through `SaveServiceSection`, as the admin save does.
- 1363's marked sermon (15:32–50:58) holds a prayer (15:32–17:56), a link, and the main reading Philippians 2:5–11 by video (18:24–19:36); the preaching starts at 19:38. A "starts at 19:38" answer is a structure correction `SaveServiceSection` cannot make; its route is to be found after the answer.
- Sermon answers (operator 17:40–17:44Z): 942 "reflection and prayer 34:41–36:00 both belong"; 1096 "neither reading"; 1363 "sermon starts 19:38 with the Philippians reading before it"; 974 "the 26:32 introduction belongs with the sermon".
  - 1096 applied through `SaveServiceSection` (composition [1820], review cleared), snapshot `followup-round-20261015-s1096`, recomposed (review kept), cut 17:47–17:52Z: sermon 1023 re-cut, 1602 s, quarantined.
  - 942 and 974 refused by `reviewComposition`: a composition admits only sermon, reading and prayer sections or a marked continuation, and §662 and §994 are `other`. A composition also needs exactly one sermon-typed section. 1363's answer needs the sermon section split. All three need structural edits (split, merge, retype) before the composition can be recorded; proposed to the operator.
- Structural edits (operator approved), through the admin actions as user 1, then `ProcessingRunOrchestrator::reExtract` (resumes at `TranscribeOutputEdges`, staging context recorded; `sermons:re-extract` refuses first because it resolves the plan before the new edges are decoded). Not through the round: a recompose replays draws and answers and re-syncs sections, which would undo these edits, so 942, 974 and 1363 must not be recomposed again.
  - 942: merged §662 + §173241 + §173242 into prayer §662 "Reflection and prayer of response" (34:41.8–36:00.8); composition [660, 661, 662]; cut 17:59–18:03Z, sermon 880 1228 s, quarantined.
  - 974: §994 retyped reading and merged into §995 "Jesus and the Greeks" (26:32–31:40); composition [995, 996]; the sermon's confirmation cleared `structure_oos_shared_item`; cut 18:04–18:14Z, sermon 910 2307 s, quarantined.
  - 1363: §215988 split at 1104.0 and 1178.3: §215988 prayer "Prayer" (15:32–18:24), §248826 reading "Philippians 2:5–11" (18:24–19:38), §248827 sermon (19:38–50:58); composition [248826, 248827]; cut 18:04–18:14Z, sermon 1285 1956 s, quarantined. OoS item 18035 ("The promise of exaltation", sermon) still links §215988, now the prayer, and §248827 has no item: no admin action re-links a section.
- Held-sermon span page https://claude.ai/artifact/9Xr6ADmqWs2qA19k4yD7mU (`storage/scratch/held-questions-20261015/`): 971, 1195, 1350, 1343, 1299, three clips each (reading start, join, sermon end). `sermons:re-extract --held-section` dry runs all ready; each now joins its reading (planned starts 1589.1, 1217.3, 1376.3, 1781.6, 2024.2 s against recorded 2169.4, 1576.7, 1530.9, 2035.2, 2108.6 s).
- Held-sermon answers (operator 18:37–18:39Z): all five "yes". Re-cut one at a time with `sermons:re-extract --held-section`, holds kept, all quarantined: 971 → sermon 918 (2080 s), 1195 → 1114 (936 s), 1350 → 1273 (1963 s), 1343 → 1267 (1973 s), 1299 → 1305 (2114 s); 18:39–19:09Z.
- Export defect: the 13-of-15 sample understated it; 0 of 368 completed historic runs built an inventory. The guard fails closed on unclassified id/path metadata, and the round had written about twenty such fields. Fixed in `421b7013e` (branch `81079ee52`, clone fast-forwarded): same-run section references export as section keys and the persister maps them back; song references by canonical key; candidate UUIDs travel; null identities pass; boundary inputs may name relative `service-transcripts/` files; superseded signatures, content-hold item ids, `service_structure_retry` and `structure_redetection_snapshot` dropped; excluded runs refused first. Result: 363 of 363 non-excluded completed runs build an inventory. Found on the way: a persister read of a null metadata cast made a later save write `[]` (caught by the normal-output canary).
- Full readiness after the fix: 12 of 455 historic runs ready. Remaining, by count: 520 sections with an unsettled publication decision (song acceptance stage 2); 406 main sermons without `thumbnail_file_path`; 81 failed runs; 17 with active or failed steps; 8 with no main sermon; 7 superseded; 12 excluded; 4 nested publication jobs failed or running (1127, 1352, 1220, 1223); 937 never dispatched; 3 unsettled Scripture enrichments (973, 1022, 1171). Order-of-service items without `canonical_identity` (5,333 of 5,892) are not a readiness reason yet: sections export with a null item identity.
- Round ended (§9), 19:35–19:45Z. Checked first: all 10 queues empty (ready, delayed, reserved), none paused; both autoticks idle ("0 ready, 19 refused"); the six `watch-stop.json` files are the 16:01–16:05 recovery stops. `failed_jobs` since 10-13 reviewed, all superseded, none retried: 15 `PromoteHistoricAssets` "differs in byte size" on 10-13 21:35 (946, 981, 1030, 1064, 1075, 1216, 1218, 1226, 1230, 1235, 1251, 1260, 1267, 1271, 1347; all re-ran 10-14, 9 completed, 6 parked on sermon content holds); 943 and 1189 `DetectServiceStructure` (both completed since); 513–516 the 893/894 weekly runs. Then: autoticks and the guard and s1096 watchers stopped; the nine `crockenhill-round` containers removed; main's `.env` restored from `env.before-round` (Redis 0/1, pre-round replicas), `sail up -d` (7 workers) and `laravel.test` restarted, both reading Redis 0/1; the ffmpeg LaunchAgent pointed back at main's `scripts/ffmpeg_host_server.py` (byte-identical to the clone's), reached from a main worker. The clone `../crockenhill-round` is kept until the round's listening is accepted.
- Re-cut listening page https://claude.ai/artifact/P3cqjxMTFFzLyYKuk3324k (`storage/scratch/recut-listening-20261015/`): per sermon, the opening and ending as published (MP3) beside the service audio, and each join. Operator 20:09–20:12Z: 8 right (880, 910, 1285, 918, 1114, 1273, 1267, 1305); 1023 "can't tell: there's no speech in the first two clips".
- 1023 opens on silence. The service audio is below −45 dB from 35:23 to about 35:49 (the children leaving), apart from a 1.6 s noise at 35:43. Whisper stretched the first cue, "Well, I'm not following a particular passage in the Bible.", over the silence (35:33.5–35:53.4, 20 s for ten words), and the re-decoded word timing put "Well," at 35:32.6. The edge rule (`word_pause`) did what it was given; the cut starts about 16 s before the speech.
- Corpus scan of the 442 pipeline-cut sermon MP3s (silence < −45 dB, ≥ 1 s; `edge-silence.tsv`, `trail-silence.tsv`): leading silence ≥ 3 s in 25, ≥ 5 s in 16, ≥ 8 s in 9 (1234 35.6 s, 894 28.1, 1218 19.4, 1056 16.6, 1028 14.5, 1061 13.8, 1253 13.3, 1023 10.8, 977 9.9); trailing ≥ 3 s in 7, ≥ 5 s in 5 (1016 40.0, 1275 39.4, 998 10.8, 981 8.0, 869 5.3). 910 (5.8 s leading) was heard as right, so the bar is the operator's. Proposed to the operator; not built.
- **Ruling reversed (operator 10-15):** "I think I probably made the wrong decision before." Finished runs are no longer left alone. Of the completed historic runs, 156 have sermon cuts planned before the 10-12 fixes (135 on media version 8 from the full pass, 21 from August–September with no version); the 217 cut in this round are current. Every one of the 156 whose replayed plan differs from its cut at all is re-cut, song and talk clips included, with no tolerance: the operator was generous with the 10-12 "right" endings that the tail rule moves by up to 0.3 s. Order: build the silence-edge rule (start: at most 2 s of dead air; end: trim dead air only, never a word's tail, proven against `RuledEndingsRegressionTest` and the 10-12 clipped endings), replay the 156 as a pre-flight, then one round on a new snapshot. The old ruling (§5: "138 finished runs stay untouched") stands in the record as superseded.
