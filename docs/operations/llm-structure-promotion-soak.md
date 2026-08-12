# LLM Structure Promotion Soak — Historical Runbook

> **Status (2026-07-20): historical.** The shadow wiring check, primary promotion, historic
> sample, and late-OOS reconcile exercise completed successfully on 2026-07-19. This runbook is
> retained for its stage summaries and backfill reference; it is no longer an open gate.

Written 2026-07-18. Step-by-step instructions for the revised promotion gate
(decision D22) from
[the archived July simplification parent](../archived-plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)
items 1.2 (shadow wiring check) and 1.4 (flip + historic-sample soak), and for analysing the
results. The design rationale is in the archived
[LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md](../archived-plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md).
The local-corpus counterpart of this runbook is
[livestream-corpus-testing.md](livestream-corpus-testing.md); production stack facts are in
[production.md](production.md).

**What the soak proves.** The local corpus runs already established detection quality
(test-files 91% / test-set-2 89% type accuracy, full chain, real detector, primary mode, with
validators catching all bad runs). The soak therefore only needs to prove two things that a
local run cannot: **prod wiring** (env config, Horizon, OpenAI calls, disk, email alerts) and
**timing-dependent behaviour** (the late-OOS reconcile path from seam 1.1b). It is *not* another
quality evaluation — per-service confidence has diminishing returns after ~8 services.

**What passing unlocks.** Backlog items 1.5 (delete the church-service heuristic cluster,
~14,000 lines) and then 1.6 (media visual stack). The soak sample itself is kept — these are
real backlog services, not throwaway test data.

## Stage summary

| Stage | What | Wall-clock | Earliest failure signal |
|---|---|---|---|
| 0 | Pre-flight checks | 15 min | immediately |
| 1 | Shadow wiring check (one service) | ~1–2 h | ~10 min in |
| 2 | Flip to primary | 15 min | immediately |
| 3 | Historic-sample soak (~8–12 services) | days of review throughput; ~30–60 min pipeline time each | first run, ~10 min in |
| 4 | Late-OOS reconcile exercise (once) | ~1 h | minutes after the `.osz` import |

All prod commands below run on the server via:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan …
```

(abbreviated to `php artisan …` from here).

---

## Stage 0 — Pre-flight checks

1. **Code prerequisites** (verified 2026-07-18; re-verify if time has passed):
   - Seams 1.1a–1.1d: PRs #1156–#1159 all **merged** 2026-07-12. ✅
   - Item 1.3 (auto-trim migration) has **not** landed — `buildAutoTrimVideoPipeline()` still
     uses the heuristic jobs. This does **not** block the flip: auto-trim chains are pinned
     mode-independent by tests, so auto-trim uploads keep working on the heuristic path in
     primary mode. It *does* block the 1.5 deletion later.
   - The deployed image is a recent `master` containing all four seams (check the running
     `IMAGE_TAG` SHA against `git log`).
2. **OpenAI key**: confirm `OPENAI_API_KEY` is set in `.env.production` and the account has
   quota. Whisper cost is ~£0.35 per 90-minute service plus one detector call
   (`SERVICE_STRUCTURE_MODEL`, default `gpt-5.6-sol`) — the whole soak costs under £10.
3. **Confirm current mode is `off`**:

   ```bash
   php artisan config:show media-processing.service_structure
   ```

4. **Horizon healthy**: `/horizon` dashboard green, no stuck jobs on `livestream-processing`;
   `/health` dashboard clean.
5. **Temp disk headroom**: the `app-temp` volume (`storage/app/temp` in the container) is the
   bottleneck. Each soak run needs roughly 2× the source file size free (staged source + working
   copies). Check free space inside the container: `df -h /var/www/html/storage/app/temp`.

## Stage 1 — Shadow wiring check (backlog 1.2)

Per D22 this is a *wiring check only* — one cleanly shadowed service, not weeks of accumulation.

1. Edit `.env.production` on the server. **All three lines are required** — `detector` and
   `transcription_service` default to `mock`, and a mock shadow run proves nothing:

   ```dotenv
   SERVICE_STRUCTURE_MODE=shadow
   SERVICE_STRUCTURE_DETECTOR=openai
   SERVICE_TRANSCRIPTION_SERVICE=openai
   ```

2. **Recreate the app container while nothing is processing** (env vars are injected via
   `env_file`, so an in-place `config:cache` is not enough; the swap kills in-flight jobs after
   ~10 s):

   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production up -d app
   ```

3. Verify the config took (repeat the `config:show` from Stage 0 — mode `shadow`, detector and
   transcription both `openai`).
4. **Process one service.** Either wait for the next natural Sunday livestream upload, or —
   faster — dispatch a single historic file (see Stage 3 steps 1–3 for staging) with
   `--limit=1`. In shadow mode the heuristic path stays authoritative, so this run's published
   output is unaffected regardless of what the LLM does.
