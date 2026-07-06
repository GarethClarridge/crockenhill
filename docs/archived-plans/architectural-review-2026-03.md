> **Archived 2026-07-05.** Point-in-time review (March 2026). Its actionable items became `docs/architecture/simplification-backlog.md` (itself superseded by the July 2026 backlog). Retained because the still-open simplification-backlog item PR 4 (backlog item 4.5) references its detail. Claims about the codebase are stale — verify against current code.

# Architectural Review: Crockenhill Baptist Church Website

**Date**: March 2026
**Scope**: Full codebase review for overengineering, dead code, unnecessary complexity, and modernisation opportunities

## Executive Summary

The codebase is well-structured and functional, but has grown significant complexity in the media processing and church service subsystems that is disproportionate to a church website's needs. The core content management (pages, sermons, meetings) is solid. The main opportunities for simplification are:

1. **Speaker identification simplification** (~200 lines): Remove the off-switch infrastructure (null provider, feature gates, enabled flags) and make it always-on
2. **Dead code removal** (~1,200 lines): One-time migration commands, test artifacts, unused views/methods
3. **Service layer consolidation** (~800 lines): Too many thin wrapper services in the processing pipeline
4. **Config over-specification** (~400 lines): Design constants masquerading as environment config
5. **Church service subsystem simplification**: In-progress feature with opportunities to reduce internal complexity
6. **Unused dependencies**: 3 npm packages and 1 Composer package can be removed

**Total estimated reduction: ~2,600 lines of code** without losing any functionality.

---

## 1. Dead Code and Unused Features

### 1.1 Speaker Identification System — SIMPLIFY (make always-on)

**Current state**: The feature works but is wrapped in excessive defensive infrastructure:
- `NullSpeakerIdentificationService` exists solely as an off-switch
- `IdentifySpeaker` job has 5 separate feature gates checking `enabled`, `provider`, shadow mode, etc.
- Config has `enabled` flag, `provider` selection, and `shadow_mode` toggle — three ways to disable one feature

**Recommendation**: Make speaker identification always-on:
- Delete `NullSpeakerIdentificationService` — there should be one implementation
- Remove the 5 feature gates from `IdentifySpeaker` job — if the job is dispatched, it should run
- Remove `speaker_identification.enabled` and `speaker_identification.shadow_mode` config flags
- Keep `SpeakerIdentificationInterface` for testability (mock in tests)
- Keep `ResemblyzerSpeakerIdentificationService`, `SpeakerProfile`, `SpeakerSample`, `BootstrapSpeakerProfilesCommand`
- Simplify config to just the Resemblyzer connection details

**Lines saved**: ~200 (null provider, feature gates, config flags)

### 1.2 One-Time Migration Commands — DELETE

These commands served their purpose and should be removed:

| Command | Purpose |
|---------|---------|
| `BackfillMediaProcessingIdentityCommand` | Backfill extracted_date/extracted_service |
| `PreacherCutoverCommand` | Migrate to canonical Preacher records |
| `MeetingMigratePhotosCommand` | Legacy photos → Media Library |
| `MigrateLocalFilesToSpacesCommand` | Local → S3/Spaces |
| `MigrateSermonStorageCommand` | Storage pattern migration |
| `MigrateLivestreamAudioFiles` | Livestream audio relocation |
| `FixUploadDirectories` | One-time permission/directory fix |

**Lines**: ~1,200 combined

### 1.3 Test Artifacts in Production Code — DELETE

| File | Issue |
|------|-------|
| `TestBritishEnglishConverter` (command) | Test masquerading as artisan command; unit test already exists |
| `TestJob` | Empty placeholder job for queue testing |

### 1.4 Unused Blade Views

| File | Issue |
|------|-------|
| `resources/views/meetings/create.blade.php` | Not routed; admin uses Livewire |
| `resources/views/meetings/edit.blade.php` | Not routed; admin uses Livewire |
| `resources/views/sermons/upload.blade.php` | Legacy; admin uses Livewire MediaUpload |

### 1.5 Dead Controller Methods

