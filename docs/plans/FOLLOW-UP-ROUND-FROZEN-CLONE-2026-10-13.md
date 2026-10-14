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
