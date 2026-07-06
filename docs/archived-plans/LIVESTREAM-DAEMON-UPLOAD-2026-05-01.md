# Livestream Daemon Upload (2026-05-01) - Analysis Proxy Video with Laptop-Side Trimming

> **ARCHIVED 2026-07-05 — never started, and now stale. Do not implement from this document.**
> The design assumes the heuristic analysis stack (`PerformVisualAnalysis`, visual song clusters,
> OCR song matching, `TranscribeSpeechSegments`) that the July 2026 backlog deletes (Workstream 1
> of
> [../plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](../plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md),
> decisions D1/D4). The underlying problem — a ~5 GB 1080p browser upload over the church's slow
> connection — remains real. If it still hurts after Workstream 1 lands, write a **fresh plan**:
> the LLM-first pipeline needs far less from a proxy (audio adequate for one Whisper pass, plus an
> RMS log), so the daemon becomes materially simpler than what is designed here.

## Recommendation

Proceed with the laptop daemon, but do not implement the original "audio plus frame archive" design.

The better shape is:

1. The daemon creates a small analysis proxy video from the full 1080p recording.
2. The server runs the existing livestream analysis jobs against that proxy video.
3. Once the server knows which sections to preserve, the daemon trims the original 1080p file locally.
4. The daemon uploads only the full-quality kept assets.
5. The server finalizes those uploaded assets into the existing sermon and section-publishing flows.

This keeps the main win, which is avoiding a 5 GB full upload, while removing most of the brittle refactor work from the first plan. A proxy video gives the existing server jobs one normal media path to inspect, so `GenerateRmsLog`, `PerformVisualAnalysis`, `TranscribeSpeechSegments`, `MatchSongsFromTranscript`, OCR, and dense visual boundary refinement can continue to behave like video-backed jobs.

The tradeoff is that the analysis payload is larger than the original ~190 MB estimate. Expect something closer to ~300-700 MB for a two-hour 720p/1fps proxy with usable audio. That is still materially smaller than a 5 GB upload, and the implementation is much safer.

## Background

The current livestream pipeline accepts a full-resolution video upload, runs analysis, extracts wanted sections, and discards the rest. Moving from 720p recordings (~1.5 GB) to 1080p recordings (~5 GB) makes that increasingly painful:

- **Bandwidth**: the church upload connection is the bottleneck and large uploads are fragile.
- **Storage I/O**: the full source lands on the server even though much of it is temporary.
- **Operator experience**: long uploads increase the chance of aborted sessions and reduce confidence.

The original decision-input-first plan correctly spotted that analysis does not need the full 1080p source. However, it proposed shipping separate audio plus a JPEG frame archive. That creates a new media abstraction layer across several jobs and risks accuracy regressions, especially where the current server code expects a video path.

The safer approach is to make a compact video proxy that preserves the "server analyzes media" contract.

## Goal

Replace the monolithic 5 GB browser upload with a daemon-driven two-phase flow:

1. Laptop creates an analysis proxy video, for example 1280x720 at 1fps with AAC audio.
2. Laptop uploads the proxy to a daemon-specific Laravel endpoint.
3. Server stores the proxy as the analysis `source_file_path` and runs a daemon-analysis pipeline.
4. Server returns an upload manifest for required full-quality outputs.
5. Laptop trims the original 1080p recording locally using `ffmpeg`, preferring stream copy.
6. Laptop uploads full-quality preserved assets directly to the configured sermon disk, usually DigitalOcean Spaces.
7. Server finalizes the uploaded assets, creates or updates the sermon, prepares section publications, and runs the normal post-processing jobs.

The proxy should be treated as analysis-only. It must never become the public sermon video.

## Non-Goals

- Do not remove the existing browser livestream upload. It remains the manual fallback.
- Do not introduce real-time ingestion. The daemon is post-recording only.
- Do not move RMS, visual analysis, OCR, or song matching to the daemon.
- Do not rely on browser-based ffmpeg/WASM for 5 GB files.
- Do not assume the server has the full original recording after daemon analysis.

## Architecture Overview

