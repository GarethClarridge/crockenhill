# Refactoring Report - Crockenhill Baptist Church Website

**Date:** February 2026 (Updated: February 9, 2026)
**Laravel Version:** 12.50.0 | **PHP Version:** 8.4.17
**Reviewed By:** Claude Code

## Executive Summary

Significant progress has been made since the initial review. The majority of Priority 1 items are complete - all models now use `casts()` methods, legacy middleware constructors are removed, the sitemap route is extracted to a controller, and the Meeting PascalCase columns are migrated. The controllers have been cleaned up with proper Form Requests and the SermonController has been split. The remaining work centres on deprecated Sermon accessors, missing controller return types, large service files, generic exception handling, and expanding test coverage.

**Overall Assessment:** Strong Laravel 12 compliance. Remaining work is moderate-impact cleanup and test coverage.

| Area | Rating | Summary |
|------|--------|---------|
| Bootstrap & Config | Excellent | Fully L12 compliant, no legacy kernels |
| Livewire & Views | Excellent | Modern Livewire 3, clean Blade components |
| Enums | Excellent | Proper PHP 8.1+ backed enums |
| Models | Excellent | All use `casts()`, proper scope types, all have factories |
| Controllers & Routes | Good | Split well, some missing return types |
| Services | Fair | Large files remain, generic exception catching |
| Tests | Fair | 71 test files, but 67% of services and 88% of jobs untested |

---

## Completed Items

These items from the original report have been fully implemented:

### 1.1 All Models: Convert `$casts` Property to `casts()` Method - COMPLETED

All 8 models now use the `casts()` method pattern:
- ✅ User.php (line 65)
- ✅ Sermon.php (line 127)
- ✅ Meeting.php (line 92)
- ✅ Page.php (line 73)
- ✅ SermonProcessingStep.php (line 41)
- ✅ CalendarEvent.php (line 45)
- ✅ LivestreamSegment.php (line 58)
- ✅ MediaProcessingLog.php (line 102)

### 1.2 Meeting Model: PascalCase Database Columns - COMPLETED

- ✅ Migration created and executed: `2026_02_06_132545_rename_meetings_pascalcase_columns.php`
- ✅ Model, factory, seeder, form requests, Livewire components, Blade views all updated
- ✅ All tests pass

### 1.3 Legacy Controller Middleware Registration - COMPLETED

- ✅ MeetingController no longer has `$this->middleware()` in constructor
- ✅ PasswordController no longer has `$this->middleware()` in constructor
- ✅ Middleware applied at route level instead

### 1.4 Sitemap Route Closure Extracted to Controller - COMPLETED

- ✅ `SitemapController` created with `__invoke()` method
- ✅ Route updated: `Route::get('/sitemap.xml', SitemapController::class)->name('sitemap')`

### 2.1 Auth View Routes - PARTIALLY COMPLETED

- ✅ Auth view routes (`/login`, `/register`, etc.) now correctly use `Route::view()`
- Remaining route closures covered in section below

### 2.2 SermonController Split - COMPLETED

- ✅ SermonController reduced from 405 to ~162 lines (public display + filtering only)
- ✅ Admin operations extracted to `SermonAdminController`
- ✅ Asset serving extracted to `SermonAssetController`

### 2.4 Inline Validation Replaced with Form Requests - COMPLETED

- ✅ `CategorizeEventRequest` created and used in CalendarAdminController
- ✅ `ProcessMediaRequest` created and used in SermonAdminController

### 2.5 Missing Model Factories - COMPLETED

- ✅ `CalendarEventFactory` created
- ✅ `SermonProcessingStepFactory` created
- ✅ CalendarEvent model has `HasFactory` trait
- ✅ All 8 models now have factories

### 2.6 CalendarEvent Scope Methods Type Hints - COMPLETED

- ✅ All scope methods across all models have proper `Builder` parameter type hints

### 2.7 User Model: Unnecessary `$table` Property - COMPLETED

- ✅ Removed

### 3.1 Mock Data Extraction - COMPLETED

- ✅ `MockSermonAnalysisService` extracted as a separate class (447 lines)
- ✅ `MockTranscriptionService` extracted as a separate class (310 lines)

### 3.3 Service Locator Anti-Pattern in Jobs - COMPLETED

