# Simplification Backlog

Derived from [architectural-review.md](architectural-review.md). Each item is a single PR.
Items are ordered by priority: low-risk deletions first, then consolidation, then refactoring.

> **Note**: The church service backlog is now complete. Remaining ⏸️ items are blocked on
> scope or dependency issues unrelated to church service work.

---

## Priority 1: Dead Code Removal

### PR 1. Delete one-time migration commands ✅
- ~~All 7 migration commands deleted~~ — confirmed removed from codebase

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
> Partially complete — dead pieces removed (`PagePolicy`, `StoreMeetingRequest`). Remaining work is blocked on live callers (not church-service-dependent):
>
> **Gates** (`manage-sermons`, `manage-meetings`, `manage-pages`) are used in 7 `@can` blocks across 5 views:
> - `resources/views/sermons/index.blade.php` — `@can('manage-sermons')`
> - `resources/views/sermons/sermon.blade.php` — `@can('manage-sermons')`
> - `resources/views/components/edit-buttons.blade.php` — `@can('manage-pages')`
> - `resources/views/components/sermon-card.blade.php` — `@can('manage-sermons')`
> - `resources/views/members/home.blade.php` — all three gates
>
> Replace with `$user->is_admin` checks (or `authorizeAdmin()` pattern) before removing gates.
>
> **`MeetingPolicy`** still called by `MeetingController` (index, destroy) and `UpdateMeetingRequest`.

- Replace `@can` gate checks with `$user->is_admin` in 5 views
- Delete `MeetingPolicy` (after removing remaining `MeetingController` authorize calls)
- Remove 3 gates from `AuthServiceProvider`
- Remove policy registrations from `AuthServiceProvider`
- Delete `AuthorizationGatesTest`, `MeetingPolicyTest`

### PR 5. Remove unused dependencies ✅
- ~~npm: remove `lodash`, `ajv`, `cross-env`~~ — removed
- ~~Composer: remove `techwilk/bible-verse-parser`~~ — removed

### PR 6. Delete `SermonProcessingStep` model ⏸️
> Blocked on `ProcessingJob` refactor — `SermonProcessingStep` (154 lines) is used by `ProcessingJob` (187 lines), which is the base class for 10+ queued jobs. Also referenced by `ShowChurchService` Livewire component.
>
> Requires refactoring `ProcessingJob` to use `MediaProcessingLog` instead, or intentionally dropping step-level granularity. Consider alongside the `SermonProcessingLogger` / `ProcessingLogService` overlap (parking lot).

- Refactor `ProcessingJob` to use `MediaProcessingLog` for step tracking
- Update `ShowChurchService` Livewire component
- Delete model, factory, migration
- Delete associated tests (`SermonProcessingStepTest`)

---

## Priority 2: Remove Unnecessary Abstractions

### PR 7. Delete small unnecessary abstractions
- ~~Delete `ProcessingLogContract`~~ ✅ — removed; `ProcessingLogService` no longer implements it
- Delete `DetectsStorageType` trait ⏸️ — 9 consumers (5 jobs + 4 services). Inlining is a wide change; defer until there's a reason to touch these files.
- Delete `HasConditionalLogging` trait ⏸️ — 2 Livewire consumers. Replacing with `Log::spy()` requires test setup changes. Low value.
- Delete `H1` view component ⏸️ — 13 views use it. Low-risk but tedious. Low value.
- Delete `SermonProcessingLogFormatter` ⏸️ — **not dead**: registered as log channel tap in `config/logging.php`. Remove only if switching log formatting is intentional.
- Delete `SermonRepository` ⏸️ — **not dead**: 6 callers across controllers, jobs, services. Fold into a larger sermon layer cleanup.

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

### PR 12. Inline `SermonStatusManagementService` ✅
- ~~`markForManualReview()`~~ — moved to `MediaProcessingLog` model (alongside `markAsFailed()`, `markAsCancelled()`)
- ~~`getProcessingStatus()`~~ — already handled by `UnifiedMediaProcessor::getStatus()`; service version was dead
- ~~`getProcessingStatistics()` / `getFailedProcessingLogs()`~~ — no production callers; deleted with the service
- ~~Service dependency removed from `SermonJobPipelineService` and `ExtractSermon`~~
- ~~Delete `SermonStatusManagementService` and `SermonStatusManagementServiceTest`~~

### PR 13. Inline `SermonAudioProcessingService`
> Ready — church service pipeline work is complete. Service is 160 lines with 2 callers (`UnifiedMediaProcessor` + service provider binding).
- Consolidate into `UnifiedMediaProcessor`
- Remove service provider binding
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
