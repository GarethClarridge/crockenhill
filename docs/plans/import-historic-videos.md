# Plan: `sermons:import-historic-videos` artisan command

## Context

The user has 275 GB of historic recordings on `/Volumes/CBC Drive/ServiceVideos` that need importing into the Crockenhill site as Sermon records. Most are raw OBS recordings of full Sunday services — many split into multiple files when recording was stopped/restarted mid-service. Some are pre-clipped sermons (in `YouTubeDownloads/`) and some are older `.mp4`s in `FromDocuments/`. The user wants a command modelled on `sermons:import-legacy` that pushes videos into the existing processing pipelines, with the question: *should everything go through the livestream pipeline so accidentally-recorded songs/prayers get auto-trimmed?*

**Confirmed strategy: yes, push everything through the livestream pipeline.** Auto-trim handles raw recordings cleanly, and even pre-clipped sermons benefit from RMS-based boundary detection in case any extra material was captured at the edges. Transcription runs locally (local Whisper), so per-minute API cost is not a constraint.

## What's actually on the drive

Counted: 45 standalone `.mkv` files at root + ~120 dated subdirectories + `FromDocuments/`, `YouTubeDownloads/`, `log.txt`. Filename formats:

| Pattern | Example | Date source |
|---|---|---|
| Root, full timestamp | `2022-01-16 18-38-15.mkv` | Filename |
| Root, underscore | `2023-11-05_10-42.mkv` | Filename |
| Inside dated dir | `2023-12-10/10-23.mkv` | Parent dir + filename time |
| FromDocuments | `2021-05-09 10-32-22.mp4` | Filename |
| YouTube clip | `Carols By Candlelight - 20 December 2020.mp4` | Filename (loose, often unparseable) |

Critical pattern: **subdirectories almost always contain multi-segment recordings of one service** (e.g., `2023-12-10/` has `10-23, 10-44, 10-55, 11-07, 11-39.mkv` — all morning service segments). Some dirs mix morning + evening (`2024-01-07/` has `10-38…11-43, 18-05`). Each segment is *not* its own sermon.

There are also stray `.mp3` files inside some subdirectories — manually-extracted audio from the same `.mkv`. These are duplicates and should be skipped.

## Pipeline routing

**Everything (raw recordings, FromDocuments, YouTubeDownloads) → livestream pipeline.** Single code path.

### What the pipeline's "auto-trim" actually does

Reading [SermonExtractionPlanResolver.php](app/Services/SermonExtractionPlanResolver.php) and [ExtractSermon.php](app/Jobs/ExtractSermon.php) carefully — the extraction has two tiers:

1. **Preferred path** — AI-classified `ServiceSection` records identify the SERMON section (and optionally an adjacent BIBLE_READING) with `confidence >= HIGH_THRESHOLD`. Precise boundaries.
2. **Baseline path** — picks the dominant speech segment, but `guardAutoExtractionPolicy` enforces: longest speech block ≥ **20 minutes** AND ≥ **1.5× longer than next-longest**. If either fails → **manual review email sent, extraction halts, no Sermon created**.

| Bucket | Behaviour |
|---|---|
| Raw full service (90+ min) with songs / prayers around the sermon | Works as expected — RMS distinguishes musical content; AI classifies; sermon extracted cleanly |
| Pre-edited YouTube clip that *is* a clean sermon | Entire clip becomes the sermon (no trimming needed) — fine |
| YouTube clip like "Reading, prayer and sermon" — all spoken, no gaps | Entire spoken block becomes the sermon (reading + prayer included). AI section classifier *may* split them but no guarantee |
| Short clip (< 20 min) | May fail the confidence guard → admin email, no Sermon until manual review |
| Two long talks (e.g. teaching service) | Triggers `multiple_qualifying_speech_blocks` → manual review |

### Implications for this command

The user (and admins) should expect a meaningful number of `[manual-review]` outcomes from the YouTubeDownloads folder specifically. The command itself doesn't need to handle these — they're surfaced via the existing `ManualReviewRequired` email and visible in the admin UI's processing queue. We just need to **report them clearly in the per-file output** so the user knows to expect admin notifications.

