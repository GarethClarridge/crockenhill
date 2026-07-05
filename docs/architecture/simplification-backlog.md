# Simplification Backlog

> **Superseded (2026-07-05):** consolidated into
> [docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](../plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md).
> Dispositions of the still-open items: PR 4 carried over (backlog item 4.5); PR 19 closed as moot
> (speaker identification is already enabled and working in production — stack kept); PR 20 and the
> parking-lot items parked to the Phase 9 code-quality review; PR 23 done (`Sync/` split landed,
> 879 → 410 lines); PR 24 superseded (the classifier is on the July backlog's deletion list, item
> 1.5). Do not add new work here; archive once item 4.5 lands.

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

### PR 6. ~~Delete `SermonProcessingStep` model~~ — removed from backlog
> No longer viable. Church service Phase 9.1 built the processing timeline view on top of `SermonProcessingStep`. The model (154 lines) is now referenced in 36 places across 7 files, including `ShowChurchService` timeline rendering and all `ProcessingJob` step logging. It is load-bearing infrastructure, not dead code.

---

## Priority 2: Remove Unnecessary Abstractions

### PR 7. Delete small unnecessary abstractions
- ~~Delete `ProcessingLogContract`~~ ✅ — removed; `ProcessingLogService` no longer implements it
- Delete `DetectsStorageType` trait ⏸️ — now 12 consumers (church service added `TranscribeSpeechSegments`, `PrepareSectionPublicationCandidates`). Growing, not shrinking — leave it.
- Delete `HasConditionalLogging` trait ⏸️ — 2 Livewire consumers. Replacing with `Log::spy()` requires test setup changes. Low value.
- Delete `H1` view component ⏸️ — 13 views use it. Low-risk but tedious. Low value.
- Delete `SermonProcessingLogFormatter` ⏸️ — **not dead**: registered as log channel tap in `config/logging.php`. Remove only if switching log formatting is intentional.
- Delete `SermonRepository` ⏸️ — **not dead**: now 14 files / 47 references across controllers, jobs, services, and tests. Core data access layer — not a simplification candidate.

### PR 8. Inline `WithUploadLifecycle` trait ⏸️
> Not recommended: the trait is 244 lines of substantive upload state and lifecycle logic. `Form.php` is already 353 lines. Inlining would produce a ~600 line component with two distinct concerns blended together. The trait provides a clean logical boundary. Leave unless there is a specific reason to collapse it.

---

## Priority 3: Service Layer Consolidation

### PR 9. Merge `LivestreamStatusService` into `LivestreamSegmentationService` ✅
- ~~`LivestreamStatusService` (99 lines) duplicates `buildProcessingResult()` from `LivestreamSegmentationService`~~ — confirmed; key stored is `file_format`, not `format` (inconsistency fixed in the process)
- ~~Move any unique methods into `LivestreamSegmentationService`~~ — `getProcessingStatus()`, `getProcessingResult()`, `getProcessingSummary()` moved; `buildProcessingResult()` deduplicated
- ~~Update all callers~~ — service provider binding removed
- ~~Delete `LivestreamStatusService`~~ — deleted; tests migrated into `LivestreamSegmentationServiceTest`

### PR 10. ~~Merge `ProcessingResult` and `ProcessingReport`~~ — removed from backlog
> Confirmed not viable. `ProcessingResult` (76 lines, 16 files) is the API response contract. `ProcessingReport` (59 lines, 5 files) is the internal diagnostic wrapper. They serve different purposes and have diverged further during church service work.

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

### PR 13. Inline `SermonAudioProcessingService` ✅
- ~~Consolidate into `UnifiedMediaProcessor`~~ — `processAudio()`, `storeAudioFile()`, `audioQueue()` inlined as private methods
- ~~Remove service provider binding~~ — removed from `MediaProcessingServiceProvider`
- ~~Delete service~~ — deleted; unit tests migrated into `UnifiedMediaProcessorTest`

### PR 14. ~~Delete duplicate data classes~~ — removed from backlog
> Re-audited: both pairs serve distinct roles and are heavily used.
> - `App\Data\LivestreamSegment` DTO (295 references, 44 files) is the data transfer shape; `App\Models\LivestreamSegment` is the Eloquent model. Not duplicates.
> - `ProcessingLogEntry` / `ProcessingLogCollection` (30 references, 5 files) provide structured log parsing in `ProcessingLogService`. Not replaceable with plain collections.

### PR 15. ~~Consolidate service providers~~ — removed from backlog
> Re-assessed: each provider is lean and focused (27, 31, and 59 lines respectively). `ModelObserverServiceProvider` grew during church service work (observes `ChurchService`, `Sermon`, `Page`, `Meeting`, `Preacher`). `RateLimitServiceProvider` grew with `mailgun-inbound` limiter. Merging into `AppServiceProvider` would make it a grab-bag. Current structure is cleaner.

---

## Priority 4: Config Simplification

### PR 16. Simplify `thumbnail-generation.php` ✅
- ~~Move pixel values, colours, font sizes, stroke widths to class constants in `ThumbnailGenerationService`~~ — done; typed PHP 8.3 constants (`WEB_WIDTH`, `TITLE_FONT_SIZE`, `TITLE_COLOR`, layout percentages, etc.)
- ~~Keep only environment-varying config: `enabled`, `storage`, `max_concurrent_jobs`, `skip_on_failure`~~ — done; removed unused `overlay.*`, `sizes.*`, `social_media.*`, `caching.*`, `logging.*`, `validation.*` sections; 245 → 43 lines
- ~~Remove ~40 env vars from `.env.example`~~ — no thumbnail vars existed in `.env.example`
- Bonus: PHPStan caught two statically-dead `if` branches (from `bool` constants); removed dead branches and the unreachable `addTextWithoutBackground()` method

