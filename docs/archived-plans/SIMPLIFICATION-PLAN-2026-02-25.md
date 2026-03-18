# Simplification Plan (2026-02-25)

## Scope

This plan consolidates simplification opportunities validated in runtime code.

Goals:

- Reduce duplicated processing/state/storage logic.
- Remove runtime-dead abstractions and legacy fallback paths.
- Keep existing behavior stable while making future changes cheaper.

## Execution Strategy

1. Keep each phase small and releasable.
2. Prefer deleting unused code over adding new wrappers.
3. For each phase: ship code + tests + static analysis + formatting.

Quality gate for each phase:

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Phase 0: Baseline and Safety Net ✅

- [x] Capture current behavior of media upload/status/cancel/retry API flows.
- [x] Add/refresh focused tests around:
  - `MediaController` upload/status/cancel/retry paths.
  - `StandardProcessingResponse` serialization and progress mapping.
  - Livestream happy-path + failure cleanup.
- [x] Capture key fixtures for audio/video/livestream status transitions.

Exit criteria:

- Focused tests pass and fail deterministically when status/step mapping changes.

Files added/updated:
- `tests/Feature/Api/MediaUploadTest.php` — added retry (happy/fail/malformed/unauth), cancel failure/malformed, upload 500, status log delegation
- `tests/Unit/Data/StandardProcessingResponseTest.php` (new) — factory methods, status helpers, toArray serialisation, fromProcessingLog for all 18 named steps
- `tests/Feature/MediaProcessingStatusTransitionsTest.php` (new) — fixture-based step→progress round-trips for audio/video/livestream via real UnifiedMediaProcessor; cancel/retry against real processor, admin visibility

## Phase 1: Canonical Processing Step + Progress Mapping (P1)

Target files:

- `app/Data/StandardProcessingResponse.php`
- `app/Data/LivestreamProcessingResult.php`
- `app/Services/LivestreamStatusService.php`
- `app/Jobs/*` updating `current_step`
- `app/Services/SermonJobPipelineService.php`

Tasks:

- [x] Introduce a single canonical processing-step definition (enum or dedicated mapper class).
- [x] Move all progress percentage logic to one mapper.
- [x] Replace ad-hoc string matching for status/step where possible.
- [x] Remove dead/private mapping helpers in `LivestreamStatusService` once centralized.

Exit criteria:

- One authoritative step/progress mapper is used by API + Livewire status views.

## Phase 2: Storage Adapter Consolidation (P1)

Target files:

- `app/Services/VideoExtractionService.php`
- `app/Services/VideoStorageService.php`
- `app/Services/FrameExtractionService.php`
- `app/Services/SermonMetadataIntegrationService.php`
- `app/Traits/DetectsStorageType.php`

Tasks:

- [x] Create one storage helper/service for: `exists`, `size`, `download-to-temp`, `upload`, `cleanup`.
- [x] Remove duplicate S3/local detection and repeated temp-file download/cleanup logic.
- [x] Reuse one temp-file collector for failure/cancel cleanup paths.

Exit criteria:

- No duplicated S3/local adapter detection logic across the listed services.

## Phase 3: Validation Rule Convergence (P2)

Target files:

- `app/Services/MediaValidationService.php`
- `app/Http/Requests/ProcessMediaRequest.php`
- `app/Jobs/ValidateAudioFile.php`
- `app/Jobs/ValidateVideoFile.php`
- `app/Services/SermonValidationService.php`
- `app/Services/SermonAudioProcessingService.php`

Tasks:

- [ ] Make `MediaValidationService` the canonical source for size/mime/extensions per media type.
- [ ] Replace duplicated hard-coded rules in jobs/services.
- [ ] Remove outdated `media-processing.processing.*` fallbacks where canonical `types.*` config exists.

Exit criteria:

- Validation inputs and error behavior come from one source of truth.

## Phase 4: Orchestrator and Pipeline Layer Reduction (P1/P2) ✅

Target files:

- `app/Services/UnifiedMediaProcessor.php`
- `app/Services/SermonProcessingService.php`
- `app/Services/SermonAudioProcessingService.php`
- `app/Services/SermonJobPipelineService.php`
- `app/Services/LivestreamSegmentationService.php`

Tasks:

- [x] Keep `UnifiedMediaProcessor` as primary orchestrator.
- [x] Remove pass-through methods/classes where they add no behavior.
- [x] Trim runtime-unused methods from `SermonJobPipelineService` (or move to test helper if truly test-only).
- [x] Keep one clear retry/cancel path per processing type.