- `MeetingController::create()`, `store()`, `edit()` — never routed (admin uses Livewire)
- `CalendarController::meetingsIndex()` — never routed or referenced

---

## 2. Service Layer Overengineering

The media processing pipeline has too many layers. There are 62 service files — for a church website. Several are thin wrappers that add indirection without value.

### 2.1 Services to DELETE or INLINE

| Service | Lines | Issue | Action |
|---------|-------|-------|--------|
| `SermonProcessingService` | 122 | 2 public methods, both just wrap other services | Inline into callers |
| `SermonStatusManagementService` | 169 | Simple DB queries + formatting | Move to repository or model scopes |
| `LivestreamStatusService` | 99 | Duplicates `LivestreamSegmentationService.buildProcessingResult()` | Merge into `LivestreamSegmentationService` |
| `SermonAudioProcessingService` | 160 | Duplicates audio branch from `UnifiedMediaProcessor` | Inline |
| `SermonProcessingLogger` | 550 | Overlaps `ProcessingLogService`; mixes logging, statistics, and reporting | Split or merge with ProcessingLogService |

### 2.2 Services to SIMPLIFY

| Service | Lines | Issue |
|---------|-------|-------|
| `ProcessingLogService` | 420 | Parses raw log files line-by-line with regex instead of querying structured data |
| `SermonJobPipelineService` | 349 | Handles dispatching, retry logic, AND pipeline state; 100+ line switch statement |
| `SermonValidationService` | 347 | Mixes file validation, data validation, storage constraints, and state queries |
| `UnifiedMediaProcessor` | 233 | 3 distinct media workflows crammed together |
| `MediaProcessingIdentityResolver` | 114 | Exists to work around inconsistent column/metadata storage |

### 2.3 Duplicate Data Classes

| Pair | Issue | Action |
|------|-------|--------|
| `ProcessingResult` + `ProcessingReport` | Nearly identical value objects | Merge into one |
| `App\Data\LivestreamSegment` + `App\Models\LivestreamSegment` | Duplicate formatting methods | Delete the DTO; use model |
| `ProcessingLogEntry` + `ProcessingLogCollection` | Thin array wrappers | Use Laravel Collections directly |

### 2.4 Abstraction Layers Assessment

Current flow for processing a media upload:
```
Controller → UnifiedMediaProcessor
           ├→ SermonAudioProcessingService (thin wrapper)
           ├→ SermonJobPipelineService (mixed concerns)
           │  ├→ SermonValidationService (scattered)
           │  └→ SermonStatusManagementService (thin wrapper)
           │     └→ ProcessingLogService (parses raw files)
           ├→ ProcessingPipelineBuilder (good)
           ├→ ProcessingInitiator (good)
           └→ LivestreamSegmentationService (good)
              └→ LivestreamStatusService (duplicate)
```

Recommended simplified flow:
```
Controller → MediaProcessor (routes by type)
           ├→ ProcessingInitiator (keep)
           ├→ ProcessingPipelineBuilder (keep)
           └→ StorageAdapterHelper (keep)
```

---

## 3. Configuration Over-Specification

The test for whether something belongs in config is: **"Would this ever differ between environments (dev/staging/prod)?"** If yes, it's config. If no, it's a design constant and should be hardcoded in the service that uses it.

Previous recommendations to extract things *to* config were about behaviour that genuinely varies between environments (storage drivers, API keys, feature toggles). What follows is the opposite: design constants that were over-promoted to config.

### 3.1 `config/thumbnail-generation.php` — 245 lines, 5 actual usages

40+ environment variables for pixel values, colours, font sizes, and stroke widths. None of these differ between dev and prod — they're design constants (`THUMBNAIL_TITLE_SIZE=144`, `THUMBNAIL_STROKE_WIDTH=2`).

**Keep as config**: `enabled`, `storage` disk, `max_concurrent_jobs`, `skip_on_failure` (these genuinely vary per environment).

**Hardcode in service**: All pixel values, colours, font metrics, text positioning. These are design decisions, not deployment configuration.

**Recommendation**: Reduce to ~50 lines of genuine config. Move design constants into `ThumbnailGenerationService` as class constants.

