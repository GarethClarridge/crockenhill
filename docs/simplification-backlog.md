# Simplification Backlog

Derived from [architectural-review.md](architectural-review.md). Each item is a single PR.
Items are ordered by priority: low-risk deletions first, then consolidation, then refactoring.

> **Sequencing note**: Some items interact with the [church service backlog](church-service-backlog.md).
> Items marked ⏸️ should be deferred until the noted church service phase is complete.
> Items marked 🔗 should be coordinated with the noted church service work.

---

## Priority 1: Dead Code Removal

### PR 1. Delete one-time migration commands ⏸️
> Blocked - check these have all been executed in prod first. 
Remove 7 commands that have already been executed and serve no ongoing purpose.
- `BackfillMediaProcessingIdentityCommand`
- `PreacherCutoverCommand`
- `MeetingMigratePhotosCommand`
- `MigrateLocalFilesToSpacesCommand`
- `MigrateSermonStorageCommand`
- `MigrateLivestreamAudioFiles`
- `FixUploadDirectories`
- Delete associated tests

### PR 2. Delete test artifacts from production code ✅
- ~~Delete `TestJob` (empty placeholder job)~~ — deleted; dispatch tests now use a local `StubJob` stub defined at the bottom of `SermonJobPipelineServiceTest.php`
- ~~Delete `TestBritishEnglishConverter` command (unit test already covers this)~~ — deleted
- ~~Delete associated tests~~

### PR 3. Delete unused views and dead controller methods ✅
- ~~Delete `resources/views/meetings/create.blade.php`~~ — already gone
- ~~Delete `resources/views/meetings/edit.blade.php`~~ — already gone
- `resources/views/sermons/upload.blade.php` — **kept**: still used by `SermonAdminController::upload()`
- ~~Remove `MeetingController::create()`, `store()`, `edit()` methods~~ — removed; `Route::resource` updated to `only(['index', 'update', 'destroy'])`
- ~~Remove `CalendarController::meetingsIndex()` method~~ — removed

### PR 4. Delete unused authorization code ⏸️
> Blocked — none of this is actually dead yet. Prerequisites before this PR can proceed:
>
> **Gates** (`manage-sermons`, `manage-meetings`, `manage-pages`) are used in three `@can` blocks in views:
> - `resources/views/sermons/index.blade.php` — `@can('manage-sermons')` guards the upload button
> - `resources/views/members/home.blade.php` — all three gates guard admin action cards
>
> These should be replaced with `$user->is_admin` checks directly (or the `authorizeAdmin()` pattern used in Livewire components) before the gates can be removed.
>
> **`MeetingPolicy`** is still called by:
> - `MeetingController::index()` — `authorize('viewAny', Meeting::class)`
> - `MeetingController::destroy()` — `authorize('delete', $meeting)`
> - `UpdateMeetingRequest::authorize()` — `can('update', $meeting)`
> - `StoreMeetingRequest::authorize()` — `can('create', Meeting::class)`
>
> The policy can only be deleted once `MeetingController::index()`, `update()`, and `destroy()` are removed (see parking lot note on dead resource routes) and `StoreMeetingRequest` is deleted alongside `store()`.
>
> **`PagePolicy`** has no direct `authorize()` callers in production code — Livewire components use `authorizeAdmin()` instead — but it is registered and tested. Safe to delete once the registration is removed and policy tests are deleted.

- Delete `MeetingPolicy` (after removing remaining dead `MeetingController` methods)
- Delete `PagePolicy` (no live callers; just needs deregistering)
- Remove 3 gates from `AuthServiceProvider` (`manage-sermons`, `manage-meetings`, `manage-pages`) (after replacing `@can` in views)
- Remove policy registrations from `AuthServiceProvider`
- Delete `StoreMeetingRequest` (only used by the now-deleted `store()` method — missed in PR 3)
- Delete `AuthorizationGatesTest`, `MeetingPolicyTest`, `PagePolicyTest`

### PR 5. Remove unused dependencies ✅
- ~~npm: remove `lodash`, `ajv`, `cross-env`~~ — removed
- ~~Composer: remove `techwilk/bible-verse-parser`~~ — removed

### PR 6. Delete `SermonProcessingStep` model ⏸️
> Blocked — `SermonProcessingStep` is actively used by `ProcessingJob` (`app/Jobs/ProcessingJob.php`), which is the base class for four live queued jobs: `TranscribeAudio`, `CreateSermonRecord`, `ProcessTranscriptWithAI`, `IdentifySpeaker`. It records step state (started, completed, failed, cancelled) for each job run.
>
> This can only be deleted if `ProcessingJob` is refactored to use `MediaProcessingLog` instead (which already tracks processing state), or if the step-level granularity is intentionally dropped. Worth revisiting alongside the parking-lot item on `SermonProcessingLogger` / `ProcessingLogService` overlap.