Files changed:
- `app/Services/SermonProcessingService.php` — removed 6 pass-through methods (`processSermon`, `getProcessingStatus`, `getProcessingStatistics`, `retryProcessing`, `getFailedProcessingLogs`, `markForManualReview`) and 3 now-unused injected deps; retains only `applyGracefulDegradation` and `cancelProcessing`
- `app/Services/UnifiedMediaProcessor.php` — added `SermonAudioProcessingService` + `SermonJobPipelineService` deps; routes audio/retry directly to underlying services instead of through `SermonProcessingService`
- `app/Services/SermonJobPipelineService.php` — removed vestigial `buildSermonProcessingPipeline()` (unused params) and its `ProcessingPipelineBuilder` dependency
- `app/Services/LivestreamSegmentationService.php` — removed `processWithSegmentation()` (pure pass-through to `startProcessing`) and `updateProcessingStatus()` (no external callers)
- `app/Providers/MediaProcessingServiceProvider.php` — explicit binding for `SermonProcessingService` with reduced deps

Exit criteria:

- Reduced service surface area with no behavior regression in upload/status/cancel/retry.

## Phase 5: Runtime-Dead Code Removal (P2) ✅

Candidates:

- `app/Services/LivestreamErrorHandler.php`
- `app/Services/ProcessingExceptionHandler.php`
- `app/Services/SermonMetadataService.php`
- `app/Services/SermonVideoDisplayService.php`
- `app/Http/Requests/StorePageRequest.php`
- `app/Http/Requests/UpdatePageRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Controllers/Auth/PasswordController.php`
- `app/Data/ProcessingConfiguration.php`
- `app/Http/Resources/SermonCollection.php`
- `app/Exceptions/UnsupportedProcessingTypeException.php`
- `app/Exceptions/ThumbnailGenerationException.php`

Tasks:

- [x] Confirm runtime reachability with `rg` and route/container references.
- [x] Delete unused classes and prune tests that only cover removed dead paths.
- [x] Keep deletions grouped by domain to simplify review.

Files deleted:
- `app/Services/LivestreamErrorHandler.php`
- `app/Services/ProcessingExceptionHandler.php`
- `app/Services/SermonMetadataService.php`
- `app/Services/SermonVideoDisplayService.php`
- `app/Http/Requests/StorePageRequest.php`
- `app/Http/Requests/UpdatePageRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Controllers/Auth/PasswordController.php`
- `app/Data/ProcessingConfiguration.php`
- `app/Http/Resources/SermonCollection.php`
- `app/Exceptions/UnsupportedProcessingTypeException.php`
- `app/Exceptions/ThumbnailGenerationException.php`

Tests pruned:
- `tests/Unit/Services/LivestreamErrorHandlerTest.php` (deleted — only covered removed class)
- `tests/Unit/Services/ProcessingExceptionHandlerTest.php` (deleted — only covered removed class)
- `tests/Unit/Services/SermonMetadataServiceTest.php` (deleted — only covered removed class)
- `tests/Unit/Services/SermonVideoDisplayServiceTest.php` (deleted — only covered removed class)
- `tests/Feature/LivestreamProcessingIntegrationTest.php` — removed `test_error_handling_integration` and `SermonVideoDisplayService` usage from `test_sermon_integration_with_livestream_processing`

Other:
- `phpstan.neon` — removed `excludePaths` entry for deleted `PasswordController.php`

Exit criteria:

- Removed files are not required by routes/runtime container bindings.

## Phase 6: Config and Queue Key Cleanup (P2/P3) ✅

Target files:

- `config/media-processing.php`
- `app/Jobs/SubmitToProcessing.php`
- `app/Services/SermonValidationService.php`
- `app/Services/LivestreamErrorHandler.php` (if retained)
- any service reading legacy `queue.name` / `storage_disk` / `processing.*` fallback keys

Tasks:

- [x] Standardize on canonical config keys (`queues.*`, `types.*`, `storage.*`).
- [x] Remove or migrate obsolete aliases only after callsites are migrated.
- [x] Eliminate misleading diagnostics that compare identical keys.

