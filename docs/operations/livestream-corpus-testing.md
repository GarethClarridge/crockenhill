# Livestream Corpus Testing — End-to-End Truth Comparison

Written 2026-07-09. Semi-manual regression testing of the **complete** livestream
processing chain against a corpus of real Sunday recordings with hand-annotated
ground truth. Complements [section-extraction-testing.md](section-extraction-testing.md),
which drives individual pipeline jobs and stops after `ExtractSermon`; this
harness instead pushes each recording through the real upload entry point
(`UnifiedMediaProcessor`) and lets the whole queue-driven chain run to a
terminal state — service detection, structure validation, section sync, song
matching, reading resolution, sermon extraction, and `Sermon` record creation.

This is deliberately a local, environment-specific harness. It writes to the
current development database and takes hours of wall-clock time. Portability is
not a goal.

## What lives where

The corpus is in `storage/scratch/july-test-files/` (gitignored):

| Path | Contents |
|---|---|
| `<date>/livestream.mp4` | Raw OBS recording for that Sunday (8 dates, 2023–2026) |
| `<date>/order-of-service.osz` | OpenLP export for that Sunday (missing for 2025-04-13 and 2026-03-01) |
| `README.md` | Corpus inventory and provenance |
| `truth.md` | Human-readable hand annotations per date |
| `_test-drive/` | The batch tooling (below) |

The tooling in `_test-drive/`:

| File | Purpose |
|---|---|
| `driver.sh` | Sequential batch runner: uploads each date, polls until terminal, moves on |
| `upload.php` | Uploads one livestream through `UnifiedMediaProcessor` (run via tinker) |
| `poll.php` | Prints `status:current_step` for the run named in `current.json` |
| `extract.php` | Dumps sections/sermons/metadata for every id in `processing-ids.txt` to `results.json`; falls back to the persisted structure proposal for failed runs |
| `compare.py` | Scores `results.json` against `truth.json` (plain python3, no deps) |
| `truth.json` | Machine-readable version of `truth.md` used by `compare.py` |
| `processing-ids.txt` | One processing id per run, appended by the driver — the ledger for extraction |
| `next.json` / `current.json` | Tiny control files the driver uses to pass arguments into tinker scripts |
| `archive/run-<date>/` | Outputs of previous batches (results, compare output, ids, logs) |

## Prerequisites

1. **Sail up, on the code you want to test** (usually latest `master`):

   ```bash
   vendor/bin/sail up -d
   ```

2. **Restart the queue workers if the code changed since they started.** The
   workers are long-running `queue:work` processes and hold old code in memory:

   ```bash
   vendor/bin/sail restart queue.worker queue.worker-video
   ```

3. **Native whisper-server on the host** (transcription). It is boot-launched
   and survives reboots; verify:

   ```bash
   curl -s http://localhost:2022/health   # → {"status":"ok"}
   ```

   `.env` must point at it: `TRANSCRIPTION_SERVICE_TYPE=local`,
   `LOCAL_WHISPER_URL=http://host.docker.internal:2022`,
   `LOCAL_WHISPER_TRANSCRIPTION_PATH=/inference`, and the same pair of
   `SONG_MATCHING_LOCAL_WHISPER_*` variables.

4. **Structure detection config.** The corpus baseline runs with
   `SERVICE_STRUCTURE_MODE=primary` and `SERVICE_STRUCTURE_DETECTOR=openai`
   (needs a valid `OPENAI_API_KEY`). Record the mode with your results — a
   shadow-mode run is not comparable to a primary-mode run.

5. **Disk space.** Each run stages the full video on the temp disk. Check
   `df -h /` (the driver prints free space after every run) and reclaim first:

   ```bash
   vendor/bin/sail artisan media:cleanup-temp-files
   ```

## Cleaning up a previous batch

Re-running the same files against a dirty database skews results: the previous
batch's `Sermon` rows, `ServiceSection` rows, and any livestream-created
`ChurchService` rows must go. Content-hash `dedup_key`s are cleared automatically
when a run reaches a terminal state, so completed/failed runs don't block
re-upload — only a stranded `processing` run does (see Resuming below).

From the ids in the previous `processing-ids.txt` (archive it first):

```php
// vendor/bin/sail artisan tinker
$ids = [...previous processing ids...];

// Sermons: delete via the model so Spatie media files are cleaned up.
App\Models\Sermon::query()->whereIn('livestream_processing_id', $ids)
    ->get()->each->delete();

// Logs: cascades livestream_segments, service_sections, sermon_processing_steps.
App\Models\MediaProcessingLog::query()->whereIn('processing_id', $ids)
    ->get()->each->delete();

// ChurchServices created BY the pipeline (source=livestream) should go;
// keep the OpenLP-imported ones (source=openlp) — they are test inputs.
App\Models\ChurchService::query()->where('source', 'livestream')
    ->whereIn('date', [...corpus dates...])->get()
    ->each(function ($svc) { $svc->items()->delete(); $svc->delete(); });
```

Then archive the old outputs and start a fresh ledger:

```bash
cd storage/scratch/july-test-files/_test-drive
mkdir -p archive/run-$(date +%F)
mv processing-ids.txt results.json compare-output.txt driver.log \
   current.json next.json archive/run-$(date +%F)/ 2>/dev/null
```