### 3.2 `config/media-processing.php` — 246 lines, ~50% unused

| Config Key | Issue |
|--------------------|-------|
| `processing.timeout` | 0 calls in codebase — dead config |
| `processing.max_concurrent_jobs` | 0 calls, never enforced — dead config |
| `analysis.model` | 0 calls, hardcoded in service — dead config |
| `visual_analysis.*` (14 thresholds) | Design constants that never differ per environment |
| `speaker_identification.enabled/shadow_mode` | Remove as part of always-on simplification (see 1.1) |

**Keep as config**: Storage disks, queue names, notification toggles, file size limits (these vary per environment).

**Recommendation**: Delete dead keys, move algorithm thresholds to service constants. Reduce to ~100 lines.

### 3.3 `config/podcast.php` — 118 lines, mostly static

Owner, author, category, feed UIDs ("DO NOT CHANGE") are hardcoded values wrapped in `env()`. These are facts about the organisation, not deployment config.

**Recommendation**: Reduce to ~30 lines. Keep only `enabled` flag and feed route config. Hardcode static metadata.

### 3.4 `config/organization.php` — static data in config

Church name, address, phone number. These don't differ between environments.

**Recommendation**: Hardcode as plain PHP constants or keep as simple config without `env()` wrappers.

---

## 4. Church Service & Song Subsystem — Simplification Opportunities

*Note: This subsystem is still under active development. Recommendations focus on reducing internal complexity, not removing the feature.*

### 4.1 Scale of the Subsystem

| Category | Count | Lines |
|----------|-------|-------|
| Models | 6 | 593 |
| Services | 8 | 2,684 |
| Jobs | 3 | 548 |
| Livewire Components | 6 | 785 |
| Enums | 3 | ~100 |
| Database Migrations | ~12 | ~825 |
| **Total** | **38 files** | **~5,535** |

This is a substantial subsystem (~20% of the application code). As it's still being built out, some complexity is expected, but there are internal simplification opportunities.

### 4.2 Internal Complexity to Review

| Component | Lines | Question |
|-----------|-------|----------|
| `SongCatalogSyncService` | 844 | Largest single service in the app. The deduplication algorithm with representative song selection and canonical key grouping is sophisticated — could it be simpler? |
| `SongClusteringService` | 269 | Visual analysis of video frames to detect songs. Is this actively integrated into the pipeline, or still experimental? If experimental, consider flagging it as such. |
| `OpenLpLyricsParser` | 247 | Parses XML lyrics into structured text. Are parsed lyrics displayed anywhere yet? If not yet, this is fine as pre-work for a future feature. |
| `ServiceSectionClassifier` | 340 | Two-tier confidence matching with anomaly detection. The anomaly detection (segment overlaps, order violations) adds complexity but only flags issues for manual review — could be deferred until the core classification is stable. |
| `ChurchServiceItemSyncService` | 223 | Dual-stage matching (stable → fallback by position). For a system used occasionally, simple replacement-on-reimport might suffice. |

### 4.3 Recommendations

**Simplify internally**:
- `SongCatalogSyncService` is the prime candidate — at 844 lines it's doing a lot. Consider whether the deduplication/clustering logic could be simpler without losing correctness.
- The `ServiceSectionClassifier` anomaly detection could be a separate pass rather than interleaved with classification, making both easier to reason about.
- `ChurchServiceItemSyncService` dual-stage matching may be overbuilt if re-uploads are rare.

**Clarify status**: If `SongClusteringService` or lyrics display are planned-but-not-yet-integrated, consider adding a brief comment or marking them as experimental so future reviewers understand their state.

---

## 5. Unused Dependencies

### 5.1 npm Packages

| Package | Status | Evidence |
|---------|--------|----------|
| `lodash` | Unused | Zero imports in any JS file |
| `ajv` | Unused | Zero imports in any JS file |
| `cross-env` | Unused | Not referenced in npm scripts (Vite handles env natively) |

### 5.2 Composer Packages

| Package | Status | Evidence |
|---------|--------|----------|
| `techwilk/bible-verse-parser` | Unused | Zero references in any PHP file |

