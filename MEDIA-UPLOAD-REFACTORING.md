# Media Upload Refactoring Plan

Date: 2026-02-16
Sources: Claude Code analysis + Codex analysis, merged

---

## Current Architecture

The media upload system handles three upload types (audio, video, livestream) through two entry points:

- **API**: `MediaController` -> `UnifiedMediaProcessor`
- **Web**: `MediaUpload` Livewire component -> `UnifiedMediaProcessor`

Both converge at `UnifiedMediaProcessor`, which routes to type-specific services via a `match` expression. The backend comprises 47 services, 11 contracts, 18 jobs, and 12 DTOs.

### Processing Flow

```
HTTP Upload
    |
    v
UnifiedMediaProcessor::process($type, $file)
    |
    +-- 'audio' --> SermonProcessingService
    |                 --> SermonAudioProcessingService::processSermon()
    |                       Store file, extract ID3, dispatch audio pipeline
    |                       [Validate -> CreateSermon -> IdentifySpeaker
    |                        -> Transcribe -> AI Analysis -> Notify -> Cleanup]
    |
    +-- 'video' --> UnifiedMediaProcessor::processDirectVideo()
    |                 Extract metadata, store temp, dispatch video pipeline
    |                 [Validate -> ExtractAudio -> CreateSermon -> IdentifySpeaker
    |                  -> Transcribe -> AI Analysis -> Thumbnail -> Cleanup]
    |
    +-- 'livestream' --> VideoProcessingService (passthrough)
                           --> LivestreamSegmentationService::startProcessing()
                                 Extract metadata, validate storage, store temp,
                                 generate RMS, dispatch livestream pipeline
                                 [VisualAnalysis? -> RmsLog -> AnalyzeSegments
                                  -> ExtractSermon -> SubmitToProcessing
                                  -> IdentifySpeaker -> Transcribe -> AI Analysis
                                  -> Thumbnail -> Cleanup]
```

---

## Issues Found

### 1. Validation rules are scattered and inconsistent

**Priority: High**

File size limits differ depending on where you look:

| Location | Audio | Video | Livestream |
|---|---|---|---|
| `config/media-processing.php` | 100MB | 1GB | 2GB |
| `MediaController` (API) | config | config | config |
| `ProcessMediaRequest` | 2GB | 2GB | 2GB |
| `MediaUpload` Livewire | 100MB | 5GB | 5GB |
| Blade view text | - | "5GB" | "5GB" |

Validation rules exist in three separate places:
- `MediaController::upload()` (line 40) -- reads from config dynamically
- `ProcessMediaRequest::rules()` (line 25) -- hard-coded 2GB for all types
- `MediaUpload::getDynamicRules()` (line 240) -- partially config-driven, video/livestream hard-coded to 5GB

Allowed MIME types are also duplicated: config has canonical lists, but `ProcessMediaRequest` and `MediaUpload` hard-code their own.

### 2. No `CANCELLED` status in the enum

**Priority: High**

`ProcessingStatus` has four states: `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`. Cancellation is shoehorned into `FAILED`:

```php
// SermonProcessingService::cancelProcessing()
$processingLog->update([
    'status' => ProcessingStatus::FAILED,
    'error_message' => 'Processing cancelled by user',
    'current_step' => 'cancelled',
]);
```

Meanwhile `SermonProcessingStep` uses a raw `'cancelled'` string status (not the enum). The Livewire component tracks `uploadCancelled` as a separate boolean. This means:
- Reports can't distinguish user cancellations from real failures
- Retry logic can't differentiate "try again" from "user stopped this"
- The UI shows cancellations as errors

### 3. `VideoProcessingService` is a pure passthrough

**Priority: Medium**

All 62 lines are one-liner delegations to `LivestreamSegmentationService` or `LivestreamStatusService`. Its interface (`VideoProcessingServiceInterface`) carries misleading names -- `processLivestream()` and `processWithSegmentation()` do the same thing. This adds an unnecessary layer between `UnifiedMediaProcessor` and the actual services.

Note: while this is pure passthrough today, it could serve as a stable seam if livestream processing gains alternate implementations in future. The recommended approach is removal, but it can be deferred if preferred.

### 4. `VideoExtractionService` is a 943-line god class

**Priority: High**