Files changed:
- `config/media-processing.php` — removed legacy `queue.name` block, removed redundant `storage.disk` key (kept `storage.sermon_disk`), removed `processing.queue` key
- `app/Jobs/SubmitToProcessing.php` — removed non-existent `storage_disk` key lookup, removed self-comparing `config_diagnostics` block
- `app/Jobs/AnalyzeSegments.php` — collapsed three-level queue fallback chain to `queues.livestream` with hardcoded default
- `app/Services/LivestreamSegmentationService.php` — same queue fallback simplification
- `app/Services/SermonJobPipelineService.php` — removed `processing.queue` fallback from `defaultQueue()`
- `app/Jobs/UpdateSermonRecord.php` — collapsed `queues.processing`/`processing.queue`/`queues.default` chain to `queues.default`

Exit criteria:

- One canonical key path per configuration concern.

## Phase 7: Logging/Reporting Unification (P2) ✅

Target files:

- `app/Services/SermonProcessingLogger.php`
- `app/Services/MediaProcessingLogger.php`
- `app/Services/LivestreamProcessingLogger.php`
- `app/Services/ProcessingLogService.php`
- `app/Services/ProcessingReport.php`

Tasks:

- [x] Pick one primary logger API and retire redundant wrappers.
- [x] Keep one log parsing/reporting service for processing logs.
- [x] Remove duplicated status aggregation code.

Files changed:
- `app/Services/SermonProcessingLogger.php` — absorbed `logWarning`, `generateProcessingReport`, `getRecentProcessingActivity` from `LivestreamProcessingLogger`
- `app/Services/MediaProcessingLogger.php` — deleted (empty alias; all callers updated to `SermonProcessingLogger`)
- `app/Services/LivestreamProcessingLogger.php` — deleted (duplicate logging methods removed; unique report/activity methods moved to `SermonProcessingLogger`)
- `app/Services/AudioTranscriptionService.php`, `SermonAnalysisService.php`, `AudioChunkingService.php`, `MockTranscriptionService.php` — type-hint updated from `MediaProcessingLogger` to `SermonProcessingLogger`
- `tests/Unit/Services/LivestreamProcessingLoggerTest.php` — deleted; unique tests migrated to `SermonProcessingLoggerTest`

Exit criteria:

- Single structured logging/reporting path used by media processing runtime.

## Phase 8: Route/Model Responsibility Cleanup (P3)

Target files:

- `routes/web.php`
- `app/Http/Controllers/SermonController.php`
- `app/Http/Controllers/Admin/SermonAdminController.php`
- `app/Models/Sermon.php`

Tasks:

- [ ] Reduce overlapping route styles and wrapper endpoints where possible.
- [ ] Move presentation/SEO/podcast formatting concerns out of `Sermon` model into presenters/resources.

Exit criteria:

- Thinner model responsibilities and clearer route/controller boundaries.

## Phase 9: Legacy Fallback Retirement Across Content/Media (P1/P2) ✅ (partial)

Target files:

- `app/Models/Page.php`
- `app/Models/Meeting.php`
- `app/Services/SermonStorageService.php`
- `app/Http/Controllers/Api/MediaController.php`
- legacy migration/verification commands in `app/Console/Commands/*`

Tasks:

- [x] `Page` legacy `public/images/headings/*` fallback removed — run `pages:migrate-images` in production first.
- [x] `Meeting` filesystem photo fallback removed — run `meetings:migrate-photos` in production first.
- [x] `MediaController` legacy processing ID acceptance narrowed to UUID-only.
- [x] `MigratePageImagesToMediaLibrary.php` and `MigrateImagesToStorage.php` deleted (superseded).
- [x] `meetings:migrate-photos` command added (`app/Console/Commands/MeetingMigratePhotosCommand.php`).
- [ ] **Deferred — sermon storage**: `SermonStorageService` legacy pattern branch (`filetype NOT NULL`, no slash) still active. 663/698 production sermons use the legacy pattern. Requires a new migration command that canonicalises both the `audio_file_path`/`filetype` columns and confirms files are at the resolved `do_spaces` paths, before the branch can be removed. `MigrateSermonStorageCommand.php` and `VerifySermonStorageCommand.php` retained until then.

Exit criteria:

- Runtime no longer branches on legacy storage/path formats for page, meeting, sermon, or processing ID lookup.

## Phase 10: Media Type and Source Type Enum Normalization (P1/P2) ✅

Target files:

- `app/Services/MediaValidationService.php`
- `app/Services/UnifiedMediaProcessor.php`
- `app/Http/Controllers/Api/MediaController.php`
- `app/Models/MediaProcessingLog.php`
- `app/Models/Sermon.php`
- jobs/services matching on `'audio'|'video'|'livestream'` and source type strings