### 5.3 Composer Packages to Evaluate

| Package | Status | Notes |
|---------|--------|-------|
| `spatie/laravel-data` | Light usage | 7 Data classes use it. All are simple DTOs with no complex validation or transformation. Plain PHP classes with constructor promotion would work equally well and remove a dependency. |

---

## 6. Unnecessary Abstractions

### 6.1 Contracts/Interfaces

| Interface | Implementations | Verdict |
|-----------|----------------|---------|
| `ProcessingStatusContract` | Multiple processors | Keep — enables polymorphic status queries |
| `SermonAnalysisInterface` | 2 (mock + real) | Keep — enables dev/prod switching |
| `TranscriptionServiceInterface` | 2 (mock + real) | Keep — enables dev/prod switching |
| `ProcessingLogContract` | 1 only | **Delete** — no alternative implementation exists or is planned |
| `SpeakerIdentificationInterface` | 2 (real + mock in tests) | Keep — needed for testability after making always-on |

### 6.2 Traits

| Trait | Usage | Verdict |
|-------|-------|---------|
| `DetectsStorageType` | 2 services | **Delete** — 3 trivial methods; inline the one-liners |
| `HasConditionalLogging` | 2 Livewire files | **Delete** — use `Log::spy()` in tests instead |
| `WithUploadLifecycle` | 1 component only | **Inline** — traits are for code reuse; this isn't reused |
| `WithAdminAuthorization` | 7 components | Keep |
| `WithNotifications` | 9 components | Keep |
| `WithSortableListing` | 6 components | Keep |

### 6.3 Other Unnecessary Abstractions

| Item | Issue | Action |
|------|-------|--------|
| `SermonRepository` | 2 methods, 47 lines | Delete; inline queries |
| `H1` view component | Empty constructor, no logic | Delete; use blade partial |
| `SermonProcessingLogFormatter` | Custom Monolog formatter, 100 lines | Delete; use default JSON/line formatting |
| `SermonProcessingStep` model | 133 lines, not used in application code | Delete model and migration |

---

## 7. Authorization Inconsistency

Three different authorization patterns are in use simultaneously:

1. **Gates** (3 defined in `AuthServiceProvider`): `manage-sermons`, `manage-meetings`, `manage-pages` — **none are ever called**
2. **Policies** (`MeetingPolicy`, `PagePolicy`): Fully defined but **never invoked**
3. **Direct checks**: `auth()->user()?->is_admin` via `WithAdminAuthorization` trait — **actually used everywhere**

**Recommendation**: Delete the 3 unused gates and 2 unused policies. Standardise on the `is_admin` check pattern that's actually in use. Keep `SermonPolicy` (used for `Gate::allows('create', Sermon::class)`).

---

## 8. Model Bloat

### 8.1 Sermon Model (673 lines)

The `Sermon` model has accumulated too many responsibilities:
- 14+ query scopes (many rarely used)
- Storage URL resolution via accessors (should be in a service/presenter)
- Processing status tracking (should be in a service)
- Duplicate logic: instance methods (`isFromLivestream()`) AND scopes (`scopeFromLivestream()`) doing the same thing

**Recommendation**: Extract URL/storage accessors to a presenter. Reduce scopes to the 5-6 that are actually used. Remove instance method duplicates of scopes.

### 8.2 Meeting Model (416 lines)

Heavy delegation to the associated `Page` model via 6+ accessor methods that all follow the same `$this->page?->field` pattern.

**Recommendation**: Create a `delegateToPage()` helper or move display logic to a presenter.

### 8.3 MediaProcessingLog Model (358 lines)

Contains backward-compatibility accessor (`storedFilePath()` mapping to `source_file_path`) and mixes authorization scopes with data queries.

**Recommendation**: Remove backward-compat accessor (fix callers). Extract `scopeVisibleTo()` to a policy or middleware.

---

## 9. Frontend

### 9.1 Dependency Cleanup

Remove from `package.json`:
- `lodash` (devDependency, unused)
- `ajv` (dependency, unused)
- `cross-env` (devDependency, unused — Vite handles env natively)