Handles too many concerns:
- Audio extraction from video (FFmpeg commands)
- Audio compression/optimization (`extractOptimizedAudio` alone is 174 lines)
- S3 upload with exponential-backoff retry logic (65 lines)
- File validation and existence checks
- Diagnostic logging
- Local vs S3 filesystem detection

### 5. `isS3Disk()` / `isS3Path()` duplicated in 6 files

**Priority: Medium**

Identical S3 detection logic is copy-pasted across:
- `VideoExtractionService`
- `VideoSegmentationService`
- `VideoStorageService`
- `AudioTranscriptionService`
- `ValidateAudioFile` (job)
- `ExtractSermon` (job)

### 6. Video initialisation logic duplicated

**Priority: Medium**

Date/service extraction, processing log creation, and job chain dispatch are duplicated between:
- `UnifiedMediaProcessor::processDirectVideo()` (lines 156-227)
- `LivestreamSegmentationService::startProcessing()` (lines 32-117)

Both perform: extract date from video -> determine service from time/filename -> store temp file -> create `MediaProcessingLog` -> build pipeline -> dispatch chain.

### 7. `app()` service location used instead of constructor DI

**Priority: Medium**

22 `app()` calls across the codebase. The media-related ones that should use constructor injection:
- `UnifiedMediaProcessor` resolves `ProcessingLogService` via `app()` (line 67)
- `SermonAudioProcessingService` resolves `ProcessingPipelineBuilder` via `app()` (lines 67, 154)
- `LivestreamSegmentationService` resolves `ProcessingPipelineBuilder` via `app()` (line 189)
- `MediaUpload` Livewire resolves `UnifiedMediaProcessor` via `app()` (lines 313, 428, 473) -- partially justified since Livewire constructor DI has limitations

### 8. Single-implementation interfaces add unnecessary indirection

**Priority: Medium**

Six contracts have exactly one implementation and no mock/null variant:

| Contract | Sole Implementation |
|---|---|
| `VideoProcessingServiceInterface` | `VideoProcessingService` |
| `SermonProcessingServiceInterface` | `SermonProcessingService` |
| `VideoStorageServiceInterface` | `VideoStorageService` |
| `ProcessingHealthServiceInterface` | `ProcessingHealthService` |
| `SermonMetadataServiceInterface` | `SermonMetadataService` |
| `AudioExtractionServiceInterface` | `AudioExtractionService` |

Contracts that *are* justified (multiple implementations):
- `TranscriptionServiceInterface` -> `AudioTranscriptionService`, `MockTranscriptionService`
- `SermonAnalysisInterface` -> `SermonAnalysisService`, `MockSermonAnalysisService`
- `SpeakerIdentificationInterface` -> `ResemblyzerSpeakerIdentificationService`, `NullSpeakerIdentificationService`

Do not bulk-remove interfaces. Evaluate each for practical abstraction value. Remove only where indirection has no benefit and no near-term extension need.

### 9. Livewire `UploadedFile` reconstruction is fragile

**Priority: Low-Medium**

`MediaUpload::startProcessing()` manually reconstructs an `UploadedFile` from a temp file using a hand-rolled extension-to-MIME map and passes `true` (test mode) to the constructor. This bypasses PHP's native MIME detection and could cause unexpected behaviour.

### 10. Test weaknesses

**Priority: High**

- **Loose assertions**: Multiple tests accept ranges of status codes (`[202, 422, 429, 500]`), masking regressions
- **Skip-on-500 patterns**: Some tests skip when endpoint behaviour returns 500 instead of asserting correctness
- **Duplicated helpers**: `createMockSermonAnalysis()` is identical in `SermonProcessingJobChainTest` and `SermonProcessingErrorHandlingTest`
- **Duplicated mock setup**: `UnifiedMediaProcessor` mocking is repeated identically in 3+ test files
- **Coverage gaps**: Cancel endpoint lacks authenticated success/failure tests; Livewire `checkProcessingStatus` and `cancelProcessing` paths are untested
- **Conditional skips**: Some tests use `markTestSkipped()` when services aren't available rather than mocking

---

## Test Coverage Assessment

### What is covered well

