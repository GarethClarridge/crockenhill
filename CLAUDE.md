# CLAUDE.md

This file provides guidance to Claude Code when working with this Laravel church website.

## Project Overview

**Crockenhill Baptist Church Website** - A Laravel-based church management system that handles:
- **Pages**: Static content organized by areas (Christ, Church, Community, Members)
- **Sermons**: Audio recordings with metadata, transcription, and AI analysis
- **Meetings**: Church events with scheduling and recurring support
- **Livestream Processing**: Automated video segmentation and sermon extraction from recordings

**Tech Stack**: TALL (Tailwind, Alpine.js, Livewire, Laravel) + FFmpeg for media processing
**Storage**: Hybrid local/cloud system with DigitalOcean Spaces integration

## Design System

- **Style Guide**: Always read `docs/design-style-guide.md` before any UI work. It defines brand tokens, component conventions, spacing, and legacy anti-patterns to avoid.
- **Component Gallery**: Browse all shared components rendered live at `/dev/components` (local only).
- **Visual References**: See `docs/design-references/` for screenshots of key page types (gitignored — regenerate using commands in the style guide).
- **Custom skill**: Use the `frontend-design` skill when building or modifying any UI. Defined at `.claude/skills/frontend-design.md` — project-specific and overrides generic design advice.

## Architecture Highlights

### Core Models
- **Sermon**: Audio/video recordings with preacher, series, Bible reference metadata
- **Page**: Static content with `PageArea` enum (christ, church, community, members, sermons)
- **Meeting**: Events with `MeetingFrequency` enum (daily, weekly, monthly, annually)
- **User**: Authentication for members area

### Key Services
- **ProcessingRouter**: Routes media uploads to appropriate processors based on type
- **VideoProcessingService**: Handles video processing (segmentation for livestreams, direct processing for sermons)
- **SermonProcessingService**: Audio processing, transcription, and AI-powered analysis
- **VideoSegmentationService**: FFmpeg-based analysis and segment extraction
- **VideoExtractionService**: S3-aware extraction with hybrid processing (local→cloud)
- **VideoStorageService**: S3-compatible operations with local fallback
- **SermonStorageService**: Multi-pattern file detection and CDN URL generation
- **ThumbnailGenerationService**: Branded thumbnail generation from sermon video frames
- **PageImageService**: Image processing and storage for page heading images

### Processing Pipelines
1. **Audio**: Direct transcription and AI analysis → sermon record
2. **Video**: Full extraction, video preservation → sermon record with video
3. **Livestream**: RMS analysis → segmentation → sermon extraction → dual preservation

### Storage Architecture
- **Hybrid Processing**: S3-compatible disks process locally then upload; local disks process directly
- **Auto-detection**: Services automatically detect S3 storage (DigitalOcean Spaces, AWS S3)
- **Retry Logic**: Exponential backoff for S3 uploads with graceful fallback

### API Endpoints
- `POST /api/sermons/{audio|video|livestream}` - Unified upload endpoints
- `GET /api/sermons/processing/{id}/status` - Unified status checking (ProcessingStatusContract)
- `DELETE /api/sermons/processing/{id}` - Cancel processing

## Development Workflow

### Essential Commands
```bash
# Frontend
vendor/bin/sail npm run dev    # Development with hot reload
vendor/bin/sail npm run build  # Production build

# Backend (Laravel Sail)
vendor/bin/sail up -d                  # Start development server
vendor/bin/sail artisan migrate        # Run migrations
vendor/bin/sail artisan db:seed        # Seed with sample data + mock transcripts

# Testing — always run in parallel for speed
vendor/bin/sail artisan test --parallel --compact          # All tests
vendor/bin/sail artisan test --compact tests/Path/To/Test  # Single file
vendor/bin/sail artisan test --compact --filter=testName   # Single test

# Code Quality — run both before finishing any change
vendor/bin/sail composer phpstan       # Static analysis (must stay at 0 errors)
vendor/bin/sail bin pint --dirty       # Auto-fix formatting on changed files
```

### Email Configuration

**Development (Sail):** Uses **Mailpit** - a local email testing tool with web UI
**Production:** Uses **Mailgun** transactional email service (100 free emails/day)

**Testing Emails in Development:**
```bash
# Send a test email
sail artisan tinker
>>> use App\Mail\LivestreamProcessingCompleted;
>>> Mail::to('test@example.com')->queue(new LivestreamProcessingCompleted('test-123', [
    'sermon_count' => 2,
    'total_duration' => '1:23:45'
]));
>>> exit

# View the email in Mailpit's web interface
# Open: http://localhost:8025
```

**Configuration:**
- `MAIL_FROM_ADDRESS` - Sender email (default: `admin@crockenhill.org`)
- `LIVESTREAM_ADMIN_EMAIL` - Receives all processing error/success alerts
- Mailpit configured automatically by Laravel Sail (mailpit:1025)

