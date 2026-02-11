Codebase Review - February 2026
Scope: Full fresh review of all application code against Laravel 12 / PHP 8.4 modern standards.

Overall Assessment: The codebase is in strong shape following the previous refactoring rounds. The issues below are primarily legacy remnants and consistency gaps, not structural problems.

Priority 1 - Remove Legacy / Deprecated Patterns
1.1 Legacy Exception Handler Should Be Removed
Handler.php extends the old ExceptionHandler class — a pattern removed in Laravel 11+. Exception handling is already configured in bootstrap/app.php:30 via withExceptions(). The Handler class adds nothing (its report() just calls parent, its render() casts responses). This file should be deleted.

1.2 Deprecated HandlesAuthorization Trait in Policies
SermonPolicy.php:11 and PagePolicy.php:11 use Illuminate\Auth\Access\HandlesAuthorization, which was deprecated in Laravel 10. Policies now return booleans natively — the trait import and usage should be removed. MeetingPolicy already does this correctly.

1.3 Policies Have Placeholder Email Arrays
SermonPolicy.php:20-23 and PagePolicy.php:20-23 contain empty-string email arrays as authorization logic. This effectively means no user can pass these checks (all authorization comes from a Gate::before elsewhere). These policies could be simplified to delegate to $user->is_admin like MeetingPolicy does, unless the separate Gate::before logic is intentionally kept.

Priority 2 - Consistency and Type Safety
2.1 Missing declare(strict_types=1) on Several Controllers
These controllers lack the strict types declaration that most others have:

CalendarController.php
SermonController.php
PodcastFeedController.php
SitemapController.php
CalendarAdminController.php
2.2 Missing Return Type Declarations
CalendarAdminController.php:14: uncategorizedEvents() missing : View
CalendarAdminController.php:36: patternManagement() missing : View
StorePageRequest.php: authorize() missing : bool, rules() missing : array
UpdatePageRequest.php: authorize() missing : bool, rules() missing : array
EnsureUserIsAdmin.php: handle() missing : Response return type
VerifyEmail.php: resend() and render() both missing return types
2.3 Untyped Livewire Property
MediaUploadField.php:20: public $file; lacks a type declaration. Should be typed or have a PHPDoc annotation.

2.4 MeetingType Enum Value Inconsistency
MeetingType.php:7-10 uses TitleCase values (SundayAndBibleStudies, ChildrenAndYoungPeople) while all other enums use lowercase (morning, pending, christ). This is a database-stored value so changing it requires a migration, but it's worth noting the inconsistency.

Priority 3 - Service Layer and DI Issues
3.1 Service Locator in SermonAssetController
SermonAssetController.php:23 uses app(\App\Services\SermonStorageService::class) instead of constructor injection. Should use:


public function __construct(private SermonStorageService $storageService) {}
3.2 TranscribeAudio Job - Service Serialization Risk
TranscribeAudio.php:31-34 injects TranscriptionServiceInterface in the constructor. Since queued jobs serialize their constructor properties, injecting a service object here can cause serialization failures. The service should be injected via the handle() method parameter instead.

3.3 ProcessTranscriptWithAI - Direct Instantiation
ProcessTranscriptWithAI.php:36 uses new SermonRepository as a default constructor parameter value. Dependencies should be resolved from the container, not instantiated directly. Move to handle() parameter injection.

3.4 GenerateThumbnail - Variadic Constructor
GenerateThumbnail.php:93-114 uses a variadic ...$args constructor with runtime type checking. This obscures the API and makes IDE support difficult. Consider using named parameters or a static factory method.

3.5 VideoSegmentationService - Fallback Service Locator
VideoSegmentationService.php:32 uses app(RmsAnalysisService::class) as a fallback when constructor parameter is null. The nullable parameter + app() pattern should be replaced with proper container resolution.

Priority 4 - Security and Error Handling
4.1 CategorizeEventRequest - Overly Permissive Authorization
CategorizeEventRequest.php:12-15 authorizes any authenticated user ($this->user() !== null). Calendar event categorization is an admin operation — should check $this->user()?->is_admin.

4.2 MeetingController - Unfiltered scandir()
MeetingController.php:79-83 uses scandir() directly on a public directory without filtering file types. Should use the Storage facade and filter to image extensions only.

4.3 Unescaped HTML Output in Page Layout
page.blade.php:51 outputs {!! $content !!} (unescaped). This is intentional for rendered Markdown/HTML content, but the page edit form at edit.blade.php:108 also uses {!!$page->body!!} — ensure this content is sanitized before storage.

4.4 SitemapController - Unhandled File Read
SitemapController.php:23 calls file_get_contents() without error handling. If the sitemap file is missing, this will throw an unhandled warning.

Priority 5 - View Layer Modernisation
5.1 Legacy Blade Patterns in Page Edit Form
edit.blade.php uses several legacy patterns:

Line 10: Manual <input type="hidden" name="_method" value="PUT"> instead of @method('PUT')
Line 11: Manual <input type="hidden" name="_token" value="{{ csrf_token() }}"> instead of @csrf
Lines 33-51: Manual <option> elements with @if checks for selected state instead of @selected() directive
Line 77: $_SERVER['DOCUMENT_ROOT'] usage — should use Laravel's public_path() or Storage facade
5.2 Livewire Directive Deprecation
guest-layout.blade.php:8 uses @livewireStyles and @livewireScripts. In Livewire 3, these are typically handled by @livewireStyles/@livewireScripts automatically or via @persist, but the main app layout should be checked for consistency.

5.3 Route Closures Remaining
web.php:172 has phpinfo as a closure and web.php:186 has a 500 test route closure. The phpinfo route is acceptable as a debug route, but the 500 route should be development-only (guarded by environment check).

Priority 6 - Database and Seeder
6.1 SermonSeeder - Stale Column Name
The SermonSeeder may still reference filename instead of audio_file_path, which could cause mass assignment issues. Should be verified and updated.

6.2 Inconsistent Database Test Traits
Tests mix DatabaseTransactions (14 files) and RefreshDatabase (40 files) without documented rationale. Standardising on RefreshDatabase (or documenting when each is preferred) would improve consistency.

Summary Table
Area	Rating	Key Issues
Bootstrap & Config	Excellent	Fully L12 compliant
Models & Enums	Excellent	MeetingType value inconsistency only
Controllers	Good	Missing strict types, a few missing return types
Services	Good	2-3 DI issues, service locator calls
Jobs	Good	Serialization risk in TranscribeAudio, variadic constructor
Mailables	Excellent	All modern Envelope/Content API
Policies	Needs Work	Deprecated trait, placeholder auth logic
Exception Handler	Needs Work	Legacy Handler.php should be removed
Views	Good	One legacy form, some unescaped output
Database	Excellent	No structural issues
Tests	Good	Trait inconsistency, 56% service coverage
The highest-value changes would be removing the legacy Exception Handler (1.1), dropping the deprecated HandlesAuthorization trait (1.2), and fixing the job serialization risk (3.2). Everything else is consistency/hygiene improvements.