- Core routing/orchestration in `UnifiedMediaProcessorTest`
- Basic Livewire upload behaviour and auto-submit flows
- Security-focused API tests for upload/status/retry endpoints
- Job chain progression and failure handling
- Speaker identification gating logic and operational modes

### Where coverage is weak or low-trust

- Feature tests accept broad status ranges (`[202, 422, 429, 500]`), reducing regression sensitivity
- Some tests skip on 500 responses instead of asserting expected behaviour
- Missing direct, strict feature coverage for authenticated cancel success/failure flows
- Limited direct tests for Livewire processing polling and cancel-processing behaviour
- Web POST upload path has minimal coverage

Coverage breadth is good, but assertion quality needs improvement to support safe refactoring.

---

## Refactoring Plan

### Phase 0: Test Stabilisation Baseline

**Goal**: Make the test suite deterministic enough to catch regressions during refactoring.

This phase must complete before any production code changes.

**Actions**:

1. Create a `MediaProcessingTestHelpers` trait with shared helpers:
   - `createMockSermonAnalysis()` (currently duplicated in `SermonProcessingJobChainTest` and `SermonProcessingErrorHandlingTest`)
   - `mockUnifiedMediaProcessor()` (currently duplicated in 3+ test files)
   - `createProcessingLog()` factory helper

2. Tighten loose status code assertions:
   - Add `withoutMiddleware(ThrottleRequests::class)` in tests that assert specific status codes, so rate limiting doesn't cause false alternatives
   - Replace `assertContains($response->status(), [202, 422, 429])` with exact expected codes

3. Remove `markTestSkipped()` calls -- mock all external dependencies instead of skipping when services aren't available.

4. Add missing coverage:
   - Cancel endpoint: authenticated success and failure paths
   - Livewire: `checkProcessingStatus()` and `cancelProcessing()` component tests
   - Web POST upload path (`/church/members/sermon-upload`)

**Acceptance**: All media upload tests pass with strict, single-status-code assertions. No skip-on-500 patterns remain. Shared helpers eliminate mock setup duplication.

**Files**: ~8 test files + 1 new trait

---

### Phase 1: Centralise Validation

**Goal**: Single source of truth for file type and size limits.

**Actions**:

1. Ensure `config/media-processing.php` has canonical definitions for each type:
   - `types.audio.max_file_size`, `types.audio.allowed_extensions`, `types.audio.allowed_mimes`
   - `types.video.max_file_size`, `types.video.allowed_extensions`, `types.video.allowed_mimes`
   - `types.livestream.max_file_size`, `types.livestream.allowed_extensions`, `types.livestream.allowed_mimes`

2. Create a `MediaValidationService` with:
   ```php
   public function rulesForType(string $type): array
   // Returns Laravel validation rules array, e.g.:
   // ['file' => 'required|file|mimes:mp4,mov|max:1048576']

   public function maxFileSizeForDisplay(string $type): string
   // Returns human-readable size, e.g.: "1GB"

   public function allowedExtensionsForDisplay(string $type): string
   // Returns comma-separated list, e.g.: "mp4, mov, avi, mkv"
   ```

3. Update all consumers to delegate to this service:
   - `MediaController::upload()` -- replace inline rule building (line 40)
   - `ProcessMediaRequest::rules()` -- delegate to service (line 25)
   - `MediaUpload::getDynamicRules()` -- delegate to service (line 240)
   - `media-upload.blade.php` -- use component properties fed from service (line 203)

4. Write tests verifying all entry points enforce identical limits from config.

**Acceptance**: Audio/video/livestream limits and allowed types are consistent across API, web POST, and Livewire. One change point for future limit updates.

**Files**: ~5 files (config, new service, controller, form request, Livewire component + blade)

---

### Phase 2: Status and Cancellation Normalisation

**Goal**: Distinguish user cancellations from processing failures across the full stack.

**Actions**:

1. Add `CANCELLED = 'cancelled'` case to `ProcessingStatus` enum:
   - Add `isCancelled(): bool` method
   - Update `label()` to return `'Cancelled'`
   - Ensure `isFailed()` returns `false` for cancelled
   - Ensure `isInProgress()` returns `false` for cancelled

2. Update cancel implementations to use the new status:
   - `SermonProcessingService::cancelProcessing()` -- use `ProcessingStatus::CANCELLED` instead of `FAILED`
   - `LivestreamSegmentationService::cancelProcessing()` -- same change

