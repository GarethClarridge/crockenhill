# Media Upload Refactor Plan — Remaining Work

Updated 2026-04-10.

The original combined audit plus implementation history now lives in [docs/archived-plans/media-upload-combined-report-and-plan.md](../archived-plans/media-upload-combined-report-and-plan.md).

This active plan tracks only the refactor work that still appears necessary in the current codebase.

## Current Status

- Validation centralisation is effectively complete. `MediaValidationService` is the single canonical config-driven source, used by requests, Livewire, and job chains. One orphaned validation method remains in `MetadataExtractionService` as dead code.
- Cancellation/status normalisation is effectively complete.
- Startup orchestration reuse is partly done. `ProcessingInitiator` centralises video and livestream startup, but audio still enters through a separate inline path in `UnifiedMediaProcessor`.
- Storage/extraction decomposition is partly done. Helpers like `StorageAdapterHelper` and `AudioCompressionService` already exist; `VideoExtractionService` still mixes extraction, storage, and audio concerns.
- Contract simplification is complete. All surviving interfaces (`TranscriptionServiceInterface`, `SermonAnalysisInterface`, `SpeakerIdentificationInterface`, `SectionPublicationHandler`, `OosEmailItemExtractor`, `ProvidesSafeMessage`) have multiple implementations or genuine testing value.

## Remaining Work

### 1. Validation Dead-Code Cleanup

Objective:

- Remove the orphaned validation method that duplicates rules already centralised in `MediaValidationService`.

Status notes:

- `MediaValidationService` is the single canonical source, config-driven via `config('media-processing.types.{type}')`.
- `ValidateAudioFile` job delegates to `AudioExtractionService`, which delegates to `MediaValidationService` — no duplication there.
- `MetadataExtractionService::validateAudioFile()` (lines 552-580) contains hard-coded 64 kbps and 100 MB limits but is not called by any active pipeline. It is dead code.

Tasks:

- [x] Delete `MetadataExtractionService::validateAudioFile()` and any tests that exercise it in isolation.
- [x] Verify no callers reference the method (grep confirms none in the active pipeline).

Exit criteria:

- No hard-coded validation limits exist outside `config/media-processing.php` and `MediaValidationService`.

### 2. Startup Orchestration Deduplication

Objective:

- Route audio processing startup through `ProcessingInitiator` so all media types share the same log-creation and metadata-bootstrap boundary.

Status notes:

- `ProcessingInitiator` already covers video and livestream startup (UUID, metadata extraction, log creation).
- Audio startup in `UnifiedMediaProcessor::processAudio()` (lines 142-206) duplicates UUID generation and `MediaProcessingLog::create()` inline.
- Audio metadata extraction genuinely differs from video: audio uses `extractId3Metadata()` while video uses `extractDateFromVideo()` + service detection. `ProcessingInitiator` will need to accept a flexible metadata source (callback, DTO, or optional override) rather than assuming video-style extraction.

Tasks:

- [x] Extend `ProcessingInitiator` to accept an optional pre-extracted metadata array or a metadata-extraction strategy, so audio can supply ID3 metadata while video/livestream continue using date-from-video extraction.
- [x] Refactor `UnifiedMediaProcessor::processAudio()` to use `ProcessingInitiator::initiateProcessing()` instead of inline log creation.
- [x] Preserve audio-specific file storage (the `storeAudioFile()` step happens before initiation, which differs from video's temp-store pattern).
- [x] Remove the inline UUID generation and `MediaProcessingLog::create()` from `processAudio()`.

Exit criteria:

- All three media types (`audio`, `video`, `livestream`) create their processing logs through `ProcessingInitiator`.
- Audio-specific metadata (ID3 tags) and file storage are preserved without duplication.

### 3. Video Extraction and Storage Boundary Cleanup

Objective:

- Reduce complexity in `VideoExtractionService` (651 lines, 14 methods) by splitting along its natural seams.

Status notes:

- `StorageAdapterHelper` already handles S3 upload retry, temp cleanup, and processing output paths.
- `AudioCompressionService` already handles audio extraction and compression.
- `VideoExtractionService` delegates to both helpers but still contains duplicate `fileExists()` and `getFileSize()` logic, and still owns FFmpeg extraction, storage path resolution, and audio extraction coordination in one class.

Tasks:

- [x] Delegate `fileExists()` and `getFileSize()` to `StorageAdapterHelper` (or a focused `StoragePathResolver`), removing the duplicate S3-aware logic from `VideoExtractionService`.
- [ ] Extract FFmpeg segment extraction methods (`extractSegmentAsFile`, `extractSegmentWithReencoding`, `extractConcatenatedSegmentAsFile`, `extractSegmentAsUpload`) into a focused `VideoSegmentExtractionService` that owns only transcoding coordination.
- [ ] Route audio extraction methods (`extractAudio`, `extractOptimizedAudio`) through `AudioCompressionService` directly where possible; deprecate or remove the `extractOptimizedAudioFromSegment()` wrapper if it adds no value.
- [ ] Keep FFmpeg behaviour and storage semantics unchanged while reducing responsibility density.

Exit criteria:

- `VideoExtractionService` is materially smaller and delegates clearly to focused collaborators.
- Storage/disk/path logic lives in `StorageAdapterHelper` (or equivalent), not in the extraction service.
- No behavioural drift — existing callers and tests continue to work.

## Explicitly Closed

- **Status and cancellation normalisation** — complete; no regressions.
- **Contract simplification** — complete. All six surviving interfaces in `app/Contracts/` have multiple implementations or genuine testing/substitution value. No ceremonial pass-throughs remain.
- **Broad "start over" decomposition** — not needed; remaining refactor builds on helpers already introduced.
- **Validation centralisation** — effectively complete once the dead-code cleanup (item 1) is done.

## Suggested Order

1. Validation dead-code cleanup (small, safe)
2. Startup orchestration deduplication (moderate, self-contained)
3. Video extraction and storage boundary cleanup (largest, benefits from items 1-2 being stable)

## Definition of Done

- [x] No orphaned validation methods with hard-coded limits remain.
- [x] Audio startup uses `ProcessingInitiator` alongside video and livestream.
- [ ] Video extraction/storage responsibilities are split at clear seams without behavioural drift. (FFmpeg segment extraction methods and audio extraction routing remain in `VideoExtractionService` — file/size delegation to `StorageAdapterHelper` done; FFmpeg decomposition deferred.)
- [x] Existing media upload tests still pass after each phase.