### 9.2 Alpine.js Loading

Alpine.js is listed in `package.json` dependencies but Livewire 3 auto-includes Alpine. Check whether the explicit npm install creates a duplicate Alpine instance.

---

## 10. Prioritised Action Plan

### Phase 1: Quick Wins (Low Risk, High Impact)

- [ ] Simplify speaker identification: delete `NullSpeakerIdentificationService`, remove feature gates from `IdentifySpeaker` job, remove `enabled`/`shadow_mode` config
- [ ] Delete 7 one-time migration commands
- [ ] Delete `TestJob`, `TestBritishEnglishConverter` command
- [ ] Delete unused blade views (`meetings/create`, `meetings/edit`, `sermons/upload`)
- [ ] Delete dead controller methods (`MeetingController::create/store/edit`, `CalendarController::meetingsIndex`)
- [ ] Delete unused gates and policies (`MeetingPolicy`, `PagePolicy`, 3 gates)
- [ ] Remove unused npm packages (`lodash`, `ajv`, `cross-env`)
- [ ] Remove unused Composer packages (`techwilk/bible-verse-parser`)
- [ ] Delete `SermonProcessingStep` model
- [ ] Delete `ProcessingLogContract` interface
- [ ] Delete `DetectsStorageType` trait (inline)
- [ ] Delete `HasConditionalLogging` trait
- [ ] Delete `H1` view component
- [ ] Delete `SermonRepository` (inline 2 methods)

### Phase 2: Service Consolidation (Medium Risk)

- [ ] Merge `ProcessingResult` + `ProcessingReport` into one class
- [ ] Inline `SermonProcessingService` into callers
- [ ] Inline `SermonStatusManagementService` into callers
- [ ] Merge `LivestreamStatusService` into `LivestreamSegmentationService`
- [ ] Inline `SermonAudioProcessingService`
- [ ] Inline `WithUploadLifecycle` into `MediaUpload/Form.php`
- [ ] Delete `SermonProcessingLogFormatter`
- [ ] Delete `App\Data\LivestreamSegment` DTO (use model)
- [ ] Delete `ProcessingLogEntry` + `ProcessingLogCollection` (use Collections)
- [ ] Consolidate service providers (merge `UrlServiceProvider`, `ModelObserverServiceProvider`, `RateLimitServiceProvider` into `AppServiceProvider`)

### Phase 3: Config Simplification (Low Risk)

- [ ] Reduce `thumbnail-generation.php` from 245 to ~50 lines (move design constants to service)
- [ ] Reduce `media-processing.php` from 246 to ~100 lines (delete dead keys, move thresholds to services)
- [ ] Reduce `podcast.php` from 118 to ~30 lines (hardcode static metadata)
- [ ] Simplify `organization.php` (remove unnecessary `env()` wrappers)

### Phase 4: Church Service Subsystem (Internal Simplification)

- [ ] Review `SongCatalogSyncService` (844 lines) for simpler deduplication approach
- [ ] Separate anomaly detection from classification in `ServiceSectionClassifier`
- [ ] Clarify status of `SongClusteringService` (experimental? integrated?)
- [ ] Simplify `ChurchServiceItemSyncService` dual-stage matching if re-uploads are rare

### Phase 5: Model Refactoring (Medium Risk)

- [ ] Extract Sermon storage/URL logic to presenter
- [ ] Reduce Sermon scopes from 14 to 6
- [ ] Simplify Meeting page delegation
- [ ] Remove MediaProcessingLog backward-compat accessor
- [ ] Evaluate replacing `spatie/laravel-data` DTOs with plain PHP classes

---

## Appendix: Codebase Statistics

| Category | Count |
|----------|-------|
| Services | 62 |
| Models | 18 |
| Controllers | 15 |
| Jobs | 21 |
| Livewire Components | 40 |
| Artisan Commands | 20 |
| Config Files | 30 |
| Enums | 16 |
| Test Files | 236 |
| Blade Views | 100+ |
| **Estimated removable lines** | **~2,600** |