- ✅ No `app()` service locator calls found in any job files
- ✅ All jobs use proper constructor dependency injection

### 3.5 Duplicate `getExistingSeries()` Method - COMPLETED

- ✅ Both `SermonAnalysisService` and `ProcessTranscriptWithAI` now delegate to `SermonRepository`

### 4.3 PodcastFeedController Tests - COMPLETED

- ✅ `PodcastFeedTest.php` exists with coverage

### 4.4 Missing API Upload Endpoint Tests - COMPLETED

- ✅ Audio upload: `AutomatedSermonApiTest.php` (15+ test methods)
- ✅ Video upload: `DirectSermonVideoUploadTest.php`, `UnifiedMediaProcessingTest.php`
- ✅ Livestream upload: `LivestreamProcessingApiTest.php`, `LivestreamProcessingIntegrationTest.php`

### 4.6 LivestreamProcessingFailed Mailable Test - PARTIALLY COMPLETED

- ✅ `LivestreamProcessingFailedTest.php` exists with comprehensive coverage

---

## Remaining Items

### Priority 1 - High Impact

#### 1.5 Sermon Model: Deprecated Backward-Compatibility Accessors

**Status:** Cannot remove yet - still actively used in production code.

Three deprecated accessor/mutator pairs at [Sermon.php:754-812](app/Models/Sermon.php#L754-L812):

| Accessor | Maps To | Active Usage |
|----------|---------|-------------|
| `filename` / `setFilenameAttribute` | `audio_file_path` | Used in 3 console commands (MigrateSermonStorageCommand, VerifySermonStorageCommand, MigrateLivestreamAudioFiles) |
| `transcript_path` / `setTranscriptPathAttribute` | `transcript_file_path` | **No usage found** - safe to remove |
| `thumbnail_path` / `setThumbnailPathAttribute` | `thumbnail_file_path` | Used in ThumbnailGenerationService, StandardProcessingResponse, and 3 test files |

**Action:**
1. Remove `getTranscriptPathAttribute()` / `setTranscriptPathAttribute()` immediately (unused)
2. Update `ThumbnailGenerationService`, `StandardProcessingResponse`, and related tests to use `thumbnail_file_path` directly, then remove the accessor
3. Update the 3 console commands to use `audio_file_path` directly, then remove the accessor

---

### Priority 2 - Moderate Impact

#### 2.1 Remaining Route Closures

A few route closures remain in [web.php](routes/web.php):

| Location | Route | Recommendation |
|----------|-------|----------------|
| Line 109-111 | Password reset `reset-password/{token}` | Closure passes `$token` to view - could use `Route::view()` if token is passed as route parameter |
| Line 120 | Admin dashboard redirect | Replace with `Route::redirect('admin', '/church/members')` |
| Lines 148-154 | Admin redirect group (pages/meetings) | Replace closures with `Route::redirect()` where possible |
| Line 172 | `phpinfo` route | Acceptable as closure; consider restricting to local environment |
| Line 229-231 | `500` error test route | Acceptable as closure for testing |

#### 2.3 Missing Return Type Declarations on Controller Methods

**MeetingController** - 4 methods missing return types:
- [MeetingController.php:22](app/Http/Controllers/MeetingController.php#L22) - `index()` - should return `View|RedirectResponse`
- [MeetingController.php:35](app/Http/Controllers/MeetingController.php#L35) - `create()` - should return `View|RedirectResponse`
- [MeetingController.php:61](app/Http/Controllers/MeetingController.php#L61) - `show(Meeting $meeting)` - should return `View`
- [MeetingController.php:119](app/Http/Controllers/MeetingController.php#L119) - `edit(Meeting $meeting)` - should return `View|RedirectResponse`

**PageController** - 2 methods missing return types:
- [PageController.php:24](app/Http/Controllers/PageController.php#L24) - `showPage()` - should return `View`
- [PageController.php:37](app/Http/Controllers/PageController.php#L37) - `show(string $area, string $slug, CommonMarkConverter $converter)` - should return `View`

**Action:** Add explicit return type declarations to all 6 methods.

---

### Priority 3 - Service Layer Refactoring

#### 3.1 Large Service Files (Updated Line Counts)

Four services remain oversized. `SermonAnalysisService` reduced from 1,217 to 738 lines after mock extraction.

| Service | Lines | Issue |
|---------|-------|-------|
| [AudioTranscriptionService.php](app/Services/AudioTranscriptionService.php) | 1,131 | Transcription + chunking + compression + validation |
| [ThumbnailGenerationService.php](app/Services/ThumbnailGenerationService.php) | 1,056 | Multiple thumbnail strategies + frame extraction + storage |
| [VideoExtractionService.php](app/Services/VideoExtractionService.php) | 942 | Extraction + retry logic + storage management |
| [VideoSegmentationService.php](app/Services/VideoSegmentationService.php) | 898 | RMS analysis + segment parsing + threshold logic |

**Recommended extractions:**
- Extract `AudioChunkingService` from `AudioTranscriptionService`
- Extract `FrameExtractionService` from `ThumbnailGenerationService`
- Extract `RmsAnalysisService` from `VideoSegmentationService`

#### 3.2 Constructor Property Promotion

13 of 43 services use PHP 8 constructor property promotion. Of the remaining 30:
- 16 have no constructor at all (stateless services - acceptable as-is)
- 6 have constructors with config initialisation logic (not candidates for simple promotion)
- 8 could benefit from refactoring to use constructor property promotion

**Note:** Services without injected dependencies don't need constructors. The main concern is services that manually assign injected dependencies to properties instead of using promotion.

#### 3.4 Overly Generic Exception Catching

**~126 instances** of `catch (\Exception $e)` across 27 service files. This is the most widespread remaining issue.

Key offenders:
- **ThumbnailGenerationService** - 13+ generic catch blocks
- **VideoExtractionService** - 8+ generic catch blocks
- **VideoSegmentationService** - 4+ generic catch blocks
- **AudioTranscriptionService** - 4+ generic catch blocks (some already catch specific types first)
- **SermonAnalysisService** - 3+ generic catch blocks

**Action:** Create a custom exception hierarchy and replace generic catches incrementally:
```
App\Exceptions\
├── ProcessingException (base)
├── TranscriptionException
├── VideoProcessingException
├── ThumbnailGenerationException
└── SegmentationException
```

#### 3.6 Minor: Service Locator in MockSermonAnalysisService

[MockSermonAnalysisService.php:442](app/Services/MockSermonAnalysisService.php#L442) uses `app(BritishEnglishConverter::class)` instead of constructor injection.

**Action:** Inject `BritishEnglishConverter` via constructor.

---

### Priority 4 - Test Suite Improvements

#### 4.1 Missing Service Layer Tests

Only 14 of 42 services have dedicated tests (33% coverage). Key untested services:

**Core processing (high priority):**
- SermonProcessingService (orchestrator)
- UnifiedMediaProcessor (main entry point)
- VideoProcessingService
- VideoExtractionService
- ThumbnailGenerationService

**Supporting services (medium priority):**
- SermonAnalysisService (AI analysis)
- CalendarService
- PodcastFeedService
- BritishEnglishConverter
- SermonStorageService
- VideoStorageService

#### 4.2 Missing Job Tests

Only 2 of 17 jobs have dedicated tests (12% coverage). Critical untested jobs:

- `ProcessingJob` (main orchestrator)
- `ValidateVideoFile` / `ValidateAudioFile`
- `ExtractSermon`
- `GenerateThumbnail`
- `TranscribeAudio`
- `ProcessTranscriptWithAI`
- `CreateSermonRecord`
- `UpdateSermonRecord`
- `CleanupTemporaryFiles`
- `SendCompletionNotification`

Each should have dispatch assertions, retry logic tests, and failure handling tests.

#### 4.3 Missing Controller Tests

Two controllers still lack test coverage:
- `CalendarAdminController` - no dedicated tests
- `MemberController` - no dedicated tests

#### 4.5 Inconsistent Database Trait Usage

Tests mix `DatabaseTransactions` (14 files) and `RefreshDatabase` (40 files) without documented rationale. Consider standardising on `RefreshDatabase` and documenting when `DatabaseTransactions` is preferred.

#### 4.6 Missing Mailable Tests

4 of 5 Mailable classes still lack dedicated tests:
- `DiskSpaceWarning` (tested indirectly via `LivestreamErrorHandlerTest` only)
- `LivestreamProcessingCompleted` (no test)
- `ManualReviewRequired` (tested indirectly via `LivestreamErrorHandlerTest` only)
- `PermissionError` (tested indirectly via `LivestreamErrorHandlerTest` only)

---

### Priority 5 - Low Impact / Nice-to-Have

#### 5.1 Page Model: Duplicate `.webp` File Check Bug - CONFIRMED

**Bug verified** in 3 methods. The `.jpg` fallback incorrectly checks the `.webp` path again:

- [Page.php:315-318](app/Models/Page.php#L315-L318) - `getHeadingImageTabletUrlAttribute()`: `$jpgPath` assigned `.webp` instead of `.jpg`
- [Page.php:350-353](app/Models/Page.php#L350-L353) - `getHeadingImageMobileUrlAttribute()`: same bug
- [Page.php:389-392](app/Models/Page.php#L389-L392) - `getHeadingImageSmallUrlAttribute()`: same bug

**Impact:** The fallback logic never actually checks for `.jpg` files. Should be fixed.

#### 5.2 Sermon Model: FQCN Instead of Import

[Sermon.php:280](app/Models/Sermon.php#L280) still uses fully-qualified `\Illuminate\Database\Eloquent\Relations\BelongsTo` instead of importing the class.

**Action:** Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` import.

#### 5.3 phpinfo Route

[web.php:172](routes/web.php#L172) - Protected by admin middleware, which is acceptable. Consider adding an environment check:
```php
Route::get('phpinfo', fn () => app()->isLocal() ? phpinfo() : abort(404))->middleware('admin');
```

#### 5.4 Permanent Redirects Could Be Consolidated

~46 individual `Route::permanentRedirect()` calls remain in [web.php](routes/web.php). Could be consolidated into a config-driven redirect map.

---

## What's Already Done Well

These areas are at or above Laravel 12 standards and need no changes:

- **Bootstrap configuration** - Proper `bootstrap/app.php` with `withMiddleware()`, `withRouting()`, `withSchedule()`, `withExceptions()`
- **No legacy kernel files** - Neither `app/Http/Kernel.php` nor `app/Console/Kernel.php` exist
- **Service providers** - All providers follow L12 patterns with proper return types
- **Enums** - All use PHP 8.1+ backed enums with match expressions
- **Zero `env()` violations** - All `env()` calls are correctly inside config files only
- **Models** - All 8 models use `casts()` method, all have factories, all have `HasFactory` trait, all scope methods properly typed
- **Livewire 3 components** - Excellent type declarations, validation attributes, proper traits
- **Blade views** - Modern component syntax, no inline styles, clean Alpine.js integration
- **API Resources** - `SermonResource` used correctly for API responses
- **Route model binding** - Consistent and correct throughout
- **Named routes** - All routes properly named
- **Form Requests** - Inline validation eliminated; `CategorizeEventRequest` and `ProcessMediaRequest` created
- **PHPDoc** - Comprehensive on models and most services
- **Health checks** - L12 `DiagnosingHealth` event listeners in AppServiceProvider
- **Controller separation** - SermonController properly split into display, admin, and asset controllers
- **Service return types** - All 43 services have explicit return type declarations on all public methods
- **API upload tests** - Comprehensive coverage for audio, video, and livestream endpoints
- **Repository pattern** - `SermonRepository` used to eliminate duplication

---

## Suggested Refactoring Order

1. **Quick wins (30 mins):** Add return types to 6 controller methods, remove unused `transcript_path` accessor, fix 3x `.webp` fallback bug, add `BelongsTo` import to Sermon model
2. **Deprecated accessor migration (1-2 hours):** Update `ThumbnailGenerationService` + `StandardProcessingResponse` + tests to use `thumbnail_file_path`, update console commands to use `audio_file_path`, then remove deprecated accessors
3. **Route cleanup (30 mins):** Replace remaining closures with `Route::redirect()`, add environment check to phpinfo route
4. **Exception hierarchy (2-3 hours):** Create custom exception classes, incrementally replace generic `catch (\Exception)` blocks in the 4 largest services
5. **Service extraction (ongoing):** Extract `AudioChunkingService`, `FrameExtractionService`, `RmsAnalysisService` from oversized services
6. **Test coverage (ongoing):** Prioritise tests for core processing services, then jobs, then mailables
