# Simplification Backlog

Derived from [architectural-review.md](architectural-review.md). Each item is a single PR.
Items are ordered by priority: low-risk deletions first, then consolidation, then refactoring.

> **Sequencing note**: Some items interact with the [church service backlog](church-service-backlog.md).
> Items marked ⏸️ should be deferred until the noted church service phase is complete.
> Items marked 🔗 should be coordinated with the noted church service work.

---

## Priority 1: Dead Code Removal

### PR 1. Delete one-time migration commands
Remove 7 commands that have already been executed and serve no ongoing purpose.
- `BackfillMediaProcessingIdentityCommand`
- `PreacherCutoverCommand`
- `MeetingMigratePhotosCommand`
- `MigrateLocalFilesToSpacesCommand`
- `MigrateSermonStorageCommand`
- `MigrateLivestreamAudioFiles`
- `FixUploadDirectories`
- Delete associated tests

### PR 2. Delete test artifacts from production code
- Delete `TestJob` (empty placeholder job)
- Delete `TestBritishEnglishConverter` command (unit test already covers this)
- Delete associated tests

### PR 3. Delete unused views and dead controller methods
- Delete `resources/views/meetings/create.blade.php`
- Delete `resources/views/meetings/edit.blade.php`
- Delete `resources/views/sermons/upload.blade.php`
- Remove `MeetingController::create()`, `store()`, `edit()` methods
- Remove `CalendarController::meetingsIndex()` method

### PR 4. Delete unused authorization code
- Delete `MeetingPolicy`
- Delete `PagePolicy`
- Remove 3 unused gates from `AuthServiceProvider` (`manage-sermons`, `manage-meetings`, `manage-pages`)
- Remove policy registrations from `AuthServiceProvider`

### PR 5. Remove unused dependencies
- npm: remove `lodash`, `ajv`, `cross-env`
- Composer: remove `techwilk/bible-verse-parser`
- Run `npm install` and `composer install` to update lock files

### PR 6. Delete `SermonProcessingStep` model
- Delete model, factory, migration
- Delete associated tests
- Confirm no references remain

---

## Priority 2: Remove Unnecessary Abstractions

### PR 7. Delete small unnecessary abstractions
- Delete `ProcessingLogContract` (single implementation; inject `ProcessingLogService` directly)
- Delete `DetectsStorageType` trait (inline the 3 one-liner methods into the 2 services that use it)
- Delete `HasConditionalLogging` trait (use `Log` directly; use `Log::spy()` in tests)
- Delete `H1` view component (no logic; use blade partial)
- Delete `SermonProcessingLogFormatter` (use default Monolog formatting)
- Delete `SermonRepository` (inline its 2 methods into callers)

### PR 8. Inline `WithUploadLifecycle` trait
- Move trait contents into `MediaUpload/Form.php` (its only consumer)
- Delete the trait file

---

## Priority 3: Service Layer Consolidation

### PR 9. Merge `LivestreamStatusService` into `LivestreamSegmentationService`
- `LivestreamStatusService` (99 lines) duplicates `buildProcessingResult()` from `LivestreamSegmentationService`
- Move any unique methods into `LivestreamSegmentationService`
- Update all callers
- Delete `LivestreamStatusService`

### PR 10. Merge `ProcessingResult` and `ProcessingReport`
- Nearly identical value objects
- Consolidate into a single class
- Update callers

### PR 11. Inline `SermonProcessingService`
- 2 public methods that just wrap other services
- Inline `applyGracefulDegradation()` and `cancelProcessing()` into their callers
- Delete service

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
