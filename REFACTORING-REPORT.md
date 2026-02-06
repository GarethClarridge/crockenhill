# Refactoring Report - Crockenhill Baptist Church Website

**Date:** February 2026
**Laravel Version:** 12.50.0 | **PHP Version:** 8.4.17
**Reviewed By:** Claude Code

## Executive Summary

This project has been well-maintained through its upgrade from Laravel 5 to Laravel 12. The overall architecture is solid - bootstrap/app.php, providers, middleware, enums, Livewire components, and Blade views all follow modern Laravel 12 patterns. However, there are several legacy remnants and inconsistencies that would benefit from refactoring.

**Overall Assessment:** Good foundation with targeted refactoring opportunities.

| Area | Rating | Summary |
|------|--------|---------|
| Bootstrap & Config | Excellent | Fully L12 compliant, no legacy kernels |
| Livewire & Views | Excellent | Modern Livewire 3, clean Blade components |
| Enums | Excellent | Proper PHP 8.1+ backed enums |
| Models | Good | Working well but need modernisation |
| Controllers & Routes | Good | Some legacy patterns remain |
| Services | Fair | Several SRP violations, large files |
| Tests | Good | 69 test files, gaps in service coverage |

---

## Priority 1 - High Impact Refactoring

### 1.1 All Models: Convert `$casts` Property to `casts()` Method

All 8 models use the legacy `$casts` property instead of the Laravel 12 preferred `casts()` method.