3. Update `SermonProcessingStep` to use the enum value instead of raw `'cancelled'` string.

4. Update `StandardProcessingResponse::calculateProgress()` to handle the cancelled state (return 0 or the progress at time of cancellation).

5. Update `MediaUpload` Livewire component to recognise and display the cancelled state distinctly from failures.

6. Update retry logic: decide whether cancelled items can be retried (likely yes -- they should be treated like a user-initiated reset).

7. Update affected tests to assert `CANCELLED` status where cancellation occurs.

**Acceptance**: Cancel behaviour is consistent in database, API status responses, and Livewire UI. Cancelled items are visually distinct from failed items. Retry/cancel transitions are deterministic.

**Files**: ~8 files (enum, 2 services, processing step model, response DTO, Livewire component, blade view, tests)

---

### Phase 3: Orchestration Deduplication and DI Cleanup

**Goal**: Remove duplicated video/livestream startup logic and replace `app()` service location with constructor injection.

**Actions**:

1. Create a `ProcessingInitiator` service with:
   ```php
   public function initiateProcessing(
       UploadedFile $file,
       string $processingType,
       ?string $clientFileDate = null
   ): MediaProcessingLog
   ```
   This handles: metadata extraction -> service detection -> temp storage -> `MediaProcessingLog` creation.

2. Refactor `UnifiedMediaProcessor::processDirectVideo()` to use `ProcessingInitiator`. It retains responsibility for building the video-specific pipeline and dispatching the chain.

3. Refactor `LivestreamSegmentationService::startProcessing()` to use `ProcessingInitiator`. It retains responsibility for the livestream-specific pipeline.

4. Replace `app()` calls with constructor injection in:
   - `UnifiedMediaProcessor` -- inject `ProcessingLogService` (line 67)
   - `SermonAudioProcessingService` -- inject `ProcessingPipelineBuilder` (lines 67, 154)
   - `LivestreamSegmentationService` -- inject `ProcessingPipelineBuilder` (line 189)

5. For `MediaUpload` Livewire: consolidate the three `app(UnifiedMediaProcessor::class)` calls (lines 313, 428, 473) into a single `getProcessor(): UnifiedMediaProcessor` helper method. Keep `app()` here since Livewire constructor DI has limitations.

6. Evaluate `VideoProcessingService` passthrough layer:
   - If removing: update `UnifiedMediaProcessor` to inject `LivestreamSegmentationService` and `LivestreamStatusService` directly. Remove `VideoProcessingService.php`, `VideoProcessingServiceInterface.php`, and their service provider binding.
   - If keeping: rename to clarify its role (e.g. `LivestreamProcessingFacade`) and remove the duplicate `processLivestream()` method that duplicates `processWithSegmentation()`.

7. Update all affected tests.

**Acceptance**: Duplicated startup logic reduced to one shared path. No `app()` calls in services that can use constructor injection. No behaviour change in existing integration tests.

**Files**: ~10 files (new service, 2 refactored services, processor, Livewire component, potentially removed passthrough + interface, tests)

---

### Phase 4: Service Decomposition (Storage and Extraction)

**Goal**: Break up the `VideoExtractionService` god class and centralise S3 detection.

**Actions**:

1. Create a `DetectsStorageType` trait with:
   ```php
   protected function isS3Disk(?string $disk = null): bool
   protected function isS3Path(string $path): bool
   ```

2. Apply the trait to the 6 files that duplicate this logic and remove their private implementations:
   - `VideoExtractionService`
   - `VideoSegmentationService`
   - `VideoStorageService`
   - `AudioTranscriptionService`
   - `ValidateAudioFile` job
   - `ExtractSermon` job

3. Write a dedicated unit test for the trait.

4. Extract `AudioCompressionService` from `VideoExtractionService`:
   - Move `extractOptimizedAudio()` and its helpers (~200 lines)
   - Responsible for: audio extraction with compression, quality validation, fallback logic

5. Move S3 upload-with-retry logic (~65 lines) into `VideoStorageService` (which already handles storage), or into a standalone `S3RetryUploader` utility.