5. **Watch it** (see "Detecting failure early" below for the signals). Shadow failures are
   swallowed by design and can never fail the run — you must look for them explicitly.
6. **Read the evidence** once the run is terminal:

   ```bash
   php artisan structure:shadow-report --since=2026-07-18
   ```

   Pass criteria: the run appears in the report, `passed_validation` is true (or the failure is
   explainable), the diff carries plausible section data, and the run's
   `processing_metadata['service_structure_shadow']` has **no `error` key**. Check directly:

   ```php
   // php artisan tinker
   App\Models\MediaProcessingLog::query()->latest()->first()
       ->processing_metadata['service_structure_shadow'] ?? 'no shadow data';
   ```

   An `error` key (OpenAI auth failure, timeout, malformed response) or the log line
   `Service structure shadow run failed` means the wiring check failed — fix before flipping.

## Stage 2 — Flip to primary (backlog 1.4)

Same mechanics as Stage 1: edit `.env.production` (`SERVICE_STRUCTURE_MODE=primary`, keep the
other two lines), recreate the app container while idle, re-verify with `config:show`.

**Instant rollback at any point**: set `SERVICE_STRUCTURE_MODE` back to `shadow` (or `off`) and
recreate the app container. The heuristic cluster still exists until 1.5, so rollback restores
the pre-flip pipeline exactly. Runs already processed keep their data; a run parked in manual
review stays reviewable either way.

## Stage 3 — Historic-sample soak (~8–12 services)

### 3.1 Pick the sample

Choose ~8–12 recordings from the CBC Drive backlog (`/Volumes/CBC Drive/ServiceVideos` on the
Mac). Aim for coverage, not volume:

- span several years and multiple preachers;
- at least one evening service (files are classified by time-of-day: morning 10:00–12:59,
  evening 17:00–21:00 — outside those windows the importer skips unless
  `--include-unclassified`);
- at least one service with a children's talk and one communion service (the section types the
  detector historically finds hardest);
- **at least two dates that have a matching `.osz` in the OpenLP archive but are not yet
  imported as `ChurchService` rows** — these become the Stage 4 reconcile exercise. Check with:

  ```php
  App\Models\ChurchService::query()->whereIn('date', [...candidate dates...])->get(['date','service','source']);
  ```

- files ≥ 30 MB (the importer's default `--min-size-mb` floor).

### 3.2 Transfer and stage

Copy the files to the server, then into the container's temp volume (the only host-visible
writable mount). Stage **2–3 files at a time**, not all 12 — the temp disk carries both the
staged source and the pipeline's working copies:

```bash
# From the Mac
scp "/Volumes/CBC Drive/ServiceVideos/<file>.mp4" user@server:~/historic-soak/

# On the server — copy into the app-temp volume via the container
docker compose -f docker-compose.prod.yml --env-file .env.production \
  cp ~/historic-soak/. app:/var/www/html/storage/app/temp/historic-soak/
```

Keep the original filenames — the importer parses the service date and time-of-day from them.

### 3.3 Dry run, then dispatch

Always dry-run first; it validates date parsing, window classification, size and duplicate
checks without touching anything:

```bash
php artisan sermons:import-historic-videos \
  --dir=/var/www/html/storage/app/temp/historic-soak --dry-run
```

Fix anything reported as `skipped_no_date` / `skipped_unclassified` before proceeding (rename,
or use `--default-year=`). Then dispatch **serially** (the default `--parallel=1` waits for each
run to reach a terminal state before dispatching the next — exactly what you want for a soak
where each run should be watched):

```bash
php artisan sermons:import-historic-videos \
  --dir=/var/www/html/storage/app/temp/historic-soak \
  --limit=1        # first run: process ONE, review it fully before continuing
```

**Review the first run end-to-end before dispatching any more** (see §5). A systematic prompt or
wiring problem shows up identically on every run — one fully-reviewed service is worth more than
eight unreviewed ones, and stops you burning the sample. After the first run is confirmed clean,
continue in batches (`--limit=2` or `--limit=3`), deleting each staged source file once its run
is terminal to free temp disk.

Each run takes ~30–60 minutes. The command polls and prints state transitions; it survives the
terminal via `nohup … &` if needed. Record every `processing_id` it prints — that list is the
soak ledger.

### 3.4 Natural Sundays

The 2–3 live Sundays that pass during the review period count toward the gate as normal. Note
one honesty caveat for the D6/5.3 measurements: bulk-imported services see their livestream
*before* any OOS email, which is the reverse of live-Sunday ordering — keep that in mind when
counting merge-workflow fire-rates (§5.4).