Tasks:

- [x] Introduce canonical enum(s) for processing/media type and sermon source type.
- [x] Replace scattered string literals and regex fragments with enum-backed values.
- [x] Consolidate supported-type definitions (validation, routing constraints, service dispatch) to one source.
- [x] Add/adjust casts/validation so invalid type values fail fast.

Files added:
- `app/Enums/MediaType.php` — `Audio='audio'`, `Video='video'`, `Livestream='livestream'` with `label()` and `hasVideo()` helpers
- `app/Enums/SermonSourceType.php` — `Manual='manual'`, `AudioUpload='audio_upload'`, `VideoUpload='video_upload'`, `Livestream='livestream'` with `label()` and `isFromLivestream()` helpers

Files changed:
- `app/Models/MediaProcessingLog.php` — `processing_type` cast to `MediaType`; scopes updated to use enum
- `app/Models/Sermon.php` — `source_type` cast to `SermonSourceType`; `isFromLivestream()` and `scopeBySourceType()` updated
- `app/Services/MediaValidationService.php` — all public methods accept `MediaType`; `supportedTypes()` returns `array<MediaType>`
- `app/Services/UnifiedMediaProcessor.php` — `MediaType::tryFrom()` at API boundary; exhaustive enum match for dispatch
- `app/Services/ProcessingInitiator.php` — signature accepts `MediaType`
- `app/Services/LivestreamSegmentationService.php`, `SermonAudioProcessingService.php`, `SermonValidationService.php`, `SermonMetadataIntegrationService.php`, `SermonJobPipelineService.php`, `AudioExtractionService.php` — updated to use enums
- `app/Data/SermonCreationOptions.php` — `$sourceType` changed to `SermonSourceType`; factory methods updated
- `app/Data/StandardProcessingResponse.php` — exhaustive enum match (no `default` arm)
- `app/Http/Controllers/Api/MediaController.php` — `MediaType::tryFrom()` replaces `in_array` check
- `app/Jobs/CreateSermonRecord.php`, `GenerateThumbnail.php`, `CleanupTemporaryFiles.php`, `TranscribeAudio.php`, `IdentifySpeaker.php`, `ValidateVideoFile.php`, `ProcessTranscriptWithAI.php` — updated to use enums
- `app/Livewire/Traits/WithUploadLifecycle.php` — `MediaType::tryFrom()` replaces `in_array` against `supportedTypes()`
- `app/Console/Commands/MigrateLivestreamAudioFiles.php`, `ProcessVideoCommand.php` — updated to use enums
- `database/factories/MediaProcessingLogFactory.php`, `SermonFactory.php` — updated to use enums
- Tests updated: `ProcessingInitiatorTest`, `MediaValidationServiceTest`, `LivestreamSegmentationServiceTest`, `SermonAudioProcessingServiceTest`, `SermonCreationServiceTest`, `SermonMetadataIntegrationServiceTest`, `MediaProcessingLogTest`, `LivestreamProcessingIntegrationTest`

Exit criteria:

- One canonical enum-driven source exists for media/source types across API, jobs, models, and Livewire.

## Phase 11: Livewire Admin/List Deduplication (P2) ✅

Target files:

- `app/Livewire/Admin/*/List*.php`
- `app/Livewire/Admin/Components/ResourceTable.php`
- `app/Livewire/MediaUpload/Form.php`
- `app/Livewire/Traits/WithUploadLifecycle.php`

Tasks:

- [x] Extract shared sortable/filterable list behavior (`sort`, sort sanitization, query-string normalization, page reset) into one reusable trait/helper.
- [x] Remove repeated authorization checks in component actions where middleware/policies already enforce access.
- [x] Remove container lookups (`app(...)`) from Livewire render/action paths in favor of injected dependencies.
- [x] Keep components focused on UI state; move repeated query composition/state transitions to dedicated helpers.

Files added:
- `app/Livewire/Traits/WithSortableListing.php` — shared `sort()` and `sanitizeSorting()` using `static::ALLOWED_SORT_COLUMNS` constants
- `app/Livewire/Traits/WithAdminAuthorization.php` — shared `authorizeAdmin()` method