```text
[Church laptop]                                      [Laravel server]

OBS recording -> drop folder
        |
        v
Daemon moves file to processing folder
        |
        v
Create analysis proxy video
        |
        v
Upload proxy ----------------------------> POST /api/livestream-daemon/analyses
                                            - Authenticates daemon token
                                            - Stores proxy as analysis source
                                            - Creates MediaProcessingLog
                                            - Dispatches daemon analysis pipeline

Poll status ------------------------------> GET /api/livestream-daemon/analyses/{id}
                                            - Returns analysis state
                                            - Returns upload requirements when ready

Create upload session --------------------> POST /api/livestream-daemon/analyses/{id}/upload-sessions
                                            - Creates scoped object keys
                                            - Creates/reuses multipart upload IDs
                                            - Returns presigned part URLs

Trim original 1080p locally
Upload kept assets directly to storage
        |
        v
Finalize --------------------------------> POST /api/livestream-daemon/analyses/{id}/finalize
                                            - Verifies expected assets exist
                                            - Records uploaded asset paths
                                            - Creates/updates sermon
                                            - Dispatches post-daemon chain

Archive original to NAS                    - Transcription, AI, thumbnails, quality checks,
                                             section publication, and notifications continue
```

## Key Decisions

### D1. Use a drop folder, not OBS auto-watch

The operator moves a recording to `D:\Crockenhill\drop\`. The daemon immediately moves it to `D:\Crockenhill\processing\<recording-id>\original.mkv`.

This keeps operator intent explicit, avoids accidentally processing rehearsals or weddings, and gives a simple recovery path: drop the file again. The installer must verify that the drop folder and OBS output folder are on the same Windows volume if atomic moves are expected.

### D2. Upload an analysis proxy video, not audio plus frames

The daemon should generate one compact video artifact:

```bash
ffmpeg -i original.mkv \
  -map 0:v:0 -map 0:a:0 \
  -vf "fps=1,scale=1280:-2" \
  -c:v libx264 -preset veryfast -crf 28 -pix_fmt yuv420p \
  -c:a aac -b:a 96k -ac 1 \
  -movflags +faststart \
  analysis-proxy.mp4