## Stage 4 — Late-OOS reconcile exercise (the 1.1b behaviour)

Historic services never trigger reconciliation naturally (their OOS is imported first or never),
so exercise it deliberately, at least once in prod:

1. Pick one of the Stage 3 runs that **completed** and whose service has **no** OpenLP-sourced
   `ChurchService` row yet.
2. Import its `.osz` — this fires `ChurchServiceCanonicalListChanged`, whose listener dispatches
   `ReconcileServiceSections` for every completed livestream run matching the service identity:

   ```bash
   # Stage the .osz the same way as the videos, in a directory of its own
   php artisan service-tracking:import-openlp-services \
     --path=/var/www/html/storage/app/temp/historic-soak-osz --dry-run
   php artisan service-tracking:import-openlp-services \
     --path=/var/www/html/storage/app/temp/historic-soak-osz
   ```

3. **Verify, within minutes**, on the run's `MediaProcessingLog`:
   - `processing_metadata['reconciliation_triggers']` records the trigger;
   - a `ReconcileServiceSections` job ran on the `livestream-processing` queue (Horizon), which
     in primary mode re-dispatches `DetectServiceStructure` in reconcile mode against the
     **stored transcript artifact** (no re-transcription, one detector call);
   - the run's `service_sections` now carry `church_service_item_id` anchorings to the imported
     OOS items;
   - the run itself is still `completed` — reconcile never re-opens completed runs.

4. Expected degradation, not a failure: the transcript artifact survives run cleanup but the RMS
   log does not, so reconcile re-detection **loses silence snapping gracefully** — boundary
   changes on reconcile are unsnapped proposals. Note it in the soak log; don't chase it.

A local corpus rehearsal of the same upload → complete → import-`.osz` sequence is a valid
substitute for the *mechanics*, but do at least one in prod.

---

## Detecting failure early

The pipeline takes 30–60 minutes per service, but almost every failure mode signals inside the
first ~10 minutes of its stage. Watch these, in order:

| # | Failure mode | Earliest signal | Where to look | Time to signal |
|---|---|---|---|---|
| 1 | Env didn't take (mode/detector still default) | `config:show media-processing.service_structure` shows `mock` or wrong mode | container shell | immediate, before any run |
| 2 | OpenAI auth/quota/network failure | `TranscribeFullService` fails and retries; in shadow, `service_structure_shadow.error` + `Service structure shadow run failed` log line | Horizon failed/retried jobs; `storage/logs/laravel.log` | ~5–10 min into the first run (transcription is the first external call) |
| 3 | Temp disk exhaustion | importer reports `skipped_low_disk`, or ffmpeg/staging steps fail | importer output; Horizon | at dispatch, or minutes in |
| 4 | Detector output rejected by the gate | run flips to `Failed` / `manual_review_required`, reason `llm_structure_validation_failed`; **admin email** (`ManualReviewRequired`) queued | admin review inbox (`/admin/services/inbox`); your inbox | end of detection, ~20–40 min in |
| 5 | Silent wrongness (validation passed, sections wrong) | none automatic — only human review catches it | workbench review of the run (§5.1) | at review — which is why the **first run is reviewed before the rest are dispatched** |
| 6 | Reconcile didn't fire | no `reconciliation_triggers` entry, no job on the queue | tinker + Horizon | within ~5 min of the `.osz` import |