**Affected files:**
- [User.php:73](app/Models/User.php#L73)
- [Sermon.php:131](app/Models/Sermon.php#L131)
- [Meeting.php:94](app/Models/Meeting.php#L94)
- [Page.php:77](app/Models/Page.php#L77)
- [SermonProcessingStep.php:43](app/Models/SermonProcessingStep.php#L43)
- [CalendarEvent.php:39](app/Models/CalendarEvent.php#L39)
- [LivestreamSegment.php:55](app/Models/LivestreamSegment.php#L55)
- [MediaProcessingLog.php:99](app/Models/MediaProcessingLog.php#L99)

**Before:**
```php
protected $casts = [
    'email_verified_at' => 'datetime',
    'is_admin' => 'boolean',
    'password' => 'hashed',
];
```

**After:**
```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'is_admin' => 'boolean',
        'password' => 'hashed',
    ];
}
```

---

### 1.2 Meeting Model: PascalCase Database Columns

The `meetings` table uses PascalCase column names inherited from the original Laravel 5 schema. This violates Laravel conventions and creates inconsistency with all other tables.

**Columns to rename:**
| Current (PascalCase) | Should Be (snake_case) |
|---|---|
| `StartTime` | `start_time` |
| `EndTime` | `end_time` |
| `LeadersPhone` | `leaders_phone` |
| `LeadersEmail` | `leaders_email` |

**Action:** ~~Create a migration to rename columns, then update the model `$fillable`, casts, and all references throughout the codebase.~~ **COMPLETED** (Feb 6, 2026)

- ✅ Migration created and executed: `2026_02_06_132545_rename_meetings_pascalcase_columns.php`
- ✅ Model updated: PHPDoc, $fillable, casts(), methods
- ✅ Factory updated: 4 fields in definition() + 3 state methods
- ✅ Seeder updated: 52 key renames (13 meetings × 4 fields)
- ✅ Form requests updated: Validation rules
- ✅ Livewire components updated: mount() and save() methods
- ✅ Blade views updated: All property access in 4 views
- ✅ All tests pass: 665/668 passed (3 pre-existing failures)

---

### 1.3 Legacy Controller Middleware Registration

Two controllers use the pre-Laravel 12 pattern of registering middleware in the constructor via `$this->middleware()`. This should be handled at the route level instead.

**MeetingController** - [MeetingController.php:22-25](app/Http/Controllers/MeetingController.php#L22-L25):
```php
public function __construct()
{
    $this->middleware('auth')->except(['show', 'showCommunityContent']);
}
```

**PasswordController** - [PasswordController.php:36-39](app/Http/Controllers/Auth/PasswordController.php#L36-L39):
```php
public function __construct()
{
    $this->middleware('guest');
}
```

**Action:** Remove these constructors and apply middleware at the route level in `routes/web.php`.

---

### 1.4 Sitemap Route Closure Should Be a Controller

The sitemap generation logic is ~35 lines of business logic inside a route closure in [web.php:179-213](routes/web.php#L179-L213). This should be extracted to a dedicated controller.

**Action:** Create `SitemapController` with a single `__invoke()` method.

---

### 1.5 Sermon Model: Remove Deprecated Backward-Compatibility Accessors

The Sermon model contains 6 deprecated accessor/mutator methods that map old property names to new ones. These are marked `@deprecated` at [Sermon.php:758-813](app/Models/Sermon.php#L758-L813).

**Deprecated methods:**
- `getFilenameAttribute()` / `setFilenameAttribute()` - maps to `audio_file_path`
- `getTranscriptAttribute()` / `setTranscriptAttribute()` - maps to `transcript_file_path`
- `getImageAttribute()` / `setImageAttribute()` - maps to `thumbnail_file_path`

**Action:** Search the codebase for any remaining usage of `->filename`, `->transcript`, `->image` on Sermon instances. If none found, remove these accessors.

---

## Priority 2 - Moderate Impact Refactoring

### 2.1 Route Closures That Should Be Controller Methods

Several route closures in [web.php](routes/web.php) contain logic that belongs in controllers:

| Lines | Route | Recommendation |
|-------|-------|----------------|
| 104-118 | Auth view routes (`/login`, `/register`, etc.) | Create invokable view controllers or use `Route::view()` |
| 150-157 | Admin redirect routes | Use `Route::redirect()` or `Route::permanentRedirect()` |
| 176 | `phpinfo` route | Move to admin controller or remove |
| 267-269 | `500` error test route | Remove (handled by exception handler) |

**Simplest fix for auth views:**
```php
Route::view('login', 'auth.login')->name('login');
Route::view('register', 'auth.register')->name('register');
```

---

### 2.2 SermonController Is Too Large (405 Lines)

[SermonController.php](app/Http/Controllers/SermonController.php) handles display, filtering, admin operations, and asset serving - too many responsibilities.

**Suggested split:**
- `SermonController` - Public display only (`index`, `show`, `showWithDate`, `getAll`)
- `SermonFilterController` - Filtering (`getPreacher`, `getSeries`, `getService`)
- `SermonAdminController` - Admin operations (`edit`, `update`, `destroy`, `upload`, `processMedia`)
- `SermonAssetController` - File serving (`serveAudio`, `serveThumbnail`)

---

### 2.3 Missing Type Declarations on Controller Methods

Several controller methods lack return type hints or parameter type hints:

- [SermonController.php](app/Http/Controllers/SermonController.php): `getPreacher($preacher)`, `getSeries($series)`, `getService($service)` - missing `string` parameter types
- [SermonController.php](app/Http/Controllers/SermonController.php): `serveAudio()`, `serveThumbnail()` - missing return types
- [CalendarController.php](app/Http/Controllers/CalendarController.php): Multiple methods missing return types
- [CalendarAdminController.php](app/Http/Controllers/Admin/CalendarAdminController.php): `categorizeEvent()` missing return type

---

### 2.4 Inline Validation Should Use Form Requests

Two places use inline `$request->validate()` instead of Form Request classes:

1. [CalendarAdminController.php:23](app/Http/Controllers/Admin/CalendarAdminController.php#L23) - `categorizeEvent()` has inline validation
2. [SermonController.php:250-284](app/Http/Controllers/SermonController.php#L250-L284) - `processMedia()` uses `$this->authorize()` + inline validation

**Action:** Create `CategorizeEventRequest` and `ProcessMediaRequest` Form Request classes.

---

### 2.5 Missing Model Factories

Two models lack factories, preventing proper testing:

- **CalendarEvent** - No factory, and missing `HasFactory` trait on the model ([CalendarEvent.php](app/Models/CalendarEvent.php))
- **SermonProcessingStep** - No factory ([SermonProcessingStep.php](app/Models/SermonProcessingStep.php))

---

### 2.6 CalendarEvent Scope Methods Missing Type Hints

[CalendarEvent.php:50-60](app/Models/CalendarEvent.php#L50-L60) - Scope method `$query` parameters lack `Builder` type hints:

```php
// Current
public function scopeUpcoming($query): Builder

// Should be
public function scopeUpcoming(Builder $query): Builder
```

---

### 2.7 User Model: Unnecessary `$table` Property

[User.php:44](app/Models/User.php#L44) explicitly sets `protected $table = 'users'` which is the default convention and can be removed.

---

## Priority 3 - Service Layer Refactoring

### 3.1 Large Service Files Violating Single Responsibility

Five services exceed recommended size limits and handle too many concerns:

| Service | Lines | Issue |
|---------|-------|-------|
| [AudioTranscriptionService.php](app/Services/AudioTranscriptionService.php) | 1,241 | Transcription + chunking + compression + validation |
| [SermonAnalysisService.php](app/Services/SermonAnalysisService.php) | 1,217 | AI analysis + ~450 lines of mock data generation |
| [ThumbnailGenerationService.php](app/Services/ThumbnailGenerationService.php) | 1,058 | Multiple thumbnail strategies + frame extraction + storage |
| [VideoExtractionService.php](app/Services/VideoExtractionService.php) | 942 | Extraction + retry logic + storage management |
| [VideoSegmentationService.php](app/Services/VideoSegmentationService.php) | 898 | RMS analysis + segment parsing + threshold logic |

**Recommended extractions:**
- Extract `MockSermonAnalysisService` from `SermonAnalysisService` (~400 lines saved)
- Extract `AudioChunkingService` from `AudioTranscriptionService`
- Extract `RmsAnalysisService` from `VideoSegmentationService`
- Extract `FrameExtractionService` from `ThumbnailGenerationService`

---

### 3.2 Inconsistent Constructor Property Promotion

Only ~12 of ~30+ services use PHP 8 constructor property promotion. The rest use the legacy pattern.

**Example of legacy pattern** (found in many services):
```php
public function __construct(MediaProcessingLogger $logger)
{
    $this->logger = $logger;
}
```

**Should be:**
```php
public function __construct(
    private readonly MediaProcessingLogger $logger
) {}
```

---

### 3.3 Service Locator Anti-Pattern in Jobs

Some jobs use `app()` to resolve dependencies instead of constructor injection:

```php
// Anti-pattern found in jobs
$transcriptionService = app(TranscriptionServiceInterface::class);
```

**Action:** Inject dependencies via the job constructor instead.

---

### 3.4 Overly Generic Exception Catching

Many services use `catch (\Exception $e)` which is too broad and can hide bugs. Consider catching specific exception types:

```php
// Current (too broad)
catch (\Exception $e) { ... }

// Better
catch (TranscriptionException | FileNotFoundException $e) { ... }
```

**Action:** Create a custom exception hierarchy (`ProcessingException`, `TranscriptionException`, `VideoProcessingException`) and update catch blocks.

---

### 3.5 Duplicate Code Across Services

The `getExistingSeries()` method is duplicated between `SermonAnalysisService` and `ProcessTranscriptWithAI` job. This should be extracted to a shared service or repository.

---

## Priority 4 - Test Suite Improvements

### 4.1 Missing Service Layer Tests

Only 23 of 41 services have dedicated tests (56% coverage). Key untested services:

- SermonProcessingService (core orchestrator)
- UnifiedMediaProcessor (main entry point)
- SermonAnalysisService (AI analysis)
- VideoProcessingService
- CalendarService
- PodcastFeedService
- BritishEnglishConverter

---

### 4.2 Missing Job Tests

Only 2-3 of 15+ background jobs have dedicated tests. Processing jobs (`TranscribeAudio`, `ProcessTranscriptWithAI`, `CreateSermonRecord`, `ExtractSermon`) are critical and should have:
- Dispatch assertions
- Retry logic tests
- Failure handling tests

---

### 4.3 Missing Controller Tests

Several controllers lack direct test coverage:
- `CalendarAdminController`
- `PodcastFeedController`
- `MemberController`

---

### 4.4 Missing API Upload Endpoint Tests

POST endpoints for `/api/sermons/audio`, `/api/sermons/video`, and `/api/sermons/livestream` lack tests for:
- File upload validation
- Multipart form data handling
- Processing pipeline triggering

---

### 4.5 Inconsistent Database Trait Usage

Tests mix `DatabaseTransactions` (14 files) and `RefreshDatabase` (40 files) without clear rationale. Consider standardising on one approach and documenting when the other is needed.

---

### 4.6 Missing Email/Mailable Tests

Five Mailable classes exist in `app/Mail/` but none have dedicated tests:
- `DiskSpaceWarning`
- `LivestreamProcessingCompleted`
- `LivestreamProcessingFailed`
- `ManualReviewRequired`
- `PermissionError`

---

## Priority 5 - Low Impact / Nice-to-Have

### 5.1 Page Model: Potential Duplicate File Check Bug

Multiple methods in [Page.php](app/Models/Page.php) around lines 282, 317, 352, 391 check for `.webp` files but the fallback logic may check for `.webp` again instead of `.jpg`.

**Action:** Audit the heading image fallback logic for correctness.

---

### 5.2 Sermon Model: FQCN Instead of Import

[Sermon.php:281](app/Models/Sermon.php#L281) uses a fully-qualified class name for the return type instead of an import:

```php
public function livestreamProcessing(): \Illuminate\Database\Eloquent\Relations\BelongsTo
```

**Action:** Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` import.

---

### 5.3 phpinfo Route Exposed

[web.php:176](routes/web.php#L176) exposes `phpinfo()` behind the admin middleware. While protected, this is a security concern in production.

**Action:** Consider removing or restricting to local environment only.

---

### 5.4 Permanent Redirects Could Be Consolidated

[web.php:215-269](routes/web.php#L215-L269) contains ~30 individual `Route::permanentRedirect()` calls for legacy URL mappings. These could be consolidated into a config array or redirect map for cleaner route files.

---

### 5.5 `$hidden` Property Comment Style

[User.php:59-62](app/Models/User.php#L59-L62) has a PHPDoc that says "The attributes excluded from the model's JSON form" which is slightly outdated phrasing. Minor cosmetic issue.

---

## What's Already Done Well

These areas are already at or above Laravel 12 standards and need no changes:

- **Bootstrap configuration** - Proper `bootstrap/app.php` with `withMiddleware()`, `withRouting()`, `withSchedule()`, `withExceptions()`
- **No legacy kernel files** - Neither `app/Http/Kernel.php` nor `app/Console/Kernel.php` exist
- **Service providers** - All 5 providers follow L12 patterns with proper return types
- **Enums** - All use PHP 8.1+ backed enums with match expressions
- **Zero `env()` violations** - All `env()` calls are correctly inside config files only
- **Livewire 3 components** - Excellent type declarations, validation attributes, proper traits
- **Blade views** - Modern component syntax, no inline styles, clean Alpine.js integration
- **API Resources** - `SermonResource` used correctly for API responses
- **Route model binding** - Consistent and correct throughout
- **Named routes** - All routes properly named
- **PHPDoc** - Comprehensive on models and most services
- **Health checks** - L12 `DiagnosingHealth` event listeners in AppServiceProvider

---

## Suggested Refactoring Order

1. **Quick wins** (1-2 hours): Convert `$casts` to `casts()` on all 8 models, remove legacy `$this->middleware()` from 2 controllers, convert auth route closures to `Route::view()`
2. **Database migration** (1-2 hours): Rename Meeting PascalCase columns, update all references
3. **Controller cleanup** (2-3 hours): Split SermonController, extract SitemapController, add missing type hints, create missing Form Requests
4. **Model cleanup** (1 hour): Add missing factories, remove deprecated Sermon accessors, fix CalendarEvent scope types
5. **Service refactoring** (ongoing): Extract large services incrementally, standardise constructor patterns
6. **Test coverage** (ongoing): Add tests for untested services and jobs