### PR 17. Simplify `media-processing.php` ✅
- ~~Delete dead keys: `processing.timeout`, `processing.max_concurrent_jobs`, `analysis.model`~~ — removed; `processing.timeout` hardcoded to `7200` in 3 FFMpeg callers; `analysis.model` moved to `SermonAnalysisService::ANALYSIS_MODEL` constant
- ~~Move `visual_analysis.*` thresholds (22 keys) to service constants~~ — thresholds moved to constants in `VisualAnalysisService`, `SongClusteringService` (with constructor params for testability), `VideoSegmentationService`; 3 operational toggles (`enabled`, `fallback_to_rms_on_failure`, `require_min_clusters`) retained as config
- Keep environment-varying config: storage disks, queue names, notification toggles, file size limits

### PR 18. Simplify `podcast.php` and `organization.php` ✅
- ~~`podcast.php`: hardcode static metadata (owner, author, category, feed UIDs); keep `enabled` flag and routes~~ — done; `owner`, `author`, `language`, `category`, `subcategory`, `explicit` hardcoded; `enabled`, `cache.*`, `items_limit` retained as env-varying; 118 → 75 lines
- `organization.php`: no `env()` wrappers existed — already static; no changes needed
- Bonus: removed the `$defaults` fallback array and null-guards in `PodcastFeedService::getFeedMetadata()` — they existed only to defend against config not being loaded, which can't happen with hardcoded values

---

## Priority 5: Speaker Identification Simplification

### PR 19. Make speaker identification always-on ⏸️
> Re-assessed after church service Phase 8.2 (children's talk speaker detection). The 5 feature gates in `IdentifySpeaker` serve as guardrails during children's talk speaker detection rollout. `NullSpeakerIdentificationService` is still used in 4 files. Defer until children's talk speaker detection is stable and the feature gates are no longer needed for safe rollout.

---

## Priority 6: Model Refactoring

### PR 20. Slim down Sermon model
> Ready but scope has changed. Model is now 715 lines with 17 scopes, 11 attribute accessors, and 13+ instance methods. Church service added `content_type` enum, `WhereSermon`/`WhereChildrensTalk` scopes, and `publishedServiceSection()` relationship — all needed.
- Extract 11 attribute accessors (`AudioUrl`, `ThumbnailUrl`, `VideoUrl`, `PublicUrl`, `SeriesUrl`, `PreacherUrl`, `CanonicalUrl`, etc.) to a presenter or trait
- Audit 17 scopes for usage — several may be unused after church service refactoring
- Remove instance methods that duplicate scopes (e.g. `isFromLivestream()` vs `scopeFromLivestream()`, `isAutomated()` vs `scopeAutomated()`)

### PR 21. Clean up MediaProcessingLog model ⏸️
> Re-assessed: model is 389 lines with 10 scopes and 27 public methods. `storedFilePath()` accessor still provides backward compat for `source_file_path`. `scopeVisibleTo()` is an authorization scope restricting logs to admin or owner — extracting to middleware would lose the query-level filtering. Both are justified. Low value.

### PR 22. ~~Simplify Meeting model page delegation~~ — removed from backlog
> Re-audited: only 4 delegation methods (`description`, `body`, `markdown`, `headingImageUrl`), not 6+. These are clean, well-bounded accessors with null-safe delegation. No simplification needed.

---

## Priority 7: Church Service Subsystem (Internal Simplification)

### PR 23. Simplify `SongCatalogSyncService`
> Now 879 lines — grew during church service Phases 1.5/1.6 (source-aware merge, evidence-aware precedence). Still the largest service in the app. Splitting is viable but should wait until the Phase 1.6 sync logic has stabilised in production.
- Review deduplication algorithm for simpler approach
- Consider splitting into smaller focused methods/classes

### PR 24. Separate anomaly detection from `ServiceSectionClassifier`
> Classifier was reworked in Phase 3 (now 228 lines, down from the original). Anomaly detection is still embedded in the classification pass. Extraction is still viable but lower priority given the reduced size.
- Extract anomaly detection (segment overlaps, order violations) into a separate pass
- Makes core classification logic easier to reason about

---

## Parking Lot (Evaluate Later)

These items need further investigation or a decision before acting:

- **Alpine.js duplication**: Livewire 3 auto-includes Alpine, but it's also in `package.json`. Check for duplicate instances.
- **`spatie/laravel-data` replacement**: 7 DTOs use it but none use advanced features. Could replace with plain PHP classes. Low priority — only worth doing if upgrading the package becomes painful.
- ~~**`SermonProcessingLogger` / `ProcessingLogService` overlap**~~: Re-audited — these are complementary, not overlapping. `SermonProcessingLogger` (551 lines, 21 files) *writes* processing events to the log. `ProcessingLogService` (419 lines, 5 files) *reads and parses* those logs into structured `ProcessingLogEntry` records. Writer/reader pattern — no merge needed.
- **`SermonJobPipelineService` split**: 339 lines mixing dispatching, retry logic, and pipeline state. Benefits from splitting but touches many callers. Church service pipeline work is complete — this can now be tackled independently.
- **`SermonValidationService` split**: Mixes file validation, data validation, and state queries. Worth separating but needs careful caller analysis.
