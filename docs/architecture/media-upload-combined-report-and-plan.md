# Media Upload: Combined Report and Refactor Plan

Date: 2026-02-16  
Author: Codex (combined analysis: Claude report + local codebase verification)

## Scope

This report covers the unified media upload flow across:

- API upload/status/retry/cancel endpoints
- Web upload path (admin POST)
- Livewire upload UI
- Unified media orchestration and type-specific processing services
- Test coverage and reliability

Primary files reviewed include:

- `app/Http/Controllers/Api/MediaController.php`
- `app/Http/Controllers/Admin/SermonAdminController.php`
- `app/Livewire/MediaUpload.php`
- `resources/views/livewire/media-upload.blade.php`
- `app/Services/UnifiedMediaProcessor.php`
- `app/Services/SermonAudioProcessingService.php`
- `app/Services/VideoProcessingService.php`
- `app/Services/LivestreamSegmentationService.php`
- `app/Services/VideoExtractionService.php`
- `config/media-processing.php`
- Media upload and processing tests in `tests/Feature` and `tests/Unit/Services`

## Executive Summary

The Claude report is largely correct and useful. The highest-value simplifications are:

1. Unify validation rules and limits across API, Livewire, and web POST path.
2. Normalize status semantics (especially cancellation) between UI and backend.
3. Reduce duplicated orchestration logic in video/livestream startup paths.
4. Improve test strictness and determinism (many tests currently allow broad status ranges).

Secondary structural work should follow after those first wins:

1. Break up `VideoExtractionService` into smaller focused services.
2. Consolidate repeated S3 detection logic.
3. Revisit interface/service layering to reduce unnecessary indirection.

## Consolidated Findings (Claude + Verification)

Legend:

- `Confirmed`: agreed and validated directly in code
- `Partially Confirmed`: valid issue, but recommended action needs moderation
- `Resolved`: already addressed in current branch/worktree

### 1) Validation is scattered and inconsistent

Status: `Confirmed`  
Priority: `High`

Evidence:

- Config limits: `config/media-processing.php`
- API validation: `app/Http/Controllers/Api/MediaController.php`
- Web POST validation: `app/Http/Requests/ProcessMediaRequest.php`
- Livewire validation and UI text: `app/Livewire/MediaUpload.php`, `resources/views/livewire/media-upload.blade.php`

Impact:

- Different limits by entry point can produce user confusion and production bugs.
- Rule changes must be made in multiple locations, increasing drift risk.

### 2) `VideoProcessingService` is mostly passthrough

Status: `Partially Confirmed`  
Priority: `Medium`

Evidence:

- `app/Services/VideoProcessingService.php` delegates almost all methods.

Notes:

- The diagnosis is accurate.
- Immediate deletion is optional; this class can still serve as a stable seam.
- Recommended approach: either slim/rename it to a clear facade role, or remove in a controlled phase after call sites and contracts are simplified.

### 3) `VideoExtractionService` is too large and multi-responsibility

Status: `Confirmed`  
Priority: `High`

Evidence:

- `app/Services/VideoExtractionService.php` is very large and contains extraction, compression, storage path behavior, and environment-specific branching.

Impact:

- Difficult to reason about, test, and safely modify.

### 4) S3/local detection logic is duplicated

Status: `Confirmed`  
Priority: `Medium`

Evidence:

- Repeated `isS3Disk`/`isS3Path`/compatibility checks appear in multiple services/jobs, including:
  - `app/Services/VideoExtractionService.php`
  - `app/Services/VideoStorageService.php`
  - `app/Services/AudioTranscriptionService.php`
  - `app/Services/VideoSegmentationService.php`
  - `app/Jobs/ValidateAudioFile.php`
  - `app/Jobs/ExtractSermon.php`

Impact:

- Behavior drift risk; fixes must be duplicated.

### 5) Metadata extraction orchestration is duplicated

Status: `Confirmed`  
Priority: `Medium`

Evidence:

- Similar date/service extraction and setup in:
  - `app/Services/UnifiedMediaProcessor.php` (`processDirectVideo`)
  - `app/Services/LivestreamSegmentationService.php` (`startProcessing`)

Impact:

- Increases bug surface for service/date inference logic.

### 6) Some interfaces may be unnecessary

Status: `Partially Confirmed`  
Priority: `Medium`

Evidence:

- Several contracts currently have one concrete implementation.
- Counterexample: some are intentional seams (mock/null or future provider swapping).

Notes:

- Do not bulk-remove interfaces.
- Keep interfaces with clear abstraction value or alternate implementations.
- Remove only where indirection has no practical benefit.

### 7) Rate limiter cleanup completeness

Status: `Resolved`  
Priority: `N/A`

Evidence:

- Routes use unified limiters only:
  - `throttle:media-upload`
  - `throttle:media-retry`
- No remaining route references to removed legacy limiter names.

### 8) `processSermon()` and `processSermonAudio()` overlap

Status: `Confirmed`  
Priority: `Low-Medium`

Evidence:

- Shared patterns inside `app/Services/SermonAudioProcessingService.php`.

Impact:

- Similar orchestration split into two methods with different return shapes and context handling.

### 9) Livewire uploaded file reconstruction is fragile

Status: `Confirmed`  
Priority: `Medium`

Evidence:

- Manual MIME mapping + reconstructed `UploadedFile` with test-mode flag in:
  - `app/Livewire/MediaUpload.php`

Impact:

- Extension-based MIME decisions are brittle.
- Rehydration behavior may diverge from real upload characteristics.

### 10) Test duplication and permissive assertions

Status: `Confirmed`  
Priority: `High`

Evidence:

- Many feature tests accept broad status sets including server errors.
- Some tests skip when endpoint behavior returns 500 instead of asserting correctness.
- Similar mock setup repeated across multiple files.

Representative files:

- `tests/Feature/AutomatedSermonApiTest.php`
- `tests/Feature/AutomatedSermonApiSecurityTest.php`
- `tests/Feature/DirectSermonVideoUploadTest.php`
- `tests/Feature/ProcessingLogsApiTest.php`

## Additional Finding (Not Explicit in Claude Report)

### Cancellation/status semantics are inconsistent across UI and backend

Status: `Confirmed`  
Priority: `High`

Evidence:

- UI uses `'cancelled'` state (`app/Livewire/MediaUpload.php`).
- Backend often marks cancellation as failed (`app/Services/SermonProcessingService.php`, `app/Services/LivestreamSegmentationService.php`).
- `ProcessingStatus` enum does not include `cancelled` (`app/Enums/ProcessingStatus.php`).

Impact:

- Confusing user behavior and status reporting.
- Harder to maintain retries/cancel flows and status UI consistency.

## Test Coverage Assessment

## What is covered well

- Core routing/orchestration in `UnifiedMediaProcessor` unit tests.
- Basic Livewire upload behavior and auto-submit flows.
- Security-focused API tests for upload/status/retry endpoint classes.

## Where coverage is weak or low-trust

- Many feature tests accept `500`/`429` as normal outcomes, reducing regression sensitivity.
- Some logs/status tests use skip-on-500 patterns.
- Missing direct, strict feature coverage for authenticated cancel success/failure flows.
- Limited direct tests for Livewire processing polling/cancel-processing behavior.

Conclusion:

- Coverage breadth is good, but assertion quality should be improved to increase confidence.

## Refactor Plan (Phased)

## Phase 0: Test Stabilization Baseline

Objective:

- Make tests deterministic enough to support safe refactoring.

Actions:

- Remove permissive “catch-all” status assertions where possible.
- Disable throttle middleware in deterministic behavior tests.
- Replace skip-on-500 patterns with explicit expected behavior.
- Extract shared mock setup helpers/traits for media processing test suites.

Acceptance:

- Targeted media upload test groups pass with strict assertions.
- No new skip-on-500 in media upload tests.

## Phase 1: Validation Unification

Objective:

- Single source of truth for upload rules and limits.

Actions:

- Introduce central media rule builder (service or config helper).
- Migrate API controller, `ProcessMediaRequest`, and Livewire dynamic rules to shared rule source.
- Bind frontend display limits to the same source where feasible.

Acceptance:

- Audio/video/livestream limits and allowed types are consistent across API/web/Livewire.
- One change point for future limit updates.

## Phase 2: Status and Cancellation Normalization

Objective:

- Align UI state model and persisted backend states.

Actions:

- Define explicit cancellation semantics.
- Either add `cancelled` to persisted status model or consistently represent cancelled via explicit metadata/state mapping.
- Update status serialization and UI polling logic accordingly.

Acceptance:

- Cancel behavior is consistent in DB, API status, and Livewire UI.
- Retry/cancel transitions are deterministic and documented.

## Phase 3: Orchestration Deduplication

Objective:

- Remove repeated video/livestream startup logic.

Actions:

- Extract shared initialization flow for metadata extraction, service inference, and processing log creation.
- Reuse across direct video and livestream start paths.
- Keep behavior unchanged initially.

Acceptance:

- Duplicate startup logic reduced.
- No behavior change in existing integration tests.

## Phase 4: Service Decomposition (Video/Storage)

Objective:

- Reduce complexity in extraction/storage path code.

Actions:

- Split `VideoExtractionService` into focused components:
  - extraction/transcoding
  - compression policy
  - disk/path strategy (local vs S3)
- Introduce shared storage/disk detection utility.

Acceptance:

- `VideoExtractionService` significantly reduced in scope/size.
- S3 detection logic centralized and reused.

## Phase 5: Contract/Interface Simplification (Selective)

Objective:

- Remove unnecessary indirection without losing useful seams.

Actions:

- Evaluate each contract for practical abstraction value.
- Remove only contracts with no alternate implementation value and no near-term extension need.
- Keep abstraction for provider-swappable systems (e.g., transcription, speaker identification).

Acceptance:

- Fewer pass-through bindings with no loss of testability.
- Dependency graph easier to follow.

## Risk and Rollout

Primary risks:

- Behavior drift in validation and status transitions.
- Regression in queue/processing side effects during service split.

Mitigation:

- Complete Phase 0 first.
- Ship in small phases with focused regression suites per phase.
- Prefer behavior-preserving refactors before semantic changes.

## Recommended Execution Order

1. Phase 0 (tests)
2. Phase 1 (validation)
3. Phase 2 (status/cancel semantics)
4. Phase 3 (orchestration dedupe)
5. Phase 4 (service decomposition + storage utility)
6. Phase 5 (interface cleanup)

## Definition of Done

- Validation is centralized and consistent in all entry points.
- Cancellation semantics are coherent across model/API/UI.
- Major duplication in startup/storage logic removed.
- Media upload tests are strict, deterministic, and pass consistently.
- Documentation updated for status model and validation source of truth.