For raw recordings (the bulk of the 275 GB) the auto-trim is reliable.

**Skip:**
- `.mp3` files in subdirs (duplicates of the `.mkv` already being processed)
- Files smaller than `--min-size-mb` (default 30 MB ≈ ~10 min, won't yield a 5-min speech segment)
- Files with **unparseable dates** in `YouTubeDownloads/` (e.g., `26 April_ Sermon.mp4` — no year). Report these and let the user rename them and re-run, or pass `--default-year=YYYY` as a fallback.
- **Files where the livestream pipeline has already been run for that `(date, service)` pair** — i.e. a Completed Livestream `MediaProcessingLog` exists, or one is in-flight, or one is awaiting manual review. See "Pre-import existence check" below for the exact criteria. Note: an MP3-only `Sermon` record alone does NOT cause a skip — the upsert in `SermonCreationService` will enrich it.
- `log.txt`

## Pre-import existence check

The existing `UnifiedMediaProcessor::findActiveDuplicate()` dedup check fires *after* the file has been SHA-256 hashed, which means reading the entire multi-GB recording over USB and possibly running an FFmpeg concat first. We want to short-circuit much earlier — but only when there's genuinely nothing new to capture.

**Key insight:** a livestream contains far more than just the sermon. The pipeline produces `ServiceSection` records (AI-classified SERMON / BIBLE_READING / PRAYER / SONG / INTRO / OUTRO etc.), `LivestreamSegment` records (RMS-derived), and the original full-service video file. None of these exist when a Sermon was imported via the legacy MP3 importer. So the dedup key should be **"has this livestream already been processed?"** — not "does a Sermon exist?".

**Workflow per file or segment-group:**
1. Determine target `(date, service)` from filename/parent dir (cheap — just string parsing).
2. Build a base query: `MediaProcessingLog::query()->where('extracted_date', $date)->where('extracted_service', $service)->where('processing_type', MediaType::Livestream)`.
3. **Skip-existing**: if any record with `status = Completed` → `[skip-exists] 2023-12-10 morning → livestream already processed (log #abc-123)`. **No file IO, no hashing, no concat.**
4. **Skip-inflight**: if any record with `status IN (Pending, Processing)` → `[skip-inflight]` to avoid double-dispatch.
5. **Skip-manual-review**: use the existing model scope `MediaProcessingLog::awaitingManualSermonReview()` filtered by date+service. These rows have `status=Failed` AND `current_step='manual_review_required'` AND their `dedup_key` has been cleared (so the pipeline's own dedup *won't* catch them). Without this guard, re-running the command will redispatch the same service and trigger a duplicate `ManualReviewRequired` email. → `[skip-pending-review]`.
6. Only after all three checks pass: proceed with hashing → (concat if needed) → dispatch — **even when a `Sermon` already exists for `(date, service)`** (the upsert in `SermonCreationService` will enrich it rather than duplicate it; see next section).

`--force` bypasses all three checks (matches legacy importer convention) and overrides the upsert reject path too.

This check goes in `HistoricVideoImporter` as the first step inside the per-file loop, before the `UploadedFile` is constructed. It saves the most expensive case: re-running the command and unnecessarily re-reading hundreds of GB from the external drive.

## Sermon upsert in the pipeline (global, content-type aware)

[`SermonCreationService::createSermon()`](app/Services/SermonCreationService.php#L119) currently always calls `Sermon::query()->create($sermonData)`. We change it to a smart upsert that matches by **`(date, service, content_type)`** — and decides what to do based on the **richness** of the incoming pipeline relative to the existing record.

### Why content_type matters in the match key

The `Sermon` table holds *both* main sermons (`content_type = SermonContentType::Sermon`) and children's talks (`content_type = ChildrensTalk`) — see the `scopeWhereChildrensTalk()` scope in [Sermon.php](app/Models/Sermon.php) and the children's-talk inference logic in [OosAlignmentService.php](app/Services/OosAlignmentService.php). A morning service can therefore have *two* Sermon records for the same `(date, service)`: one for the sermon and one for the children's talk.

A `findByDateAndService()` match would non-deterministically hit either one, potentially enriching the children's talk with the sermon's video data (or vice versa). So:

- The historic import always targets `content_type = Sermon`. The pipeline's `SermonCreationService::createSermon()` call sets it via `$options->contentType`. The upsert query must scope to `where('content_type', $options->contentType)` to avoid cross-type collisions.
- Children's talks created later via section publication go through their own creation path with `content_type = ChildrensTalk` — they get their own upsert lane and never collide with the main sermon for the same date.

The repository helper is therefore `findByDateAndServiceAndContentType(Carbon $date, SermonService $service, SermonContentType $contentType): ?Sermon`.

**Richness ranking** (based on `MediaType` of the incoming processing log, and on which media fields are populated for the existing Sermon):

| Level | Existing Sermon detected by | Incoming pipeline | `SermonSourceType` |
|---|---|---|---|
| 1 — Audio | `livestream_processing_id` null AND `video_file_path` null | `MediaType::Audio` | `AudioUpload` (or `Manual`) |
| 2 — Video | `video_file_path` set AND `livestream_processing_id` null | `MediaType::Video` | `VideoUpload` |
| 3 — Livestream | `livestream_processing_id` set | `MediaType::Livestream` | `Livestream` |

(Reading existing fields is more reliable than checking `source_type` because pre-existing records may have it null.)

**Decision matrix:**

| Existing | Incoming | Action |
|---|---|---|
| (none) | any | **Create** — current behaviour preserved |
| Audio | Audio | **Replace** — admin re-uploading to fix audio (replace `audio_file_path`, transcript; preserve identity) |
| Audio | Video | **Enrich** — add video to audio-only sermon |
| Audio | Livestream | **Enrich** — add video + segments + structure |
| Video | Audio | **Reject** — log warning, throw `SermonRichnessDowngradeException`, mark MediaProcessingLog failed |
| Video | Video | **Replace** — re-uploading video |
| Video | Livestream | **Enrich** — add segments + livestream structure |
| Livestream | Audio | **Reject** — log warning, throw, fail processing log |
| Livestream | Video | **Reject** — log warning, throw, fail processing log |
| Livestream | Livestream | **Replace** — re-importing the livestream (e.g. better source file) |

**Three concrete operations:**

### Create
Current behaviour. Unchanged.

### Enrich (incoming is richer than existing)
Strictly additive — adds new capabilities without disturbing identity or existing media:

- **Always set:**
  - `video_file_path`
  - `livestream_processing_id`
  - `segment_start_time`, `segment_end_time`, `livestream_metadata`
  - `source_type` upgraded (Audio→Video, Audio/Video→Livestream)

- **Set only if currently null:**
  - `transcript_file_path`, `thumbnail_file_path`
  - `series`, `reference`, `bible_reference`, `points`, `summary`
  - `duration`, `audio_length`

- **Set only if `preacher_source = Default`:**
  - `preacher` / `preacher_id` (replaces the placeholder "Visiting Speaker" with the AI-detected preacher)

- **Never overwrite:**
  - `slug`, `audio_file_path`, `date`, `service`, `title`, `notes`, `show_summary`, `show_points`, `is_guest`
  - Any field whose `*_source` is `Manual`

### Replace (incoming is same richness as existing — admin re-doing an upload)
Refresh mutable media + AI-derived fields, preserve identity:

- **Always replace:**
  - `audio_file_path` (if incoming is Audio or Livestream)
  - `video_file_path` (if incoming is Video or Livestream)
  - `transcript_file_path`, `thumbnail_file_path`
  - `livestream_processing_id`, `segment_start_time`, `segment_end_time`, `livestream_metadata` (if incoming is Livestream)
  - `duration`, `audio_length`

- **Replace only if `*_source = Ai` or null** (AI-derived metadata can be refreshed):
  - `series`, `reference`, `bible_reference`, `points`, `summary`
  - `preacher` / `preacher_id` (only if `preacher_source` is not `Manual`)

- **Never replace:**
  - `slug`, `date`, `service`, `title`, `notes`, `show_summary`, `show_points`, `is_guest`
  - Any field with `*_source = Manual`

(Note: orphaned old media files on disk are not deleted — out of scope for this change. A separate storage maintenance job could clean them up.)

### Reject (incoming would be a downgrade)
Throws `SermonRichnessDowngradeException` (new). The pipeline's existing failure handler marks the MediaProcessingLog as failed with a clear message ("Refusing to overwrite richer sermon. Existing sermon for 2024-05-12 morning is a livestream; incoming is audio."). This surfaces in the admin UI and the failure notification email, so admins can diagnose the conflict.

### Implementation skeleton
```php
public function createSermon(MediaProcessingLog $processingLog, SermonCreationOptions $options): Sermon
{
    $sermonDate = $options->date ?? $this->extractDate($processingLog, $options->originalFilename);
    $service = $options->service ?? $this->extractServiceType($processingLog, $options->originalFilename);

    $existing = $this->sermonRepository->findByDateAndServiceAndContentType(
        $sermonDate,
        $service,
        $options->contentType,
    );

    if ($existing === null) {
        return $this->createFresh($processingLog, $options, $sermonDate, $service);
    }

    $action = $this->decideAction($existing, $processingLog->processing_type);

    return match ($action) {
        UpsertAction::Enrich => $this->enrichExisting($existing, $processingLog, $options),
        UpsertAction::Replace => $this->replaceExisting($existing, $processingLog, $options),
        UpsertAction::Reject => throw new SermonRichnessDowngradeException(...),
    };
}
```

### Why this is safe to apply globally
- **Existing `/api/sermons/audio` flow:** if an audio sermon exists, a re-upload triggers Replace (admin's intent). If a video or livestream sermon exists, the audio re-upload is rejected loudly — which is the right behaviour: an admin shouldn't accidentally degrade a video sermon to audio.
- **Existing `/api/sermons/video` flow:** same logic, one tier up.
- **Existing `/api/sermons/livestream` flow:** Livestream→Livestream is Replace; Livestream over Audio/Video is Enrich. Both make sense.
- **Legacy MP3 importer:** Audio source, Audio target — Replace if a Sermon exists (which the legacy importer's own pre-check would normally have caught). Effectively no change in practice.

**Files touched:** `app/Services/SermonCreationService.php`, `app/Repositories/SermonRepository.php` (add `findByDateAndService()`), new `app/Exceptions/SermonRichnessDowngradeException.php`, new `app/Enums/UpsertAction.php` (or inline match constants), `tests/Unit/Services/SermonCreationServiceTest.php` (matrix of all 9 cases above), and a small amount of churn in any test that asserts duplicate creation by date+service.

## Storage destinations (sermon disk + temp disk)

There are **two** disks that matter, configured separately in [config/media-processing.php](config/media-processing.php):

| Config key | Default | What it stores | Total size for 275 GB import |
|---|---|---|---|
| `storage.sermon_disk` | `local` (via env `SERMON_STORAGE_DISK` / `FILESYSTEM_DISK`) | Finished extracted sermon files (audio + video clips ≈ 5–10% of source) | ~15–30 GB across all imports |
| `storage.temp_disk` | `local` (hardcoded in config) | **Full source video files** during processing — the entire multi-GB livestream lives here from upload until `ExtractSermon` cleans it up | Up to whichever single source file is largest (multi-GB) per concurrent job |

**Critical:** even with `SERMON_STORAGE_DISK=spaces`, the pipeline still copies each *full source video* to the local temp disk via [`VideoStorageService::storeUploadedVideo()`](app/Services/VideoStorageService.php) before processing. With a Sail container disk of typical size, dispatching multiple multi-GB livestream jobs concurrently will fill local disk before any of them complete.

### Mitigations (all required for safe bulk import)

1. **Temp-disk capacity guard at dispatch time.** Before each per-file dispatch, check `disk_free_space()` on the resolved temp disk path. Abort if free space is below a configurable threshold (default: largest of `--temp-disk-min-free-gb=20` or 2× the incoming file size). Dispatch with the next file, not the same one. Skip with `[skip-low-disk]` and continue (the file is logged for retry).

2. **Serial dispatch by default.** The command waits for the previous dispatched MediaProcessingLog to leave Pending/Processing status before dispatching the next file. This bounds temp-disk usage to *at most one* full source file at a time.
   - Implementation: after dispatch, poll `MediaProcessingLog::find($processingId)->status` every N seconds (default 30) until it's no longer `Pending` or `Processing`, with a configurable timeout (default 2 hours per file).
   - Override flag: `--parallel=N` allows the user to dispatch up to N concurrent jobs if they have headroom (e.g. running on a beefy machine with 1 TB free), still gated by the capacity guard.

3. **Override temp disk via env.** Document that `MEDIA_PROCESSING_TEMP_DISK=spaces` in `.env` would make the temp disk also S3, eliminating local disk pressure entirely. Trade-off: FFmpeg operations stream from S3 which is much slower (5–10× wallclock per file). The command surfaces this option in its startup log so the user can choose. Not the default because slow.

4. **Sermon disk safety guard (existing).** Still enforced — if `SERMON_STORAGE_DISK=local` and `--allow-local-storage` isn't passed, abort. Set `SERMON_STORAGE_DISK=spaces` for the bulk import. Hybrid processing handles the upload via the existing retry logic in `VideoStorageService`.

### Recommended `.env` setup for the bulk import

```
SERMON_STORAGE_DISK=spaces
# leave temp disk as default 'local' — serial dispatch + capacity guard make this safe
```

### Recommended invocation

```
vendor/bin/sail artisan sermons:import-historic-videos \
    --dir="/Volumes/CBC Drive/ServiceVideos" \
    --temp-disk-min-free-gb=20
```

(Serial dispatch is the default — no flag needed.)

## Multi-segment day handling

For each dated subdirectory:
1. List `.mkv` / `.mp4` files.
2. Group by time-of-day window: **morning** (10:00–12:59 starts) and **evening** (17:00–21:00 starts).
3. For each group with **2+ files**: verify codec compatibility via `ffprobe` (compare video codec, audio codec, resolution, frame rate, sample rate). If they all match → concatenate via FFmpeg `concat` demuxer into a temp file (lossless, fast: `ffmpeg -f concat -safe 0 -i list.txt -c copy out.mkv`). Name it `YYYY-MM-DD HH-MM-SS.mkv` using the parent date + first segment's start time. Process the concatenated file through the livestream pipeline.
4. For groups with **1 file**: process it directly through the livestream pipeline (auto-trim still applies).
5. For files outside both windows: log as "unclassified" and skip unless `--include-unclassified` is set.

### Codec mismatch handling

A multi-segment service with mismatched codecs is unsafe to silently fall back to "process the longest segment alone" — the sermon may be in a shorter later segment, or split across files (e.g. a software glitch caused mid-sermon recording restart). Defaults are conservative:

- **Default behaviour:** mark the group as `[error] codec mismatch` and skip it. Surface clearly in the summary so the user knows manual intervention is needed for that day. Errors return non-zero exit so the user notices.
- **Opt-in re-encode:** `--reencode-mismatched` flag triggers a fallback path that re-encodes all segments to a common codec (e.g. `-c:v libx264 -preset veryfast -c:a aac -b:a 192k`) and concatenates the re-encoded files. Slower (CPU-heavy) but preserves all content. Surface in output as `[concat-reencoded]` so the user knows quality is no longer source-perfect.
- **Never silently use the longest segment** — that mode (the original buggy fallback) is removed.

The concatenation (lossless or re-encoded) produces a **single Sermon per service per day**, which is what we want.

## Command signature

```
sermons:import-historic-videos
    {--dir= : Root directory (default: /Volumes/CBC Drive/ServiceVideos)}
    {--from= : Only files from this date (YYYY-MM-DD)}
    {--until= : Only files up to this date (YYYY-MM-DD)}
    {--include-unclassified : Process files outside morning (10:00-12:59) and evening (17:00-21:00) windows}
    {--default-year= : Fallback year for YouTubeDownloads files lacking a year (e.g. 2020)}
    {--min-size-mb=30 : Skip files smaller than this}
    {--no-concat : Disable multi-segment concatenation; process each segment separately}
    {--reencode-mismatched : Re-encode segments with mismatched codecs before concatenation (slower; default is to skip mismatched groups with an error)}
    {--allow-local-storage : Allow running with SERMON_STORAGE_DISK=local (otherwise aborts)}
    {--temp-disk-min-free-gb=20 : Minimum free space on the pipeline temp disk before dispatch}
    {--parallel=1 : Max concurrent in-flight dispatches (default 1 = serial; bounds temp-disk usage)}
    {--poll-interval=30 : Seconds between status polls when serial-dispatching}
    {--per-file-timeout=7200 : Max seconds to wait for a single file's pipeline to finish (default 2h)}
    {--dry-run : Show what would happen, no work}
    {--delay=0 : Seconds between dispatches (in addition to serial poll wait)}
    {--limit=0 : Max sermons to import this run (0 = no limit)}
    {--force : Bypass all skip checks: completed log, in-flight, awaiting manual review, and the upsert reject path}
```

## Files to create / modify

| Path | Purpose |
|---|---|
| `app/Console/Commands/ImportHistoricVideoBatchCommand.php` | **New** — argument parsing, progress output, summary table — mirrors [ImportLegacySermonBatchCommand.php](app/Console/Commands/ImportLegacySermonBatchCommand.php) |
| `app/Services/HistoricVideoImporter.php` | **New** — discovery, classification, concatenation, duplicate check, dispatch — mirrors [LegacySermonImporter.php](app/Services/LegacySermonImporter.php) |
| `app/Services/SermonCreationService.php` | **Modify** — `createSermon()` becomes a content-type-aware upsert by (date, service): Create / Enrich / Replace / Reject per the matrix below |
| `app/Repositories/SermonRepository.php` | **Modify** — add `findByDateAndServiceAndContentType(Carbon $date, SermonService $service, SermonContentType $contentType): ?Sermon` |
| `app/Exceptions/SermonRichnessDowngradeException.php` | **New** — thrown when incoming pipeline would downgrade an existing richer Sermon |
| `tests/Feature/Console/ImportHistoricVideoBatchCommandTest.php` | **New** — feature tests (mocking `UnifiedMediaProcessor` and FFmpeg) |
| `tests/Unit/Services/HistoricVideoImporterTest.php` | **New** — unit tests for classification + concat list building |
| `tests/Unit/Services/SermonCreationServiceTest.php` | **Update** — add upsert + enrichment tests |

No new jobs or pipeline services needed — we **reuse** the existing pipeline:
- [`UnifiedMediaProcessor::process()`](app/Services/UnifiedMediaProcessor.php#L30) is the single dispatch entry point
- It accepts `'livestream'` type + an `UploadedFile` constructed with `test: true` from a local path
- Built-in dedup via `dedup_key` + filename hash; existing `MediaProcessingLog` row will short-circuit re-imports without `--force`

## Key implementation notes

1. **`UploadedFile` from local path:** `new UploadedFile($absolutePath, basename($path), null, null, true)` — the `test: true` flag bypasses the "must be uploaded via HTTP" check. `UnifiedMediaProcessor` and `LivestreamSegmentationService` use only `getSize()`, `getClientOriginalName()`, and stream contents — all work for local files. (Confirmed by reading [UnifiedMediaProcessor.php](app/Services/UnifiedMediaProcessor.php) and [LivestreamSegmentationService.php](app/Services/LivestreamSegmentationService.php).)

2. **`clientFileDate` parameter:** for subdirectory files like `2023-12-10/10-23.mkv` where the filename alone lacks the date, pass `clientFileDate: '2023-12-10 10:23:00'` so [`MetadataExtractionService::extractDateFromVideo()`](app/Services/MetadataExtractionService.php) gets a proper date+time. The pipeline's [`ProcessingInitiator::determineService()`](app/Services/ProcessingInitiator.php#L110) auto-detects morning/evening from the time component. For concatenated files, pass the parent dir date + first segment's start time.

3. **YouTube filename date parsing:** try multiple patterns — full date (`20 December 2020`), date with year context, day-month with `--default-year` fallback. If none yields a valid date and no `--default-year` set, skip with `[skip-no-date]` and report at the end so the user can fix names and re-run.

4. **Concatenation safety:** quick `ffprobe` check on each file in a group before generating the concat list (compare video codec, audio codec, resolution, frame rate). If they all match, concat with `-c copy` (lossless, fast). If they differ, fall back to longest segment + warn.

5. **Idempotency:** for **concatenated** files, hash the *concatenated output*, not the source files (concat output is deterministic, and we want a single dedup record per resulting Sermon). For **single files**, hash the source. The existing dedup check in `UnifiedMediaProcessor` (file_hash + dedup_key) handles the rest.

6. **Temp file cleanup:** concatenated files go to `storage/app/temp/historic-import/` with cleanup after dispatch (the pipeline copies the file into its own storage during `LivestreamSegmentationService::startProcessing()`). Use `Storage::disk('local')->delete()` rather than raw `unlink()`.

7. **External drive throughput:** reading 275 GB over USB will be the slowest step, not the pipeline. Process serially (no parallelism) and let `--delay` give the queue room to breathe between dispatches if needed.

## Output format

Match the legacy importer's per-file callback style:
```
[concat]              2023-12-10 → morning (5 segments, 2.1 GB) → dispatched → processing_id=abc-123 → completed
[concat-reencoded]    2023-06-25 → morning (3 segments, codec mismatch re-encoded) → dispatched → processing_id=def-456 → completed
[livestream]          2022-01-16 18-38-15.mkv → dispatched → processing_id=ghi-789 → completed
[enrich]              2023-05-21 morning → existing sermon #567 will be enriched with video + segments
[skip-exists]         2024-04-07 morning → livestream already processed (log #abc-123)
[skip-inflight]       2024-04-14 evening → livestream already in pipeline (processing_id=jkl-012)
[skip-pending-review] 2023-08-13 morning → awaiting manual sermon review (log #mno-345)
[skip-small]          2023-11-05_11-36.mkv → too small (32 MB)
[skip-dup]            2024-01-07/2024-01-07-am.mp3 → audio duplicate of .mkv in same dir
[skip-no-date]        YouTubeDownloads/26 April_ Sermon.mp4 → no year in filename (use --default-year)
[skip-low-disk]       2024-02-04 morning → temp disk free space below threshold (4.2 GB free, 20 GB required)
[error]               2023-06-25 → codec mismatch in segment group (use --reencode-mismatched to override)
[error]               2024-03-17 → upsert rejected: existing sermon is richer (livestream) than incoming (audio)
```

Final summary table: dispatched, concatenated (lossless / re-encoded), enriched (existing sermons updated), replaced, rejected, skipped (broken down by reason: exists / in-flight / pending-review / too-small / duplicate-audio / no-date / unclassified / low-disk), errors, total bytes processed, total bytes skipped.

## Verification

1. **Dry run on a small slice first:**
   ```
   vendor/bin/sail artisan sermons:import-historic-videos --dir="/Volumes/CBC Drive/ServiceVideos" --from=2022-01-01 --until=2022-02-01 --dry-run
   ```
   Should print classification + dispatch plan without touching anything.

2. **Live run with limit:**
   ```
   vendor/bin/sail artisan sermons:import-historic-videos --dir="/Volumes/CBC Drive/ServiceVideos" --limit=2
   ```
   Verify two `MediaProcessingLog` rows created, `livestream-processing` queue jobs dispatched.

3. **Test suite:**
   ```
   vendor/bin/sail artisan test --compact tests/Feature/Console/ImportHistoricVideoBatchCommandTest.php
   vendor/bin/sail artisan test --compact tests/Unit/Services/HistoricVideoImporterTest.php
   vendor/bin/sail artisan test --compact tests/Unit/Services/SermonCreationServiceTest.php
   ```

   **Command + importer service coverage:**
   - File discovery, classification (root / subdir / YouTube / FromDocuments)
   - Multi-segment grouping (morning / evening / mixed days)
   - Concat list generation; lossless concat path
   - **Codec mismatch defaults to `[error]`-and-skip; `--reencode-mismatched` triggers re-encode path**
   - **Skip-existing check** — Completed Livestream `MediaProcessingLog` for `(date, service)` causes skip
   - **Skip-inflight check** — Pending/Processing log for `(date, service)` causes skip
   - **Skip-pending-review check** — `awaitingManualSermonReview` scope match for `(date, service)` causes skip (avoids redispatching and double-emailing the admin)
   - **Proceeds when only an MP3-imported Sermon exists** (the upsert handles enrichment)
   - Children's-talk Sermon record at the same `(date, service)` does NOT cause a false match (because match is scoped to `content_type`)
   - Dry-run, `--from`/`--until` filtering, `--limit`, `--default-year`, unparseable-date skip
   - **Storage guards:** abort when `SERMON_STORAGE_DISK=local` without `--allow-local-storage`
   - **Temp-disk capacity guard:** when free space < `--temp-disk-min-free-gb`, dispatch is skipped with `[skip-low-disk]`
   - **Serial-dispatch:** with `--parallel=1` (default), command waits for previous log to leave Pending/Processing before dispatching next; respects `--per-file-timeout`
   - **`--parallel=N`:** allows up to N concurrent in-flight dispatches; capacity guard still applied per dispatch

   **`SermonCreationService` upsert coverage** — all nine cells of the matrix:
   - **Create:** no existing sermon → fresh record (matches current behaviour)
   - **Enrich (Audio→Video, Audio→Livestream, Video→Livestream):** existing sermon enriched; slug / title / `audio_file_path` / manual edits never overwritten; transcript / series / reference set only if null; preacher updated only when `preacher_source = Default`; `source_type` upgraded
   - **Replace (Audio→Audio, Video→Video, Livestream→Livestream):** mutable media fields refreshed; AI-derived fields refreshed if not Manual; slug / title / notes / manual fields preserved
   - **Reject (Video→Audio, Livestream→Audio, Livestream→Video):** throws `SermonRichnessDowngradeException`; `MediaProcessingLog` marked failed with clear reason
   - **Content-type isolation:** an existing children's-talk Sermon at `(date, service)` does NOT match when looking up a `Sermon`-content_type record (and vice versa); each content type has its own upsert lane
   - **`--force` overrides Reject** in the import command flow only (passes a flag through `SermonCreationOptions`)

4. **End-to-end on one real file:**
   Run the command with `--limit=1` against a known short standalone `.mkv`, then poll status:
   ```
   vendor/bin/sail artisan tinker
   >>> App\Models\MediaProcessingLog::latest()->first()->status
   ```
   Expect progression Pending → Processing → Completed and a Sermon record created with audio extracted from the longest speech segment.

5. **Quality checks:**
   ```
   vendor/bin/sail bin pint --dirty
   vendor/bin/sail composer phpstan
   vendor/bin/sail artisan test --compact --parallel
   ```

## What happens automatically (no extra work in this command)

- **S3 / Spaces upload** of finished sermon files — handled by the existing `VideoStorageService` whenever `SERMON_STORAGE_DISK=spaces`. The import command just needs to not write to local; the safety guard above enforces that.
- **Preacher / series / Bible reference metadata** — extracted by the existing `ProcessTranscriptWithAI` job that runs as part of the livestream pipeline after transcription. The Sermon records this command creates will be metadata-complete once their pipeline jobs finish.
- **Thumbnail generation** — `GenerateThumbnail` runs in the pipeline.
- **Completion notifications** — `SendCompletionNotification` job emails admins per the existing config.

## Out of scope (deliberately)

- **Batch renaming the YouTubeDownloads files with missing years** — surfaced in `[skip-no-date]` output, fixed by hand or by passing `--default-year`.
- **Manual override of AI-extracted metadata** — if AI gets the preacher wrong, that's edited in the existing admin UI, not by this command.
- **Handling files that trigger the manual-review confidence guard** — these surface as admin emails (`ManualReviewRequired` mail) and in the existing admin processing queue. The command will dispatch them and report them; resolving them is out of scope.