- Delete model, factory, migration
- Delete associated tests (`SermonProcessingStepTest`)
- Refactor or remove `ProcessingJob` base class first

---

## Priority 2: Remove Unnecessary Abstractions

### PR 7. Delete small unnecessary abstractions
- ~~Delete `ProcessingLogContract`~~ ✅ — removed; `ProcessingLogService` no longer implements it
- Delete `DetectsStorageType` trait ⏸️ — the backlog said "2 services" but it's actually 6 consumers (3 jobs + 3 services). Inlining all 6 is a larger change; defer.
- Delete `HasConditionalLogging` trait ⏸️ — suppresses log noise in tests via `app()->runningUnitTests()` check; replacing with `Log::spy()` requires updating test setup in 2 Livewire component test files. Defer.
- Delete `H1` view component ⏸️ — used in 6+ views; replacing with inline markup or a blade partial is low-risk but tedious. Defer.
- Delete `SermonProcessingLogFormatter` ⏸️ — **not dead**: registered as a log channel tap in `config/logging.php`. Remove only if switching log formatting is intentional.
- Delete `SermonRepository` ⏸️ — **not dead**: has 6 callers across controllers, jobs, services, and tests. The backlog underestimated the scope. Fold into a larger sermon layer cleanup.

### PR 8. Inline `WithUploadLifecycle` trait ⏸️
> Not recommended: the trait is 244 lines of substantive upload state and lifecycle logic. `Form.php` is already 353 lines. Inlining would produce a ~600 line component with two distinct concerns blended together. The trait provides a clean logical boundary. Leave unless there is a specific reason to collapse it.

---

## Priority 3: Service Layer Consolidation

### PR 9. Merge `LivestreamStatusService` into `LivestreamSegmentationService` ✅
- ~~`LivestreamStatusService` (99 lines) duplicates `buildProcessingResult()` from `LivestreamSegmentationService`~~ — confirmed; key stored is `file_format`, not `format` (inconsistency fixed in the process)
- ~~Move any unique methods into `LivestreamSegmentationService`~~ — `getProcessingStatus()`, `getProcessingResult()`, `getProcessingSummary()` moved; `buildProcessingResult()` deduplicated
- ~~Update all callers~~ — service provider binding removed
- ~~Delete `LivestreamStatusService`~~ — deleted; tests migrated into `LivestreamSegmentationServiceTest`

### PR 10. Merge `ProcessingResult` and `ProcessingReport` ⏸️
> Skipped — these are not "nearly identical value objects". `ProcessingResult` is a strongly-typed API response object (typed properties, success/failure factory methods); `ProcessingReport` is a generic diagnostic data bag (array-backed, enum-aware, with helpers like `hasErrors()`, `getSegmentCount()`). Merging would conflate API response concerns with internal diagnostic concerns and damage type safety.

### PR 11. Inline `SermonProcessingService` ✅
- ~~`cancelProcessing()`~~ — inlined into `UnifiedMediaProcessor::cancelSermonProcessing()` (private method); `SermonProcessingLogger` injected in place of `SermonProcessingService`
- ~~`applyGracefulDegradation()`~~ — dropped; had no production callers (tests-only dead code)
- ~~Delete service~~ — deleted; unit tests replaced by direct DB assertions in `UnifiedMediaProcessorTest`

### PR 12. Inline `SermonStatusManagementService` ⏸️
> Defer until after church service Phase 3. The new pipeline introduces review states and confidence-based status logic that will change the callers you'd inline into.
- Simple DB queries + formatting
- Move methods to model scopes or inline into controllers
- Delete service

### PR 13. Inline `SermonAudioProcessingService` ⏸️
> Defer until after church service Phase 3. Phase 3.5 reworks the processing pipeline — inlining into `UnifiedMediaProcessor` now means Phase 3 refactors that consolidated code again.
- Duplicates audio branch from `UnifiedMediaProcessor`
- Consolidate into `UnifiedMediaProcessor`
- Delete service

### PR 14. Delete duplicate data classes
- Delete `App\Data\LivestreamSegment` DTO (use `App\Models\LivestreamSegment` instead; move any unique formatting to model)
- Delete `ProcessingLogEntry` and `ProcessingLogCollection` (use Laravel Collections)