**Mailable Classes** (`app/Mail/`):
- `DiskSpaceWarning` - Critical disk space alerts
- `LivestreamProcessingCompleted` - Success notifications with sermon details
- `LivestreamProcessingFailed` - Failure notifications with stack traces
- `ManualReviewRequired` - Segmentation review alerts
- `PermissionError` - File permission issues

**Email Queueing:**
All emails use `Mail::queue()` for async delivery. Queue driver (`QUEUE_DRIVER`):
- `sync` (default) - Processes immediately, no background worker needed
- `database` - Queues in database (requires `sail artisan queue:work`)

**Notification Toggles** (`config/media-processing.php`):
- `email.send_success_notifications` - Success emails (default: false)
- `email.send_failure_notifications` - Failure emails (default: true)

### Testing Strategy
- **Always run in parallel**: `--parallel` is mandatory for speed; use `--compact` for clean output
- **Feature Tests**: HTTP routes, integration tests (most tests should be feature tests)
- **Unit Tests**: Models, services, request validation
- **Coverage**: Every new feature or bug fix must have accompanying tests covering happy path, failure paths, and edge cases
- **Minimum viable run**: Filter to the affected file/test name rather than running everything
- **Key Files**: `SermonPagesTest.php`, `SermonTest.php`, `MeetingTest.php`

### File Locations
- **Models**: `app/Models/` (Sermon, Page, Meeting, User)
- **Services**: `app/Services/` (processing, storage, transcription)
- **Controllers**: `app/Http/Controllers/Api/` (sermon upload/processing)
- **Jobs**: `app/Jobs/` (background processing tasks)
- **Storage**: `storage/app/public/sermons/`, S3/Spaces for cloud
- **Frontend**: `resources/` (Tailwind, Alpine.js, Livewire components)

## Project Conventions

### Route Structure
- `/` - Homepage
- `/christ/*` - Christ-focused content and sermons
- `/church/*` - Church information pages
- `/community/*` - Community events and meetings
- `/church/members/*` - Authenticated members area

### API Patterns
- Unified processing endpoints using `ProcessingStatusContract`
- `StandardProcessingResponse` for consistent API responses
- Polymorphic status checking across processing types

### Storage Conventions
- **Local**: `storage/app/public/sermons/` for development
- **Cloud**: DigitalOcean Spaces with CDN for production
- **Temporary**: `storage/app/temp/` for processing files
- **Transcripts**: `storage/app/transcripts/` with mock data for development

### Key Enums
- `SermonService`: morning, evening, other
- `PageArea`: christ, church, community, members, sermons
- `MeetingType`: regular, special, event types
- `MeetingFrequency`: daily, weekly, monthly, annually

## Current State

- ✅ **Code Quality**: PHPStan at 0 errors — must stay that way; run after every change
- ✅ **Formatting**: Pint enforced — run `vendor/bin/sail bin pint --dirty` before committing
- ✅ **Testing**: Parallel test suite; high coverage across models, services, and HTTP routes
- ✅ **S3 Storage**: Production-ready hybrid processing with retry logic
- ✅ **API Consistency**: Unified response format via `ProcessingStatusContract`
- ✅ **Image Processing**: `intervention/image-laravel` (v1.x) used throughout — not the legacy `intervention/image` v2

## Development Notes

- **Transcription**: Uses OpenAI Whisper API (production) or mock service (development)
- **Video Processing**: Requires FFmpeg for segmentation and analysis
- **Authentication**: Livewire 3 components in `app/Livewire/Auth/`
- **Image Processing**: `intervention/image-laravel` v1.x via `Image` facade — use `Image::read()`, not `Image::make()`
- **Thumbnails**: `ThumbnailGenerationService` generates branded WebP thumbnails from video frames using FFmpeg + Intervention Image
- **Image Utilities**: `images:convert-to-webp` and `images:migrate-to-storage` artisan commands for image migrations
- **Environment**: Configure via `TRANSCRIPTION_SERVICE_TYPE`, storage disk settings
- **Boost**: Use the `search-docs` MCP tool for all Laravel/package documentation lookups before writing code

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.17
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v3
- larastan/larastan (LARASTAN) - v3
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- alpinejs (ALPINEJS) - v3
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `livewire-development` — Develops reactive Livewire 3 components. Activates when creating, updating, or modifying Livewire components; working with wire:model, wire:click, wire:loading, or any wire: directives; adding real-time updates, loading states, or reactivity; debugging component behavior; writing Livewire tests; or when the user mentions Livewire, component, counter, or reactive UI.
- `tailwindcss-development` — Styles applications using Tailwind CSS v3 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `vendor/bin/sail artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces using only PHP — no JavaScript required.
- Instead of writing frontend code in JavaScript frameworks, you use Alpine.js to build the UI when client-side interactions are required.
- State lives on the server; the UI reflects it. Validate and authorize in actions (they're like HTTP requests).
- IMPORTANT: Activate `livewire-development` every time you're working with Livewire-related tasks.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/sail bin pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test`, simply run `vendor/bin/sail bin pint` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `vendor/bin/sail artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/sail artisan test --compact`.
- To run all tests in a file: `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/sail artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