6. Slim `VideoExtractionService` to FFmpeg extraction concerns only (~300 lines):
   - `extractSegment()`
   - `extractSegmentAsFile()`
   - `extractSegmentAsUpload()`
   - `extractAudio()` (basic, no compression)

7. Update `VideoStorageService` to inject `AudioCompressionService` where it currently delegates to `VideoExtractionService::extractOptimizedAudio()`.

8. Fix Livewire `UploadedFile` reconstruction:
   - Replace hand-rolled extension-to-MIME map in `MediaUpload::startProcessing()` with `mime_content_type()` or `finfo_file()`
   - Remove or document the `true` (test mode) flag on the `UploadedFile` constructor

9. Update all affected tests.

**Acceptance**: `VideoExtractionService` reduced from 943 to ~300 lines. S3 detection logic exists in one place. Audio compression is independently testable. Livewire MIME detection uses native PHP.

**Files**: ~10 files (new trait, new service, refactored extraction/storage services, 6 trait consumers, Livewire component, tests)

---

### Phase 5: Interface Simplification

**Goal**: Remove unnecessary indirection without losing useful abstraction seams.

**Actions**:

1. Remove interfaces that have a single implementation, no mock variant, and no near-term extension need:
   - `VideoStorageServiceInterface` -> type-hint `VideoStorageService` directly
   - `SermonProcessingServiceInterface` -> type-hint `SermonProcessingService` directly
   - `ProcessingHealthServiceInterface` -> type-hint `ProcessingHealthService` directly
   - `SermonMetadataServiceInterface` -> type-hint `SermonMetadataService` directly
   - `AudioExtractionServiceInterface` -> type-hint `AudioExtractionService` directly
   - `VideoProcessingServiceInterface` (if not already removed in Phase 3)

2. Remove the corresponding service provider bindings.

3. Update all type-hints in constructors, method signatures, and tests.

4. Keep interfaces that have multiple implementations:
   - `TranscriptionServiceInterface` (real + mock)
   - `SermonAnalysisInterface` (real + mock)
   - `SpeakerIdentificationInterface` (real + null)
   - `ProcessingStatusContract` (used polymorphically)

**Acceptance**: Fewer pass-through bindings with no loss of testability. Dependency graph easier to follow.

**Files**: ~12 files (removed interfaces, service provider, updated type-hints across services/jobs/tests)

---

## Risk and Rollout

### Primary risks

- **Behaviour drift in validation**: Changing validation sources could inadvertently reject previously-accepted uploads or accept previously-rejected ones.
- **Status transition breakage**: Adding `CANCELLED` to the enum could affect database queries that filter on status values.
- **Regression in queue/job processing**: Changing service wiring or DI could break job chains in ways that only surface during actual processing.

### Mitigation

- **Phase 0 first**: Stabilise tests before touching production code. Every subsequent phase relies on the test suite catching regressions.
- **Small commits per phase**: Each phase is independently committable and deployable. Ship one phase, verify in production, then proceed.
- **Behaviour-preserving first**: Phases 0-2 change configuration and semantics but not processing logic. The riskier structural changes (Phases 3-4) come after a stable baseline.
- **Database migration for CANCELLED**: If `ProcessingStatus` is stored as a string enum in the database, ensure the migration allows the new value before deploying the code that writes it.

---

## Summary

| Phase | Change | Value | Risk | Est. Scope |
|---|---|---|---|---|
| 0 | Test stabilisation | High | Low | ~8 test files + 1 trait |
| 1 | Centralise validation | High | Low | ~5 files |
| 2 | Status/cancellation normalisation | High | Low | ~8 files |
| 3 | Orchestration dedup + DI + passthrough | High | Medium | ~10 files |
| 4 | Service decomposition + S3 trait + Livewire fix | High | Medium | ~10 files |
| 5 | Interface simplification | Medium | Low | ~12 files |

## Definition of Done

- Validation is centralised and consistent across all entry points
- Cancellation semantics are coherent across database, API responses, and UI
- No duplicated startup logic between video and livestream paths
- No `app()` service location in classes that support constructor injection
- `VideoExtractionService` reduced to a focused extraction service
- S3 detection exists in one place
- Single-implementation interfaces removed where they add no value
- Media upload tests are strict, deterministic, and pass consistently
- All phases committed independently with passing test suite