```

The exact CRF, frame rate, and audio bitrate must be tuned against real church recordings before rollout. The first measurement gate should compare the existing full-video pipeline with the proxy pipeline for segmentation boundaries, visual song clusters, OCR success, and song-opening transcription.

Why this is better than audio plus frames:

- It preserves the existing server assumption that analysis jobs receive a local video path.
- It keeps dense visual boundary refinement possible because a 1fps proxy still has frames at 1-second cadence.
- It avoids new frame-archive unpacking, timestamp lookup, and media-provider abstractions.
- It lets OCR and song-opening transcription keep using the same video-backed services.
- It is easier to reason about and easier to test end-to-end.

### D3. Store proxy size as `MediaProcessingLog.file_size`

For daemon runs, `MediaProcessingLog.source_file_path` should point to the proxy video and `file_size` should be the proxy size, not the original 5 GB size. Existing jobs such as `GenerateRmsLog` compare `file_size` to configured upload limits and would reject the run if `file_size` stored the original 1080p size.

Original recording metadata belongs in structured daemon metadata, for example:

```php
[
    'daemon' => [
        'recording_id' => '...',
        'source' => 'analysis_proxy',
        'original' => [
            'filename' => '...',
            'size' => 5368709120,
            'duration' => 7200.4,
            'sha256' => '...',
        ],
        'proxy' => [
            'path' => 'livestream-daemon/proxies/...',
            'size' => 480000000,
            'duration' => 7200.4,
            'sha256' => '...',
            'profile' => '720p-1fps-aac96',
        ],
    ],
]
```

### D4. Add a daemon analysis pipeline, but reuse existing analysis jobs

The daemon analysis pipeline should run against the proxy video:

1. `ValidateDaemonProxyFile`
2. Parallel phase: `GenerateRmsLog`, `PerformVisualAnalysis`
3. Chain phase: `AnalyzeSegments`, `ClassifyServiceSections`, `TranscribeSpeechSegments`, `ClassifySpeechSections`, `ProjectLivestreamServiceStructure`, `AlignWithOos`, `MatchSongsFromTranscript`, `ReclassifyIntroOutroSections`
4. New terminal analysis step: `MarkDaemonAnalysisReady`

Do not run `ExtractSermon`, `SubmitToProcessing`, or `PrepareSectionPublicationCandidates` before full-quality assets have arrived. Those jobs either assume a full source video or create public-facing records/assets from the current processing paths.

Implementation note: this can be a new `ProcessingRunOrchestrator::startDaemonAnalysis()` path rather than forcing `processingPipelineProfile()` to infer everything from `processing_type`.

### D5. Finalize is a first-class handoff, not a skipped `ExtractSermon`

The first plan said the post-daemon chain is "existing post-review minus `ExtractSermon`". That is not sufficient.

Current code has several source-video assumptions:

- `ExtractSermon` reads `source_file_path`.
- `SubmitToProcessing` creates the `Sermon` and stores the video.
- `PrepareSectionPublicationCandidates` extracts section media from `source_file_path`.
- The manual confirmation action checks that the original source video still exists.

Daemon finalization needs a dedicated action, for example `FinalizeDaemonLivestreamAssets`, that:

- Locks the processing log.
- Verifies the run is in `daemon_analysis_ready` or `daemon_uploading_assets`.
- Verifies each required object key is server-issued and exists on the configured disk.
- Verifies expected size, content type, and checksum metadata where storage supports it.
- Writes the sermon video and sermon audio paths to `MediaProcessingLog`.
- Writes section asset paths to `ServiceSection.extracted_video_path` and `ServiceSection.extracted_audio_path`.
- Creates or updates the `Sermon` using the same domain services as `SubmitToProcessing`, but without copying from a temp extraction path.
- Dispatches a daemon-specific post-finalize chain.

The post-finalize chain should start after canonical assets are recorded:

```text
CreateOrUpdateSermonFromDaemonAssets
EnhanceAudio
IdentifySpeaker
TranscribeAudio
ProcessTranscriptWithAI
AssessSermonVideoQuality
GenerateThumbnail
PrepareDaemonSectionPublicationCandidates
SendCompletionNotification
CleanupTemporaryFiles
```

`PrepareDaemonSectionPublicationCandidates` may share most logic with `PrepareSectionPublicationCandidates`, but it must not silently extract section media from the proxy. It should reuse daemon-provided full-quality assets or mark missing optional assets as warnings.

### D6. The server decides the upload manifest

The daemon should not decide which sections are important based only on raw segments. The server should return an explicit upload manifest once analysis is ready.

The manifest should include:

- The sermon extraction plan, including `single_span` or `concat_spans`.
- Required sermon assets, at minimum full-quality video and an audio asset suitable for transcription/publication.
- Optional section assets for songs, children's talks, or other publishable sections.
- Stable IDs for each asset, preferably `service_section_id` for section publications and a dedicated `sermon` asset ID for the sermon.
- Exact start/end spans to trim from the original recording.
- Whether each asset is required or optional.

This matters because `SermonExtractionPlanResolver` can choose enhanced plans such as bible-reading-plus-sermon or concatenated spans. The daemon must support multi-span trim/concat for those plans.

### D7. Manual review must not require the full original on the server

If sermon selection is ambiguous, the analysis pipeline can still enter manual review. However, the existing confirm action in `ConfirmLivestreamSermonSegment::ensureSourceVideoExists()` checks that `source_file_path` points to a full source video. For daemon runs, `source_file_path` is the proxy and the full original lives only on the laptop.

This is also a problem the project wants to solve independently of the daemon: keeping full originals server-side indefinitely is not viable on the available disk. The manual-review redesign therefore lives in **Phase 0b** as a hard prerequisite for Phase 1, scoped daemon-agnostically so both upload paths inherit the same review experience.

Requirements for the redesigned review flow:

- Reviewer works against an analysis or review proxy, not the full original.
- Reviewer can scrub, zoom on candidate boundaries, and confirm or adjust sermon span(s) without ever touching the full source.
- Confirmation updates `MediaProcessingLog` review metadata as today and rebuilds the upload manifest where applicable.
- The flow works equally for browser-uploaded runs (where the server can generate a proxy from the uploaded video before review) and daemon runs (where the daemon has already supplied a proxy).
- The daemon polls until the rebuilt manifest is ready, then resumes its normal flow.

See "Phase 0b" below for scope and exit criteria.

### D8. Use structured upload sessions, not ad-hoc JSON only

Direct-to-S3 multipart upload is stateful enough to deserve explicit server-side records. Avoid hiding the whole contract inside `MediaProcessingLog.processing_metadata`.

Recommended tables:

- `livestream_daemon_upload_sessions`: processing log, daemon recording ID, status, expiry, original/proxy metadata, last heartbeat.
- `livestream_daemon_upload_assets`: session, asset ID, service section ID, asset type, required flag, storage disk, storage key, multipart upload ID, expected size, uploaded size, checksum, status.

Metadata on `MediaProcessingLog` can still hold a compact summary for UI/status responses, but upload lifecycle should be queryable and testable.

### D9. Presigned multipart URLs should be idempotent and scoped

Do not generate new multipart uploads on every status poll. Use a separate upload-session endpoint:

```text
POST /api/livestream-daemon/analyses/{processingId}/upload-sessions
```

If an active session exists, return it. If it expired or was aborted, explicitly create a replacement. The server must generate all object keys and enforce a prefix such as:

```text
livestream-daemon/{processing_id}/{asset_id}/video.mp4
livestream-daemon/{processing_id}/{asset_id}/audio.mp3
```

The daemon should never submit arbitrary storage keys.

When the configured sermon disk is not S3-compatible, for example local development, the same upload-session contract should fall back to daemon uploads through Laravel endpoints. Production can use presigned multipart uploads; tests and local development should not need real Spaces credentials.

### D10. Security model: daemon-specific, least privilege

Use a dedicated Sanctum ability such as `media:daemon`, not the broader `media:process` ability. Apply it to all daemon endpoints.

Security requirements:

- HTTPS only.
- Dedicated daemon token stored in Windows Credential Manager or another protected store, not a plaintext config file.
- Rotateable token with a device label.
- Separate rate limiters for analysis upload, status polling, upload-session creation, finalization, and heartbeat.
- Idempotency key on `analyze`, based on the daemon recording ID.
- Server-generated upload keys only.
- Strict MIME/extension/probe validation for the proxy.
- Strict object HEAD checks during finalize.
- Safe error messages in API responses, detailed errors only in logs.

Soft identity checks are still useful, but they are not security. Filename, size, duration, and original SHA-256 catch daemon or operator mistakes; they do not prove trust by themselves.

### D11. Partial success uses a dedicated `CompletedWithWarnings` status

Add a new `CompletedWithWarnings` case to the `ProcessingStatus` enum rather than overloading `is_degraded_completion`. The existing flag was introduced for degraded extraction; conflating it with "daemon optional asset missing" muddies dashboards, completion emails, and reporting later. A dedicated status separates these concerns and is cheap to add.

Rules:

- If required sermon assets are missing, finalization fails outright.
- If optional section assets (songs, children's talk, etc.) are missing, the run completes with status `CompletedWithWarnings`.
- Store the warning detail in `processing_metadata` and surface it in the completion email and admin UI.
- Mark affected `ServiceSection` records as not applicable or needing review, depending on the handler.
- Update any code that branches on `ProcessingStatus::Completed` to also handle `CompletedWithWarnings` (admin UI badges, completion notifications, polling clients, exports).

Partial publication is useful, but the sermon itself should always be treated as required.

### D12. Keep originals locally, with an explicit archive policy

The daemon should archive the full original to a NAS path after successful finalization. The plan must be honest that the server will not have the full original unless we add a background archival upload.

Recommended policy:

- Required: NAS archive path with local retention.
- Required: operator-visible alert if archive copy fails.
- Required: if the NAS is unreachable, the daemon retains the original on the laptop in a `pending-archive/` folder, alerts the operator, and retries the archive copy on each daemon start and on a periodic schedule. The processing run is still considered `done` from the server's perspective; archival is a daemon-local concern that must not block sermon publication or subsequent recordings.
- Recommended: NAS backups are covered by existing off-site backup.
- Optional later: overnight low-priority cloud upload of the full original for reprocessing.

The Phase 4 state machine reflects this: `archiving_original` succeeds to `done`, or on NAS failure transitions to a daemon-local `archive_pending_local` retry loop while the run itself is reported complete to the server.

### D13. YouTube fallback uses the same drop-folder flow, with known-degraded analysis

If the local OBS recording is unavailable, download the YouTube version with `yt-dlp` and drop it into the same folder. The daemon treats it as just another source recording.

This is a fallback, not the primary recording path. YouTube re-encoding measurably degrades audio quality (affecting `MatchSongsFromTranscript` accuracy) and projector/lyric clarity (affecting visual song clustering and OCR). Treat YouTube-sourced runs as known-degraded:

- Tag the proxy upload with a `source: 'youtube_fallback'` flag in daemon metadata so the server can distinguish these runs.
- Surface the flag in the manual-review UI so reviewers know not to trust borderline song matches automatically.
- Retuning analysis thresholds specifically for YouTube-sourced inputs is out of scope for the initial daemon rollout. Track it as a known limitation in the operator runbook and revisit once enough YouTube fallback runs have accumulated to measure the actual accuracy hit.

### D14. Keep daemon code in this repository as a top-level subproject

The daemon should live in this repo, but outside the Laravel application tree:

```text
livestream-daemon/
  pyproject.toml
  src/crockenhill_livestream_daemon/
  tests/
  packaging/windows/
    install-service.ps1
    uninstall-service.ps1
    nssm/