Live-watch a run without babysitting the UI — poll the step from tinker (mirrors the corpus
harness's `poll.php`):

```php
App\Models\MediaProcessingLog::query()->where('processing_id', '<id>')
    ->first(['status', 'current_step', 'error_message']);
```

The step sequence you should see in primary mode:
`… → rms_generation → transcribe_full_service → detect_service_structure → … → extract_sermon → …`.
If `transcribe_full_service` hasn't started within ~10 minutes of upload validation, look at
Horizon. Per-step durations are also durable in `sermon_processing_steps`, so nothing is lost if
you check late.

**Stop rules** (park the soak, investigate, don't burn sample):

- signal #2 on the first run — wiring, not quality; nothing else will work either;
- the **first two** primary runs both hard-fail validation — the corpus baseline says hard
  failures should be occasional, so two-for-two points at a prod-specific problem (wrong model
  env value, truncated transcripts, mangled OOS context), not model variance;
- any run that *completes* with structurally wrong sections that validation should have caught
  (e.g. overlapping sections, two sermons) — that is a gate bug and outranks everything else;
- repeated `skipped_low_disk` / disk-pressure failures — stop and reclaim
  (`php artisan media:cleanup-temp-files`) before continuing.

A single validator-routed manual review is **not** a stop signal — that is the designed
fallback working (a scoreable outcome, same as the corpus harness treats it).

---

## Analysing the results

### 5.1 Per-run review (the primary evidence)

Review each soak service through the normal admin flow — inbox → service workbench — exactly as
an operator would, because operator-fitness is what is being promoted. Per run, record:

1. **Terminal state** — `completed` clean / `manual_review_required` (validator-routed, with
   reason) / failed-crashed (bug).
2. **Section structure** — types and order plausible against the recording; no merged adjacent
   songs (a hymn disappearing), no section absorbing unrelated following content, whole readings
   and whole songs as single sections.
3. **Sermon** — a `Sermon` record was created, boundaries sound right when you spot-listen to
   the first/last ~30 s of the extracted audio (boundaries off by >~30 s cut the published
   sermon — the one error class that reaches the public).
4. **Songs and readings** — matched titles and `reading_reference` values correct where the
   recording is unambiguous.
5. **Review flags** — list any `metadata.review_flags` on sections
   (`structure_low_confidence`, `structure_micro_section`, `structure_benediction_suspect`,
   `unknown_section_type`) and whether each was warranted.
6. **Children's talk vs sermon** — on the runs chosen for it, the `content_type` judgement is
   correct.

Apply the corpus doc's judgement calibration ("truth is partly opinion"): boundary deltas under
~15 s and defensible type alternatives (benediction as prayer/reading/other) are not failures.
The sermon-end convention (includes the preacher's immediate response prayer) applies here too.

### 5.2 The soak scorecard

Keep one table (append it to this file, or a dated sibling `llm-structure-soak-log-<date>.md`)
— aggregate numbers are meaningless without the config they ran under, so record the header
facts once: deployed image SHA, `SERVICE_STRUCTURE_MODEL`, `transcription_model`, mode.

| Service date | processing_id | Outcome | Sermon boundaries OK | Flags raised | Operator actions needed | Notes |
|---|---|---|---|---|---|---|

Useful ledger queries:

```php
// All soak runs and their terminal states
App\Models\MediaProcessingLog::query()->livestream()
    ->where('created_at', '>=', '<soak start date>')
    ->get(['processing_id', 'status', 'current_step', 'created_at']);

// Runs parked for manual review
App\Models\MediaProcessingLog::query()->awaitingManualSermonReview()->get(['processing_id']);
```

### 5.3 Optional: mechanical scoring

`structure:evaluate --processing-id=<id>` re-runs detect + snap + validate against a stored
run's transcript and prints the validation/detection summary (add manifest expectations for full
accuracy metrics — see `structure-eval-manifest.example.json`). **Caution in prod:** the
`--detector` default is the *bound* detector, which after the flip is `openai` — every entry
costs a real detector call. Use it for post-mortems on suspicious runs, not routinely. The
corpus harness (local) remains the free bulk-quality instrument.

### 5.4 The D6/5.3 observations (record during, not after)

The backlog defers the staged structure-merge collapse (item 5.3) pending soak data. While
reviewing, count:

- how often a service entered the pending-structure-merge state
  (`ChurchService` rows with `pending_structure_merge_source` non-null, plus inbox occurrences);
- what the operator chose each time (accept incoming / keep existing / manual merge).

Record the ordering caveat with the numbers: bulk-imported historic services meet their OOS in
the reverse order of live Sundays, so the fire-rate here under-represents live behaviour.

### 5.5 The gate decision

Proceed to backlog 1.5 (heuristic-cluster deletion) when **all** of:

- ~8–12 sample services are **clean, or their failures are understood and were
  validator-routed to manual review** (the designed fallback). Quality reference points from the
  archived plan's gate: sermon start *and* end within 30 s on ≥90% of services; **zero
  catastrophic misses** (sermon mislabelled or extracted from the wrong block); manual-review
  rate low enough to stay occasional.
- The late-OOS reconcile exercise verified (§Stage 4, all four checks).
- The D6/5.3 fire-rate observations recorded (§5.4).
- No new investment in heuristic-path tests happened during the soak (media test note 3).

Tick the two open production-check boxes in the backlog ("Enable `SERVICE_STRUCTURE_MODE`…" and
"1.4 historic-sample soak evidence…") with dates and a pointer to the scorecard. If the gate
fails: roll back to `shadow` (Stage 2 rollback), fix, and re-run only the failed portion — the
sample services already imported are kept either way.

### Aftercare

- Delete staged source files from `storage/app/temp/historic-soak/` (the pipeline's own
  artifacts are cleaned by the scheduled temp-file cleanup; your staged copies are not).
- The bulk historic backfill (~500 items) is **not** part of this soak — it waits for 1.7a
  (one-Whisper-pass) per D22. Do not keep feeding the importer past the sample.
- `HistoricVideoImporter` deletion (backlog 2.5) stays blocked until the full backlog is
  processed — the importer is the soak *and* backfill vehicle.
