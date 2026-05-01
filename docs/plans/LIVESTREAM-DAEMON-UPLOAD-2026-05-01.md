# Livestream Daemon Upload (2026-05-01) — Decision-Input-First Analysis with Laptop-Side Trimming

## Background

The church livestream pipeline currently accepts a full-resolution video upload from the church laptop, runs RMS audio analysis to identify sermon and other segments, extracts the wanted segments, and discards the rest. Today's recordings are 720p (~1.5 GB) but the goal is to move to 1080p (~5 GB) for archival quality. Uploading 5 GB only to discard most of it is wasteful in three ways:

- **Bandwidth**: 5 GB over the church's connection is slow and failure-prone.
- **Storage I/O**: full file lands on the server even though most bytes will be deleted.
- **Operator patience**: long uploads tie up the church laptop and increase the chance of an aborted session.

A second observation is that the inputs the *analyzer* needs are far smaller than the inputs the *viewer* needs. Several decision-time jobs consume media:

- `GenerateRmsLog` needs the audio waveform.
- `PerformVisualAnalysis` needs sample frames at 10-second intervals (`SAMPLE_INTERVAL_SECONDS = 10` in [VisualAnalysisService.php](../../app/Services/VisualAnalysisService.php)) for song-vs-speech disambiguation via brightness, contrast, and edge density.
- `TranscribeSpeechSegments` needs audio of speech regions.
- `MatchSongsFromTranscript` needs both frames within song regions (for OCR of projected lyrics via `SongLyricOcrService`) *and* audio of song openings (to transcribe the first few seconds and match against the lyrics catalogue).

Together, these inputs total ~80 MB of audio plus ~110 MB of OCR-readable sample frames, around ~190 MB. The full 1080p video is ~5 GB, but the kept segments after segmentation are typically ~2 GB — so the realistic comparison is "5 GB uploaded, 2 GB worth keeping" today vs "~190 MB of analyzer inputs followed by ~2 GB of kept segments" in the new flow. The asymmetry between *decision-making payload* and *display payload* is what creates the opportunity, not a near-elimination of upload bytes.

## Goal

Replace the monolithic 5 GB upload with a two-phase flow driven by a daemon on the church laptop:

1. Laptop extracts the analyzer's inputs locally — audio (~80 MB) plus sample frames at the existing 10-second interval at OCR-readable resolution (~110 MB at 1280×720) — and uploads them for analysis. Total analyze payload: ~190 MB.
2. Server runs RMS analysis on the audio and visual classification on the frames in parallel, then chains through segmentation, speech transcription, and song matching (which uses both frames for OCR and audio for opening-transcription). Returns segment timestamps and types.
3. Laptop trims the original 1080p video locally with `ffmpeg -c copy` (no re-encoding).
4. Laptop uploads only the kept segments via direct-to-S3 multipart uploads (~2 GB total — sermon, children's talk, songs).
5. Server triggers the existing post-review pipeline on the already-trimmed assets.

Total bytes uploaded: ~190 MB analyze + ~2 GB kept segments ≈ ~2.2 GB, vs. ~5 GB today. That's roughly 55% fewer bytes uploaded, full 1080p quality preserved end-to-end, segmentation and song-matching accuracy preserved (visual analysis and OCR both still run, on daemon-supplied frames), and zero weekly operator effort beyond moving the recording into a drop folder.

The headline win isn't actually byte savings (which are real but modest). It's that the bytes we *do* upload are bytes we *want* — every uploaded segment becomes a published piece of content. Today's 3 GB of "uploaded then discarded" is eliminated.

## Non-Goals

- Replacing the existing in-browser livestream upload. It stays as a manual fallback for testing, emergencies, or operators without laptop access.
- Real-time / streaming ingestion. The daemon is post-recording.
- Replacing or duplicating visual analysis. It remains in the pipeline; the daemon supplies pre-extracted sample frames so the existing `VisualAnalysisService` can run as it does today, with only its input source changing.
- Browser-based ffmpeg / WASM trimming. Browser heap limits make this impractical for 5 GB files; the daemon owns local processing.

## Rationale Summary

- **Why decision-input-first**: today we upload 5 GB and discard ~3 GB after segmentation. The new flow uploads ~190 MB of analyzer inputs, the server decides which segments to keep, and only then does the laptop upload the ~2 GB of kept segments. Total upload drops from ~5 GB to ~2.2 GB — modest in absolute terms, but the eliminated bytes are precisely the ones that would have been thrown away.
- **Why a laptop daemon over auto-upload-on-record-finish**: testing parity (drop a file in any time, no need to record a service), avoiding accidental processing of mid-week recordings (rehearsals, weddings, sound checks), explicit operator intent.
- **Why a drop folder over a watched OBS output folder**: filesystem-move atomicity (no "is the file done writing?" detection), unified treatment of all sources (local recording, YouTube fallback, USB-imported file), trivial recovery (drop the file again), and identical pattern in dev and production.
- **Why reuse the existing `manual_review` state machine**: the codebase already supports analyze-pause-resume; the daemon plays the role today filled by a human reviewer. New work is mostly a new pipeline definition and two thin endpoints.

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail artisan dusk` (only if any UI surfaces are added; not expected)

## Architecture Overview

```
[Church laptop]                                    [Server (Laravel)]
                                                   
OBS recording -> drop folder                       
        |                                          
        v                                          
Daemon detects file                                
Extract audio + sample frames (ffmpeg, ~190MB)     
Upload audio + frames -----------------> POST /api/livestreams/analyze
                                          - Creates MediaProcessingLog
                                          - Dispatches parallel jobs:
                                              GenerateRmsLog (audio)
                                              PerformVisualAnalysis (frames)
                                          - Then chains through segmentation
                                          - Pipeline pauses at manual_review
                                                   
Poll for result <----------------------- GET /api/livestreams/analyze/{id}
                                          - Returns segments + S3 multipart URLs
                                                   
Trim segments locally (ffmpeg -c copy)             
Upload trimmed segments ---------------> Direct-to-S3 (DigitalOcean Spaces)
                                                   
Notify finalize -----------------------> POST /api/livestreams/finalize
                                          - Validates soft identity match
                                          - Records segments as confirmed
                                          - Dispatches post-daemon chain
                                                   
Archive original to NAS                            - Sermon assets attached
                                                   - Transcription, AI, thumbnails run
                                                   - Completion email sent
```

## Decisions

### D1. Trigger model: drop folder, not auto-watch

Operator (or a one-line `.bat` shortcut) moves the recording to `D:\Crockenhill\drop\`. Daemon picks it up via filesystem watcher. Daemon immediately moves the file to `D:\Crockenhill\processing\<recording-id>\original.mkv` to give visual feedback and prevent double-processing.

**Why**: testability (can drop any file, including 5-second test clips), explicit operator intent, no need to filter out non-service recordings, atomic-move guarantee on same-volume operations.

### D2. Refactor shape: new pipeline definition reusing existing manual-review pattern

The new pipeline mirrors the existing livestream pipeline's parallel-then-chain shape ([ProcessingPipelineBuilder.php:115-156](../../app/Services/ProcessingPipelineBuilder.php#L115-L156)), differing only in input sourcing.

Add `ProcessingPipelineBuilder::buildLivestreamDaemonAnalysisParallelJobs()` — runs `PerformVisualAnalysis` (reading daemon-supplied frames) and `GenerateRmsLog` (reading daemon-supplied audio) in parallel.

Add `ProcessingPipelineBuilder::buildLivestreamDaemonAnalysisChainJobs()` — runs sequentially after the parallel phase: `AnalyzeSegments -> ClassifyServiceSections -> TranscribeSpeechSegments -> ClassifySpeechSections -> ProjectLivestreamServiceStructure -> AlignWithOos -> MatchSongsFromTranscript -> ReclassifyIntroOutroSections`, then transitions the log to `manual_review_required`.

Add `ProcessingPipelineBuilder::buildLivestreamPostDaemonChainJobs()` — identical to the existing `buildLivestreamPostReviewChainJobs()` *minus* `ExtractSermon` (the daemon already produced trimmed assets).

Validation runs ahead of the parallel phase: a new `ValidateDaemonAnalysisPayload` job (or extension to `ValidateAudioFile`) confirms both the audio file and the frame archive are present and well-formed.

**Why**: codebase already has the analyze-pause-resume pattern *and* the parallel-then-chain shape. The new pipeline reuses both; the only architectural delta is that two jobs read their inputs from daemon-supplied artifacts rather than extracting them from a server-side video. Keeping `ExtractSermon` skip explicit (new pipeline definition) is more readable than making the job conditionally a no-op.

### D3. Async, not sync

The analyze endpoint returns a `processing_id` immediately; daemon polls for completion. Matches the existing `ProcessingRunOrchestrator` and queue infrastructure.

**Why**: aligns with existing async patterns; avoids tying up a long HTTP request for ~30–60 seconds of RMS processing; lets the queue worker do what it's already configured to do.

### D4. Identity verification: soft cross-check

Daemon submits `original_filename`, `file_size`, and `duration` on the `analyze` call. Server stores them on `MediaProcessingLog`. On `finalize`, daemon submits the same three values. Server compares; mismatch returns a friendly error.

**Why**: catches realistic failure modes (operator drops the wrong file, daemon state file corruption, two recordings on the laptop) without cryptographic ceremony. Threat model is "human or daemon bug," not adversarial.

### D5. Domain object timing

`MediaProcessingLog` is created at `analyze` time (existing behavior in `LivestreamSegmentationService::startProcessing`). The `Sermon` record is created downstream during the audio-only chain (existing behavior — `ProjectLivestreamServiceStructure` or similar already does this for livestreams). The daemon's `finalize` call attaches trimmed video to a Sermon that already exists.

**Why**: matches the existing pipeline shape; no new orchestration needed.

### D6. Multi-segment mapping

A service produces multiple typed outputs (sermon, children's talk, songs, OOS items). Each is stored as a `LivestreamSegment` and mapped via existing `LivestreamSectionToServiceItemMapper` and `ChurchServiceItemSyncService`. The analyze endpoint returns segments tagged with type; the daemon uploads trimmed video for each segment that needs preservation; finalize wires each upload to the matching segment by ID.

**Why**: existing infrastructure already handles multi-output services; no model changes needed.

### D7. Partial success is allowed

Each segment's video upload is its own atomic operation. Segment status is updated as each completes. If 3 of 5 succeed and the laptop dies, the 3 are kept; the `MediaProcessingLog` lands in a `completed_with_warnings` state and a notification email lists what's missing.

**Why**: matches existing graceful-degradation patterns; partial output is more useful than rolled-back nothing.

### D8. Sunday-evening status email

A scheduled task at ~18:00 every Sunday emails a status report (`[OK] Sermon "Title" published at 18:42` or `[WARN] No recording was dropped today`). Reuses existing `Mailable` infrastructure.

**Why**: shifts failure detection from passive ("I'll notice if the website is missing a sermon") to active ("did I get the OK email?"), which is far easier to verify and harder to overlook.

### D9. YouTube as a fallback path, not a primary one

If the local recording is unavailable, the operator (or a manual command) downloads the YouTube version via `yt-dlp` and drops it into the same drop folder. The daemon and server treat it identically to a local recording.

**Why**: belt-and-braces redundancy; the drop folder pattern unifies fallback handling at zero design cost.

### D10. Preserve visual analysis and OCR via daemon-supplied frames at OCR-readable resolution

The daemon extracts sample frames at the existing 10-second interval (`SAMPLE_INTERVAL_SECONDS = 10` in [VisualAnalysisService.php](../../app/Services/VisualAnalysisService.php)) using `ffmpeg -vf fps=1/10 -s 1280x720`. For a 2-hour service this produces ~720 JPEG frames totalling ~110 MB. The frames are bundled (tar.gz) and uploaded alongside the audio in the analyze request. Server-side `PerformVisualAnalysis` and `SongLyricOcrService` both read frames from the unpacked archive instead of extracting them from a video.

The 1280×720 resolution is chosen to serve two consumers:

- **Visual classification** (`PerformVisualAnalysis`) needs low-cost metrics — brightness, contrast, edge density, ylow. These work at any resolution; 1280×720 is more than sufficient.
- **OCR of projected lyrics** (`SongLyricOcrService::extractLyrics`, called from `MatchSongsFromTranscript`) needs readable text. 320×180 would be too low. 1280×720 is the practical floor for reliable Tesseract output on typical projector slides.

One frame set serves both purposes: the daemon doesn't need to ship two resolutions.

Considered alternatives:

- **Skip visual analysis and OCR entirely.** Rejected: visual classification materially improves song-vs-speech disambiguation; OCR materially improves song identification (it's the strongest signal when the lyrics are clearly projected). `MatchSongsFromTranscript` already gracefully degrades to title-hint-only matching when the source is unavailable, but accuracy regresses noticeably.
- **Reimplement classification and/or OCR on the daemon.** Rejected: duplicates non-trivial logic and drift between server and daemon implementations would silently degrade results.
- **Daemon ships low-res frames for classification, on-demand high-res frames for OCR after analysis identifies songs.** Rejected: requires a roundtrip between server (which now knows where the songs are) and daemon (which has the original video), adding a phase to the daemon's state machine and complicating the API. Single-resolution upload is simpler and only ~80 MB more expensive.
- **Daemon ships full video.** Rejected: defeats the entire purpose; we're back to 5 GB uploads.

**Why**: preserves classification *and* song-matching accuracy at modest bandwidth cost (~110 MB instead of ~30 MB — still <2.5% of today's 5 GB), keeps the brain on the server (no duplicated logic), requires a moderate refactor to `VisualAnalysisService` and `SongLyricOcrService` to accept pre-extracted frames as input, and avoids any roundtrip between daemon and server during analysis.

### D11. Media source abstraction for analysis-time jobs

`MatchSongsFromTranscript` currently takes `$localSourcePath` (the original video) and uses it for two operations: extracting frames at specific timestamps for OCR, and extracting audio from specific time ranges for song-opening transcription. With the daemon flow, the original video isn't on the server — only the frames archive and the audio file are.

Introduce a `MediaSourceProvider` interface with two implementations:

- **`VideoBackedMediaSource`** — wraps a video path, extracts frames and audio on demand via ffmpeg. Used by the existing browser-upload pipeline.
- **`DaemonArtifactMediaSource`** — wraps `(audio_path, frames_directory)`, returns the nearest available frame for a target timestamp and slices audio from the audio file directly. Used by the daemon-driven pipeline.

`MatchSongsFromTranscript`, `SongLyricOcrService`, and any other analysis-time job that previously used `$localSourcePath` accept the interface instead. The daemon controller assembles the appropriate provider based on the `MediaProcessingLog`'s source type.

Considered alternatives:

- **Sibling methods on each consumer.** Adding `extractLyricsFromFrames(...)` next to `extractLyrics(...)` works but spreads the daemon-vs-video branching across multiple services. The interface approach centralises the decision.
- **Reconstruct a synthetic video from frames + audio.** Theoretically allows zero refactor of consumers, but ffmpeg-encoding a fake video from scattered frames is fragile, slow, and produces a video that visual classification might score differently than the real one.

**Why**: cleanly separates "what does this job do?" from "where does the media come from?". The refactor surface is moderate but bounded — the interface lives in front of two services and a handful of jobs. Same abstraction would benefit any future analysis-time consumer of media.

## Test Strategy

The codebase already has fixture-as-builder at the RMS-log level (`buildRmsLog([...])` helper in `VideoSegmentationServiceRmsTest`). The strategy extends that pattern up the stack.

### Three test layers

| Layer | Inputs | Fixtures | Speed | Where |
|---|---|---|---|---|
| Unit | Synthetic RMS log strings; synthetic frame metric arrays | `buildRmsLog([...])` helper, in-memory metric structs, no binary files | <100ms each | In test files |
| Feature | Tiny generated `.m4a` files + matching frame sequences | `tests/Fixtures/audio/` and `tests/Fixtures/frames/` (~6MB total committed) | ~1–2s each | Repo |
| Manual exploratory | Real recordings | Operator's gitignored `D:\Crockenhill\test-recordings\` | Minutes | Local-only |

CI runs unit + feature. Manual is the developer's responsibility before significant releases.

### Audio fixtures (six, generated)

A new artisan command `php artisan test:build-audio-fixtures` reads `tests/Fixtures/audio/manifest.yaml`, runs `ffmpeg`, writes outputs:

1. `happy-path.m4a` (60s) — silence-speech-silence-speech-silence; predictable boundaries.
2. `pure-silence.m4a` (30s) — segmenter must handle "no segments found".
3. `pure-speech.m4a` (30s) — no silence gaps; segmenter must produce one giant segment.
4. `tiny.m4a` (1s) — `ValidateAudioFile` minimum-duration boundary case.
5. `corrupt.m4a` — truncated valid file; ffmpeg should fail gracefully.
6. `wrong-format.m4a` — text file with `.m4a` extension; `ValidateAudioFile` should reject.

Both manifest and generated binaries are committed. CI verifies checksums match the manifest output.

### Synthetic over real

Audio fixtures are generated, not real recordings. Generated fixtures give exact-match assertions (`assertEquals([{ start: 5.0, end: 15.0, type: 'speech' }], $segments)`). Real recordings would force weak assertions like `assertGreaterThan(0, count($segments))` and miss subtle regressions.

Real recordings stay out of the repo entirely — they live in the operator's gitignored test-recordings folder for full end-to-end exploratory testing via the drop folder.

### New test helpers

- `SegmentBuilder` — fluent helper that assembles coherent `LivestreamSegment` data for downstream-job tests that don't care about the audio (`SegmentBuilder::for($log)->silence(0, 30)->speech(30, 240, type: 'song')->save()`).

## Implementation Plan

### Phase 1 — Server-side daemon-analysis pipeline

Priority: **Critical** — blocks daemon work.

Target files:

- `app/Services/ProcessingPipelineBuilder.php` — add `buildLivestreamDaemonAnalysisParallelJobs()`, `buildLivestreamDaemonAnalysisChainJobs()`, and `buildLivestreamPostDaemonChainJobs()`.
- `app/Services/ProcessingPhaseRegistry.php` — register phase mappings for the new pipeline so progress reporting works.
- `app/Services/MediaSourceProvider.php` (new interface) plus `VideoBackedMediaSource` and `DaemonArtifactMediaSource` implementations (D11). Provide methods like `frameAtTimestamp(float $time): ?string` and `audioSlice(float $start, float $end): string`.
- `app/Services/VisualAnalysisService.php` — split `extractFrameMetrics()` into "extract from video" and "compute metrics from existing frames" phases, or add a sibling `extractFrameMetricsFromFiles(array $framePaths, ...)` entry point. Keep `analyzeVideo()` and `refineBoundaries()` callable in both modes.
- `app/Services/SongLyricOcrService.php` — refactor `extractLyrics()` to take a `MediaSourceProvider` instead of `$localVideoPath`. The existing video-extracting `extractFrame()` becomes the implementation behind `VideoBackedMediaSource::frameAtTimestamp()`.
- `app/Jobs/MatchSongsFromTranscript.php` — replace `$localSourcePath` resolution with `MediaSourceProvider` resolution. Both video-backed and daemon-artifact sources route through the same downstream logic.
- `app/Jobs/PerformVisualAnalysis.php` — read frame source from log metadata (daemon-supplied tar.gz path vs. extract-from-video path). Both modes call into the same downstream classification logic.
- `app/Services/LivestreamSegmentationService.php` — accept the (audio + frames) payload, thread through to the new pipeline.
- `app/Http/Controllers/Api/` — new controller (e.g. `LivestreamDaemonController`) with `analyze`, `analyzeStatus`, `finalize`, `heartbeat` endpoints.
- `routes/api.php` — register new routes under `/api/livestreams/`.
- `config/media-processing.php` — confirm audio formats are present in the relevant allowlist.

Tasks:

- [ ] Verify whether `ValidateAudioFile` job uses its own format allowlist or the shared `media-processing.allowed_formats`. If shared, add `m4a/aac/mp3` if missing.
- [ ] Verify exact job that creates the `Sermon` record in the livestream pipeline (`ProjectLivestreamServiceStructure` or `SubmitToProcessing`?) so the daemon-server contract is precise.
- [ ] Define the `MediaSourceProvider` interface and the two implementations (`VideoBackedMediaSource`, `DaemonArtifactMediaSource`). Unit tests for both, including frame-nearest-timestamp lookup and audio-slice extraction.
- [ ] Refactor `VisualAnalysisService::extractFrameMetrics` to support pre-extracted frames as input (split or sibling-method). Add unit tests for the new entry point using synthetic JPEG fixtures.
- [ ] Refactor `SongLyricOcrService::extractLyrics` to take a `MediaSourceProvider`. Existing video-extraction logic moves into `VideoBackedMediaSource`. Add tests comparing OCR output against the same source delivered as a video vs. as a daemon artifact bundle.
- [ ] Refactor `MatchSongsFromTranscript` to resolve a `MediaSourceProvider` instead of `$localSourcePath`. Tests must cover both source types and the existing graceful-degradation path when neither OCR nor opening-transcription is available.
- [ ] Update `PerformVisualAnalysis` job to detect daemon-supplied frames (via log metadata) and route to the new entry point. Existing extract-from-video path stays intact for the legacy livestream upload.
- [ ] Define `buildLivestreamAudioAnalysisOnlyPipeline()` and `buildLivestreamPostDaemonChainJobs()`.
- [ ] Wire phase keys for both pipelines into `ProcessingPhaseRegistry`.
- [ ] Add `LivestreamDaemonController` with analyze/analyzeStatus/finalize/heartbeat endpoints.
- [ ] Define analyze-request payload shape: multipart form with `audio` (m4a), `frames` (tar.gz of JPEGs), and metadata fields. Server unpacks frames archive into a temp directory referenced by `MediaProcessingLog.processing_metadata`.
- [ ] Implement soft identity check in `finalize` (filename + size + duration cross-match against stored `MediaProcessingLog`). Add a soft sanity check on frame count vs. expected (`duration / 10` ± tolerance) at analyze time.
- [ ] Pre-signed multipart S3 URL generation in `analyzeStatus` response (use AWS SDK `S3Client::createMultipartUpload()`).
- [ ] Feature tests for both endpoints using audio + frame fixtures.
- [ ] Unit tests for the new pipeline definitions, phase mappings, and `VisualAnalysisService` frames-from-disk entry point.

Exit criteria:

- Daemon-shaped HTTP requests against the new endpoints produce a Sermon record and run the post-daemon chain end-to-end with mocked queues.
- Segment boundaries from synthetic audio + frame fixtures match expected timestamps exactly.
- Visual analysis on daemon-supplied frames produces identical classifications to visual analysis on frames extracted from the same source video.

### Phase 2 — Test fixture infrastructure

Priority: **High** — feeds Phase 1 tests.

Target files:

- `app/Console/Commands/BuildAudioFixturesCommand.php` (new).
- `app/Console/Commands/BuildFrameFixturesCommand.php` (new).
- `tests/Fixtures/audio/manifest.yaml` (new).
- `tests/Fixtures/audio/*.m4a` (generated, committed).
- `tests/Fixtures/frames/manifest.yaml` (new).
- `tests/Fixtures/frames/*/` (generated frame sequences, committed).
- `tests/Support/SegmentBuilder.php` (new).

Tasks:

- [ ] Define audio manifest format (silence/tone block declarations with durations and frequencies).
- [ ] Implement `BuildAudioFixturesCommand` that runs `ffmpeg` per fixture, writes outputs.
- [ ] Generate the six audio fixtures, commit them.
- [ ] Define frame manifest format (per-fixture: count, resolution, frame metric profile — `song`/`speech`/`mixed` — any specific timestamps that should classify a particular way, and optional rendered-text content for OCR fixtures).
- [ ] Implement `BuildFrameFixturesCommand` that generates synthetic JPEGs at 1280×720 with controlled brightness/contrast/edge density to produce predictable visual classifications. For OCR-targeted fixtures, render known lyric text onto the frames using a standard font so OCR output can be asserted against ground truth.
- [ ] Generate frame fixtures matching the audio fixtures (e.g. `happy-path/` contains 6 frames matching the 60s audio at 10s intervals). Add at least one OCR-targeted fixture (`song-with-projected-lyrics/`) where specific frames contain readable lyric text and the test asserts `SongLyricOcrService` extracts the expected text.
- [ ] Add CI verification step (regenerate from manifests, compare checksums).
- [ ] Implement `SegmentBuilder` fluent helper for downstream-job tests.

Exit criteria:

- `php artisan test:build-audio-fixtures` and `test:build-frame-fixtures` regenerate all fixtures deterministically.
- Feature tests in Phase 1 use the generated fixtures.

### Phase 3 — Windows daemon

Priority: **Critical** — delivers user-facing value.

Daemon implementation:

- Language: Python 3.x. Rationale: `watchdog` library handles Windows file events cleanly; `requests` for HTTP; `boto3` for S3 multipart; portable to Linux for dev.
- Hosting: NSSM-wrapped Windows service with auto-restart.
- ffmpeg: bundled in install dir, not relying on PATH.
- State persistence: JSON file per recording at `%APPDATA%\CrockenhillDaemon\state\<recording-id>.json`.
- File watcher: debounced `on_modified` to handle Windows write-while-watcher-fires race.

Tasks:

- [ ] Project skeleton (Python, dependencies, NSSM install scripts).
- [ ] Drop folder watcher with atomic-move-to-processing semantics.
- [ ] Audio extraction via bundled ffmpeg (`-vn -c:a aac -b:a 96k`).
- [ ] Frame extraction via bundled ffmpeg (`-vf fps=1/10 -s 1280x720`, JPEG output, quality ~85). Sample interval matches `VisualAnalysisService::SAMPLE_INTERVAL_SECONDS`; if that constant changes server-side, daemon's interval must follow. Resolution chosen for OCR readability (per D10).
- [ ] Frame bundling into tar.gz for upload (chosen over zip for streaming-friendly output and ubiquity in Python's stdlib).
- [ ] Frame count sanity check before upload (warn if `count != round(duration / 10)` ± small tolerance — catches truncated extractions).
- [ ] HTTP client for `analyze` / `analyzeStatus` / `finalize` endpoints. Multipart form upload with audio + frames archive + metadata.
- [ ] Multipart S3 upload with retry/resume semantics for trimmed video segments.
- [ ] `ffmpeg -c copy` segment trimming based on returned timestamps.
- [ ] State machine with phase persistence (resumable across restarts). Phases: `new -> extracting_audio -> extracting_frames -> bundling_frames -> uploading_analysis_payload -> awaiting_analysis -> trimming_and_uploading_segments -> finalizing -> done`.
- [ ] Heartbeat: `POST /api/livestreams/heartbeat` every 5 minutes.
- [ ] Archive successful runs to a configurable NAS folder; failed runs to a `failed\` folder with `error.txt`.
- [ ] NSSM service registration script and uninstaller.
- [ ] Operator-facing log file with rotation.

Exit criteria:

- Drop a generated test fixture into the drop folder; trimmed segments arrive in S3; sermon appears on the website.
- Daemon survives a forced kill mid-phase and resumes correctly on restart.

### Phase 4 — Operations

Priority: **Medium** — quality-of-life, not blocking.

Tasks:

- [ ] Sunday-evening status email scheduled task. Sends `[OK]` if a recording was processed today, `[WARN]` if not. Reuses `Mailable` infrastructure.
- [ ] Server-side heartbeat freshness check: if no heartbeat for >25 hours, send a daemon-down alert email.
- [ ] Documentation for operators: how to drop a file, what to do if processing fails, how to invoke the YouTube fallback (`yt-dlp <URL>` to drop folder).

Exit criteria:

- A missed-processing scenario produces an email by Sunday evening at the latest.
- A daemon-offline scenario produces an email within 25 hours.

## Open Questions to Verify During Implementation

1. **Format allowlist scope**. Confirm whether `ValidateAudioFile` job uses `media-processing.allowed_formats` (shared with video) or a separate audio allowlist. Determines whether config needs an additive change or is already correct.

2. **Sermon record creation point**. Confirm exactly which job in the existing livestream chain creates the `Sermon` record (`ProjectLivestreamServiceStructure`, `SubmitToProcessing`, or another). Determines whether the daemon's `finalize` finds an existing Sermon and attaches media, or whether it needs to handle a creation path.

3. **`ExtractSermon` semantics**. Confirm that excluding `ExtractSermon` from the post-daemon chain is sufficient, vs. needing to make it tolerant of a pre-existing trimmed file at its expected output location. The pipeline-definition approach in D2 should make this a non-issue, but worth a sanity check during Phase 1.

4. **S3 multipart vs single-PUT cutoff**. Trimmed segments span a wide size range (a 4-minute song might be 100MB, a 35-minute sermon 1.2GB). Decide whether the daemon always uses multipart, or single-PUT under (say) 100MB. Multipart-only is simpler; single-PUT under 100MB is slightly faster for small segments. Default: multipart-only unless profiling shows it matters.

5. **Drop folder volume**. Recordings and drop folder must be on the same Windows volume for atomic moves. Confirm during installation that OBS records to a path on `D:\` (or wherever the drop folder lives) and document this as a prerequisite.

6. **`VisualAnalysisService` refactor surface**. Confirm whether `extractFrameMetrics(string $videoPath, ...)` can be cleanly split into "extract from video" and "compute metrics from existing frames" without disturbing `analyzeVideo()`, `refineBoundaries()`, or `extractFrameMetricsInRegion()`. If the internal coupling is tight, the alternative is a sibling method that takes a frames directory and reuses the same metric computation. Either is acceptable; pick the smaller diff.

7. **10-second sampling adequacy across consumers**. Three different jobs consume the frame samples — `PerformVisualAnalysis`, `SongLyricOcrService` (called from `MatchSongsFromTranscript`), and `VisualAnalysisService::refineBoundaries` / `extractFrameMetricsInRegion`. Visual classification works fine at 10-second intervals (that's already the existing default). OCR currently picks a specific timestamp within a song segment; with 10-second sampling, the nearest available frame may be up to 5 seconds off-target — likely fine for typical 4-minute songs, possibly inadequate for short songs or songs with rapid lyric changes. Boundary refinement may rely on denser sampling inside song clusters. Options to evaluate during Phase 1: (a) keep 10-second sampling and accept any minor regressions, (b) daemon oversamples uniformly at 5-second intervals (~220 MB of frames instead of ~110 MB, total analyze upload still ~300 MB), (c) the analyze response can request additional frames in specific regions and the daemon supports a follow-up extraction call (most complex; allows adaptive density). Option (a) first, with measurement; only escalate if measurement shows real accuracy loss.

8. **Frame bundle format**. Tar.gz of JPEGs is the default choice (streaming-friendly; trivial Python and PHP support; minimal compression overhead since JPEGs are already compressed). Alternative: individual multipart form parts per frame. Validate during Phase 1 that tar extraction performance on the server is acceptable for ~720 frames; if not, evaluate a single concatenated JPEG bundle with byte-offset index.

9. **Cloud archive of original recordings**. The daemon archives the 5 GB original to a configurable local NAS folder (D8 mentions this only briefly). Today's pipeline preserves originals in cloud storage; the new flow does not. If the AI improves and we want to reprocess from source (better speaker identification, refined segmentation, new analysis types), only the NAS has the recording. Decide: (a) accept that reprocessing requires retrieving from NAS — fine if the NAS is backed up off-site, (b) extend the daemon to upload the original as a low-priority background transfer overnight (defeats some of the bandwidth gain but preserves cloud archive), (c) accept that originals are local-only and reprocessing requires manual re-upload. Recommend documenting the NAS-backup expectation explicitly and revisiting if any of those reprocess scenarios become real needs.

## Estimate

- Phase 1 (server-side): ~3.5 days. Includes the `VisualAnalysisService` refactor, `SongLyricOcrService` refactor, the `MediaSourceProvider` interface and two implementations, and `MatchSongsFromTranscript` rewiring. The OCR/song-matching path is the largest single chunk of refactor work.
- Phase 2 (fixtures): ~1.5 days. Audio + frame fixture builders, OCR-targeted frame fixtures with rendered text, manifests, and the `SegmentBuilder` helper.
- Phase 3 (daemon): ~3.5–4.5 days. Frame extraction at 1280×720, bundling, additional state-machine phases.
- Phase 4 (operations): ~0.5 day.

Total: roughly 9–10 working days of focused effort. The original estimate (~6 days) was too optimistic because it underestimated the analysis-time consumers of media — every job that reaches into `$localSourcePath` during the analyze chain needs to be adapted, not just `PerformVisualAnalysis`. Server-side refactor work has roughly doubled compared to the first cut.

The architectural alignment with the existing manual-review pattern still keeps the *pipeline orchestration* small. What grew is the boundary work — substituting daemon-supplied artifacts for the original video in places where existing services assumed it was always available.