```

Do not put daemon code in `app/`, because it is not Laravel application code. Do not hide it in `scripts/`, because it is a long-running product component with its own tests, packaging, releases, and operational state. A separate repository is unnecessary unless the daemon needs independent versioning or reuse outside this project.

Keeping it in the monorepo lets one pull request update the Laravel API contract, daemon client, fixtures, and documentation together.

## API Contract

### `POST /api/livestream-daemon/analyses`

Multipart form upload:

- `proxy_video`: required MP4 analysis proxy.
- `daemon_recording_id`: required idempotency key.
- `original_filename`: required.
- `original_size`: required integer.
- `original_duration`: required float.
- `original_sha256`: recommended.
- `proxy_sha256`: recommended.
- `proxy_profile`: required string, for example `720p-1fps-aac96`.

Response:

- `processing_id`
- `status_url`
- `message`

### `GET /api/livestream-daemon/analyses/{processingId}`

Returns:

- Current status and current step.
- Analysis progress.
- Whether manual review is required.
- Upload manifest when analysis is ready.
- Upload session summary if one exists.
- Warnings or blocking errors.

### `POST /api/livestream-daemon/analyses/{processingId}/upload-sessions`

Creates or reuses a scoped upload session for the current manifest.

Returns:

- Asset list.
- Storage keys.
- Multipart upload IDs.
- Presigned part URLs or instructions for requesting part URL batches.
- Expiry timestamps.

### `POST /api/livestream-daemon/analyses/{processingId}/finalize`

Request:

- `daemon_recording_id`
- Original metadata repeated for soft identity check.
- Upload session ID.
- Per-asset completed multipart parts.
- Per-asset file size and checksum metadata.

Response:

- Accepted status.
- Final processing status URL.
- Any warnings about optional missing assets.

### `POST /api/livestream-daemon/heartbeat`

Records daemon health, current phase, version, and last recording ID. This supports daemon-down and no-recording alerts.

## Test Strategy

### Measurement gate before implementation

Before building the full daemon, run a local spike on at least two real recordings:

- Full source through current server analysis.
- Proxy source through current server analysis.
- Compare RMS boundaries, service sections, song clusters, OCR matches, and song-opening transcription.

If proxy accuracy is poor, tune proxy settings before building API and upload-session machinery. Do not compensate by reviving the frame-archive design unless measurement proves the proxy cannot work.

### Automated test layers

| Layer | Purpose | Fixtures |
|---|---|---|
| Unit | Pipeline definitions, manifest generation, upload-session state, finalization validation | Model factories and fake disks |
| Feature | Daemon API contract, auth, idempotency, upload-session reuse, finalize behavior | Small generated proxy MP4 fixtures |
| Job/service | Analysis pipeline integration where existing jobs can be mocked | Fake `VideoSegmentationService`, fake storage, queued jobs |
| Manual exploratory | Real daemon on Windows against real recordings | Gitignored local recordings |

### Fixture approach

The old audio/frame fixture plan should be replaced with generated tiny proxy videos. Keep tests fast, but use fixtures that exercise the same file shape as production: an MP4 with video and audio.

Recommended fixtures:

1. `happy-path-proxy.mp4`: predictable audio and visual sections.
2. `ambiguous-sermon-proxy.mp4`: triggers manual review.
3. `no-sermon-proxy.mp4`: analysis completes but cannot produce a required sermon asset.
4. `bad-proxy.txt`: extension spoofing should fail validation.
5. `truncated-proxy.mp4`: ffprobe or ffmpeg validation should fail.

Use fake S3 disks for upload-session/finalize tests. Do not require real DigitalOcean Spaces in CI.

## Implementation Plan

### Phase 0 - Proxy measurement spike

Priority: Critical.

Tasks:

- Generate proxy videos from two or more real recordings.
- Run existing analysis jobs against full source and proxy source.
- Compare segment boundaries, song cluster boundaries, OCR results, song matches, and transcription quality.
- Pick default proxy settings and document expected proxy size.

Exit criteria:

- Proxy analysis quality is close enough to full-source analysis for Sunday use.
- Any known accuracy tradeoffs are explicit.

### Phase 0b - Manual review without server-side original

Priority: Critical (hard prerequisite for Phase 1).

Motivation: keeping full originals server-side indefinitely is not viable on the available disk, and the existing manual-review flow in `ConfirmLivestreamSermonSegment` requires the full source video. This phase redesigns review to operate against a proxy. It solves the disk-space problem for both upload paths and unblocks daemon runs (where the server has only the proxy). It is intentionally scoped daemon-agnostically so Phase 1 inherits the result without daemon-specific review code.

Target areas:

- `app/Actions/ConfirmLivestreamSermonSegment.php` — drop the `ensureSourceVideoExists()` precondition; treat the proxy as the canonical review surface.
- New review-proxy generation step for browser-uploaded runs (server-side `ffmpeg`) so browser-path runs reach review with a proxy ready.
- Manual-review UI (Livewire component) — proxy player with scrub, candidate-boundary zoom, confirm/adjust controls.
- `MediaProcessingRunTransitionService::confirmSermonSegment()` — manifest rebuild path that does not assume a full source video is on disk.
- Cleanup policy for full sources after analysis: configurable retention window so full originals are deleted server-side once review is no longer the gating step.

Tasks:

- Define the review proxy profile. The daemon's analysis proxy may be sufficient; if 1fps scrubbing proves too coarse for reviewers, define a slightly higher-fps "review proxy" variant.
- For browser runs, add a server-side proxy generation step before manual review can begin.
- Update the manual-review UI to load the proxy, not the full source.
- Allow review confirmation to operate purely on proxy timestamps, with the full source (if still present) trimmed afterwards as today.
- Update existing manual-review feature tests to use proxies; add tests for "full source already deleted" cases.
- Add a cleanup job (or extend `CleanupTemporaryFiles`) to delete retained full sources once a configurable retention window expires.

Exit criteria:

- An admin can complete manual review entirely from the proxy, with no server-side full source required.
- Browser-uploaded livestream runs continue to work end-to-end through review.
- The flow is daemon-agnostic; Phase 1 inherits review behavior with no daemon-specific code in the review path.
- Disk usage of pending review runs drops to proxy-sized.

### Phase 1 - Server daemon analysis pipeline

Priority: Critical.

Target areas:

- `routes/api.php`
- `app/Enums/ApiTokenAbility.php`
- `app/Enums/ProcessingStatus.php` — add `CompletedWithWarnings` case (per D11) and update consumers that branch on `Completed`.
- `app/Http/Controllers/Api/LivestreamDaemonController.php`
- New daemon form requests.
- `app/Services/ProcessingPipelineBuilder.php`
- `app/Services/ProcessingRunOrchestrator.php`
- `app/Services/ProcessingPhaseRegistry.php`
- New `ValidateDaemonProxyFile` and `MarkDaemonAnalysisReady` jobs.

Tasks:

- Add `media:daemon` token ability and authorization tests.
- Add daemon analyze/status/heartbeat routes.
- Store uploaded proxy on the temp disk and create `MediaProcessingLog` with `source_file_path` pointing to the proxy.
- Store original recording metadata separately from `file_size`.
- Add daemon analysis pipeline using existing analysis jobs.
- Add phase/progress mappings for daemon analysis.
- Add idempotency by `daemon_recording_id`.
- Add feature tests for auth, validation, idempotency, and dispatch.

Exit criteria:

- Uploading a valid proxy dispatches analysis.
- Existing full livestream upload still works.
- Daemon analysis reaches `daemon_analysis_ready` without requiring the full original.

### Phase 2 - Upload manifest and session model

Priority: Critical.

Target areas:

- New upload-session migrations and models.
- Manifest builder service.
- S3-compatible multipart upload service.
- Daemon upload-session endpoint.

Tasks:

- Build upload manifest from service sections and sermon extraction plan.
- Represent required and optional assets explicitly.
- Support `single_span` and `concat_spans`.
- Create/reuse upload sessions idempotently.
- Generate scoped storage keys server-side.
- Generate presigned multipart upload instructions.
- Provide a Laravel-mediated upload fallback for non-S3 local/dev disks.
- Add expiry/abort handling.
- Add feature tests using fake disks and mocked S3 client behavior.

Exit criteria:

- The daemon can retrieve a stable manifest and upload-session instructions after analysis.
- Repeated polling does not create duplicate multipart uploads.

### Phase 3 - Finalization and post-daemon processing

Priority: Critical.

Target areas:

- `FinalizeDaemonLivestreamAssets` action.
- `CreateOrUpdateSermonFromDaemonAssets` job or service.
- `PrepareDaemonSectionPublicationCandidates` job or refactor of existing section publication preparation.
- Completion notification data.

Tasks:

- Validate all required uploaded assets.
- Record sermon video/audio paths on `MediaProcessingLog`.
- Create/update `Sermon` without relying on `SubmitToProcessing` copying an extracted temp video.
- Record section asset paths and extraction provenance.
- Dispatch post-daemon chain.
- Use `ProcessingStatus::CompletedWithWarnings` plus structured warnings in `processing_metadata` for optional missing assets (per D11).
- Plug daemon runs into the Phase 0b review flow; daemon-specific confirmation reduces to "rebuild manifest from proxy-based review output and resume polling."
- Add tests for happy path, missing required sermon asset, missing optional section asset, and manual review continuation.

Exit criteria:

- Finalize creates a sermon and dispatches downstream processing.
- Optional asset failures are visible but do not block sermon publication.
- Required asset failures block finalization safely.

### Phase 4 - Windows daemon

Priority: Critical.

Implementation:

- Location: `livestream-daemon/`.
- Language: Python 3.x.
- File watcher: `watchdog`.
- HTTP: `requests`.
- Storage upload: `boto3` or presigned-url HTTP client, depending on the final server contract.
- Hosting: NSSM-wrapped Windows service.
- FFmpeg: bundled with the daemon, not assumed on `PATH`.
- State: one JSON state file per recording under `%APPDATA%\CrockenhillDaemon\state\`.

Tasks:

- Drop-folder watcher with atomic move to processing folder.
- Proxy generation.
- Analyze upload with retry and idempotency.
- Polling with backoff.
- Manual-review waiting state.
- Full-quality trim using stream copy, with duration/playability validation.
- High-quality re-encode fallback only when stream copy fails.
- Multi-span concat support.
- Multipart/resumable upload.
- Finalize call.
- NAS archive copy with local retention on failure: on NAS unreachable, retain the original in a `pending-archive/` folder, alert the operator, and retry on each daemon start and on a periodic schedule. The server-side run is reported `done` regardless.
- Failed-run folder with `error.txt`.
- Heartbeat every 5 minutes.
- Operator log with rotation.

State machine:

```text
new
-> moving_to_processing
-> creating_proxy
-> uploading_proxy
-> awaiting_analysis
-> awaiting_manual_review
-> creating_upload_session
-> trimming_assets
-> uploading_assets
-> finalizing
-> archiving_original
-> done