### PR 15. Consolidate service providers
- Merge `UrlServiceProvider` (~16 lines) into `AppServiceProvider::boot()`
- Merge `ModelObserverServiceProvider` (~27 lines) into `AppServiceProvider::boot()`
- Merge `RateLimitServiceProvider` (~50 lines) into `AppServiceProvider::boot()`
- Remove from `bootstrap/providers.php`

---

## Priority 4: Config Simplification

### PR 16. Simplify `thumbnail-generation.php`
- Move pixel values, colours, font sizes, stroke widths to class constants in `ThumbnailGenerationService`
- Keep only environment-varying config: `enabled`, `storage`, `max_concurrent_jobs`, `skip_on_failure`
- Remove ~40 env vars from `.env.example`
- Target: 245 → ~50 lines

### PR 17. Simplify `media-processing.php` ⏸️
> Defer until after church service Phase 3. Phase 3.1 adds new config keys (e.g. `section_classification.transcribe_speech_segments`). Simplify after the new keys are settled.
- Delete dead keys: `processing.timeout`, `processing.max_concurrent_jobs`, `analysis.model`
- Move `visual_analysis.*` thresholds to service constants
- Keep environment-varying config: storage disks, queue names, notification toggles, file size limits
- Target: 246 → ~100 lines

### PR 18. Simplify `podcast.php` and `organization.php`
- `podcast.php`: hardcode static metadata (owner, author, category, feed UIDs); keep `enabled` flag and routes. Target: 118 → ~30 lines
- `organization.php`: remove `env()` wrappers from static values (church name, address, phone)

---

## Priority 5: Speaker Identification Simplification

### PR 19. Make speaker identification always-on
- Delete `NullSpeakerIdentificationService`
- Remove feature gates from `IdentifySpeaker` job (5 checks)
- Remove `speaker_identification.enabled` and `speaker_identification.shadow_mode` from config
- Update `MediaProcessingServiceProvider` binding (always bind to `ResemblyzerSpeakerIdentificationService`)
- Keep `SpeakerIdentificationInterface` for test mocking
- Simplify config to just Resemblyzer connection details

---

## Priority 6: Model Refactoring

### PR 20. Slim down Sermon model 🔗
> Do before church service Phase 4 (4.1 adds `content_type` and new scopes to this model).
- Remove rarely-used scopes (audit usage first; keep ~6 of 14)
- Remove instance methods that duplicate scopes (e.g. `isFromLivestream()` vs `scopeFromLivestream()`)
- Extract storage URL accessors (`getAudioUrlAttribute`, `getThumbnailUrlAttribute`, `getVideoUrlAttribute`) to a presenter

### PR 21. Clean up MediaProcessingLog model 🔗
> Coordinate with church service 1.1 (adds `church_service_id` FK and relationships to this model). Do 1.1 first or combine.
- Remove backward-compat `storedFilePath()` accessor (fix callers to use `source_file_path`)
- Extract `scopeVisibleTo()` to middleware or policy

### PR 22. Simplify Meeting model page delegation
- Consolidate 6+ `$this->page?->field` accessor methods into a cleaner pattern

---

## Priority 7: Church Service Subsystem (Internal Simplification)

### PR 23. Simplify `SongCatalogSyncService`
- At 844 lines, this is the largest service in the app
- Review deduplication algorithm for simpler approach
- Consider splitting into smaller focused methods/classes

### PR 24. Separate anomaly detection from `ServiceSectionClassifier`
- Extract anomaly detection (segment overlaps, order violations) into a separate pass
- Makes core classification logic easier to reason about

---

## Parking Lot (Evaluate Later)

These items need further investigation or a decision before acting:

- **Alpine.js duplication**: Livewire 3 auto-includes Alpine, but it's also in `package.json`. Check for duplicate instances.
- **`spatie/laravel-data` replacement**: 7 DTOs use it but none use advanced features. Could replace with plain PHP classes. Low priority — only worth doing if upgrading the package becomes painful.
- **`SermonProcessingLogger` / `ProcessingLogService` overlap**: These two services have overlapping responsibilities (logging, statistics, report generation). Merging or splitting cleanly is a larger refactor — scope it when tackling the processing pipeline.
- **`SermonJobPipelineService` split**: 349 lines mixing dispatching, retry logic, and pipeline state. Benefits from splitting but touches many callers. **→ Fold into church service Phase 3.5** — that work rewrites the pipeline chain anyway, making it the natural time to split.
- **`SermonValidationService` split**: Mixes file validation, data validation, and state queries. Worth separating but needs careful caller analysis.