## Running the batch

```bash
cd storage/scratch/july-test-files/_test-drive
nohup ./driver.sh > driver.log 2>&1 &
tail -f driver.log
```

The driver processes the dates **sequentially** — one upload, then poll every
45 s until the run reaches `completed`, `failed`, or `cancelled` — because
parallel runs compete for the temp disk and the whisper server. Expect roughly
30–60 minutes per service and several hours for the full corpus of 8. `nohup`
matters: the batch outlives any terminal or SSH session.

The log shows one line per state transition, e.g.:

```text
2026-07-05 UPLOAD: OK id=71532b98-... msg=Livestream processing initiated successfully
2026-07-05 71532b98-... processing:transcribe_full_service
2026-07-05 71532b98-... completed:completed
DISK free: 14Gi
```

The final line is `ALL_RUNS_TERMINAL`.

`failed:manual_review_required` is a **scoreable outcome**, not a harness
error — it means the structure validator rejected the LLM proposal and routed
the run to manual review. The rejected proposal is persisted to
`processing_metadata.service_structure_proposal`, and `extract.php` scores it
like any other run (marked `sections_from_proposal` in the output).

## Resuming after an interruption

If the host dies mid-batch (the September 2026 laptop-battery incident):

1. Find the stranded run — a log stuck in `processing`. Mark it failed and
   clear its dedup key, otherwise the content hash blocks re-upload of the
   same file:

   ```php
   $log = App\Models\MediaProcessingLog::query()
       ->where('processing_id', '<stranded id>')->first();
   $log->forceFill(['status' => 'failed', 'dedup_key' => null])->save();
   ```

2. Reclaim temp disk: `vendor/bin/sail artisan media:cleanup-temp-files`.
3. Edit the `runs` array in `driver.sh` down to the dates that never reached
   a terminal state (including the stranded one — it re-uploads fresh with a
   new processing id).
4. Relaunch with `nohup` as above. `processing-ids.txt` appends, so the
   already-terminal runs stay in the ledger and are still scored.

## Extracting and scoring results

Once all runs are terminal:

```bash
cd /path/to/crockenhill
vendor/bin/sail artisan tinker --execute \
  'require "/var/www/html/storage/scratch/july-test-files/_test-drive/extract.php";'

cd storage/scratch/july-test-files/_test-drive
python3 compare.py | tee compare-output.txt
```

`compare.py` pairs each truth section with the generated section of largest
time-overlap and reports, per service and in aggregate:

- **type accuracy** — matched sections whose generated type is in the truth
  section's allowed `types` list;
- **boundary |delta|** — median/p90 of start/end offsets, plus within-15 s and
  within-30 s rates;
- **sermon boundaries** — start/end delta for the sermon section *and* for the
  run-level `sermon_start_time`/`sermon_end_time` (they can disagree; the log
  fields are what `ExtractSermon` actually cut);
- **song titles** — matched against truth via normalisation, preferring the
  matched-catalogue `metadata.song_title`, then `song_title_hint`, then the
  section title;
- **reading references** — normalised string equality with the truth `reading`;
- **unmatched truth sections / extra generated sections** — merges and
  hallucinations show up here.

Compare aggregates against the previous batch in `archive/` rather than
against absolute thresholds.

## Interpreting differences — truth is partly opinion

`truth.md`/`truth.json` were hand-annotated from watching the recordings.
Boundaries and labels involve judgement calls, so **different is not
necessarily wrong**:

- Several truth sections carry multiple acceptable `types`
  (a benediction may be fairly labelled `prayer`, `bible_reading`, or `other`).
- Exact boundaries between contiguous sections (song → prayer with no gap) are
  ±a few seconds of opinion. Deltas under ~15 s are rarely meaningful.
- Where the band plays into or out of a song, the "start of the song" is
  genuinely ambiguous.
- OpenLP orders of service list songs grouped by type, not in performance
  order — a pipeline result that deviates from OoS order but matches the
  recording is *correct*.
- **Sermon-end convention** (decided after the 2026-07-10 test-set-2 batch):
  the sermon section *includes* a short response prayer the preacher prays
  immediately after preaching, before any hymn or handover — the sermon ends
  when the preacher stops speaking. A closing prayer by a different speaker,
  or after an intervening item, is its own section. Annotate truth data and
  judge sermon-end deltas by this rule.

What generally **is** wrong: type mismatches on unambiguous sections (sermon,
children's talk), merged adjacent songs (a hymn disappears), sections absorbing
the following unrelated content (large `dE`), sermon boundaries off by more
than ~30 s (these cut the published sermon audio), and missing/incorrect
reading references where truth has an exact passage.

Update `truth.json` (and `truth.md`) when a "failure" turns out to be a truth
error — but record the change in the run notes so batches stay comparable.

## Recording the outcome

For each batch, keep in `archive/run-<date>/`: `processing-ids.txt`,
`results.json`, `compare-output.txt`, and the driver log. Note alongside them
the git commit, `SERVICE_STRUCTURE_MODE`/detector, and the Whisper model —
aggregate numbers are meaningless without them.