If the NAS archive copy fails (NAS unreachable):
archiving_original -> archive_pending_local
The server-side run is `done`. The daemon retains the original in `pending-archive/`,
alerts the operator, and retries the archive copy on each daemon start and periodically.
Subsequent recordings are not blocked.
```

Exit criteria:

- Forced daemon restart resumes from persisted state.
- A real recording can process end-to-end without opening the browser uploader.
- A failed upload can resume without reprocessing the proxy or duplicating server records.

### Phase 5 - Operations

Priority: Medium.

Tasks:

- Sunday status email: OK, warning, or failure.
- Daemon heartbeat freshness alert.
- Upload-session expiry cleanup.
- Proxy cleanup after successful finalization.
- Operator runbook for drop folder, failures, manual review, and YouTube fallback.

Exit criteria:

- Missing recording is reported by Sunday evening.
- Daemon-down condition is reported within the configured alert window.
- Temporary proxy and upload-session artifacts are cleaned safely.

## Open Questions

1. **Proxy settings**: What is the smallest proxy that preserves segmentation, OCR, and song matching for this church's camera/projector setup?
2. **Audio asset source**: Should the daemon upload sermon audio directly, or should the server extract public/transcription audio from the uploaded full-quality sermon video? Default recommendation: daemon uploads both video and audio so finalize has explicit canonical assets.
3. **Multipart implementation**: Does DigitalOcean Spaces in the current Flysystem/AWS SDK setup support the checksum features we want, or do we rely on size plus metadata plus optional server-side spot checks?
4. **Section publication scope**: Which non-sermon sections should be uploaded every week by default: songs, children's talk, both, or only those eligible for publication review?
5. **Manual review UX**: Resolved by Phase 0b — the existing admin manual-review UI is redesigned to operate against a proxy, daemon-agnostically. Daemon runs reuse the same screen. Open sub-question: is the daemon's 1fps analysis proxy sufficient for reviewer scrubbing, or should a higher-fps "review proxy" variant be defined?
6. **Original archive policy**: Is NAS plus off-site backup enough, or do we want an overnight low-priority full-original cloud archive later?

## Quality Gates

For implementation work, keep the usual project gates:

- Run focused tests for changed behavior.
- Run `vendor/bin/sail composer phpstan`.
- Run `vendor/bin/sail bin pint --dirty`.
- Run Dusk only if UI surfaces are added.

## Estimate

- Phase 0: 0.5-1 day.
- Phase 0b: 3-5 days.
- Phase 1: 2-3 days.
- Phase 2: 2-3 days.
- Phase 3: 2.5-4 days.
- Phase 4: 4-6 days.
- Phase 5: 0.5-1 day.

Total: roughly 14.5-23 working days. Phase 0b expands scope but solves an independent disk-space constraint that the project wants addressed regardless, and unblocks Phase 1 manual-review handling for both daemon and browser paths.

This is still a better plan than the audio-plus-frame-archive version. The proxy approach spends more bandwidth on analysis, but it preserves existing server behavior and avoids a risky media-source abstraction across half the livestream pipeline. The main complexity moves to the correct place: the daemon's upload/finalize contract and operational reliability.