Files changed:
- `app/Livewire/Admin/Meetings/ListMeetings.php` — uses `WithSortableListing`; constants changed to `protected`
- `app/Livewire/Admin/Pages/ListPages.php` — uses `WithSortableListing`; constants changed to `protected`
- `app/Livewire/Admin/Sermons/ListSermons.php` — uses `WithSortableListing` + `WithAdminAuthorization`; constants changed to `protected`
- `app/Livewire/Admin/Preachers/ListPreachers.php` — uses `WithSortableListing` + `WithAdminAuthorization`; constants changed to `protected`
- `app/Livewire/Admin/Users/ListUsers.php` — uses `WithAdminAuthorization`
- `app/Livewire/MediaUpload/Form.php` — services resolved via `boot()` DI instead of `app()`; `render()` uses `MediaType::tryFrom()` for type-safe validation calls
- `app/Livewire/Traits/WithUploadLifecycle.php` — uses `$this->validation` property instead of `app()`

Files deleted:
- `app/Livewire/Admin/Components/ResourceTable.php` — unused abstract base class (no component extended it)

Exit criteria:

- Admin list components share one sorting/filtering pattern, and MediaUpload runtime paths do not use service-locator lookups.

## Phase 12: Calendar Integration and Cache Invalidation Cleanup (P2/P3)

Target files:

- `app/Http/Controllers/Admin/CalendarAdminController.php`
- `app/Services/CalendarService.php`
- calendar sync/cache-related tests

Tasks:

- [ ] Move `CalendarAdminController` from `app(...)` service resolution to constructor injection.
- [ ] Split Google Calendar API integration concerns from categorization/business rules in `CalendarService`.
- [ ] Replace wildcard-style cache invalidation and global `Cache::flush()` with deterministic key/tag invalidation.
- [ ] Add focused tests for cache invalidation and sync error/retry behavior.

Exit criteria:

- Calendar cache invalidation is explicit/safe, and calendar controller/service boundaries are thin and testable.

## Phase 13: Schema Snapshot and Migration Hygiene (P2/P3)

Target files:

- `database/schema/mysql-schema.sql`
- `database/migrations/*`
- CI checks/scripts that rely on schema snapshots

Tasks:

- [ ] Decide one approach and enforce it: keep schema dump current, or remove schema dump usage.
- [ ] If retained, regenerate schema snapshot after cleanup migrations and add guardrails to detect drift.
- [ ] Remove stale assumptions in tooling/tests that rely on dropped legacy tables.

Exit criteria:

- Database bootstrap path is deterministic and aligned with the current migration history.

## Phase 14: Complexity Hotspot Decomposition (P3)

Target files:

- `app/Services/ThumbnailGenerationService.php`
- `app/Services/AudioTranscriptionService.php`
- `app/Services/SermonAnalysisService.php`
- `app/Services/MetadataExtractionService.php`

Tasks:

- [ ] Split mixed-responsibility services into smaller collaborators (orchestrator + focused workers).
- [ ] Isolate pure transformation logic (prompt/text formatting, wrapping, parsing) from IO-heavy concerns (storage/network/ffmpeg).
- [ ] Keep public service APIs stable while reducing internal method sprawl and cross-cutting concerns.

Exit criteria:

- Hotspot services are decomposed into focused units with lower cognitive load and clearer test seams.

## Proposed Order

1. Phase 0 (safety net)
2. Phase 1 (step/progress unification)
3. Phase 2 (storage consolidation)
4. Phase 3 (validation convergence)
5. Phase 4 (orchestrator reduction)
6. Phase 5 (dead code removal)
7. Phase 6 (config cleanup)
8. Phase 7 (logging/reporting unification)
9. Phase 8 (route/model cleanup)
10. Phase 9 (legacy fallback retirement)
11. Phase 10 (media/source type enum normalization)
12. Phase 11 (Livewire admin/list deduplication)
13. Phase 12 (calendar/cache cleanup)
14. Phase 13 (schema snapshot hygiene)
15. Phase 14 (complexity hotspot decomposition)

## Definition of Done

- [ ] Phases 1-4 complete with no API behavior regressions.
- [ ] Runtime-dead code deleted.
- [ ] Canonical config keys enforced.
- [ ] Logging/status/progress behavior is consistent across API and Livewire.
- [ ] Legacy fallback branches retired after migration completion.
- [x] Media/source typing is enum-driven instead of string-scattered.
- [ ] Calendar cache invalidation is deterministic and does not use global flush.
- [ ] Schema snapshot strategy is consistent and drift-free.
- [ ] All required quality gates pass per phase.
