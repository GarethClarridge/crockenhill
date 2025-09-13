# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based church website for Crockenhill Baptist Church. The application manages:
- **Pages**: Static content organized by areas (Christ, Church, Community, Members)
- **Sermons**: Audio recordings with metadata (preacher, series, reference, etc.)
- **Meetings**: Church events with scheduling and location information
- **Members**: Authentication and admin areas
- **Livestream Processing**: Automated video segmentation and sermon extraction from livestream recordings

## Recent Architectural Improvements

### ProcessingStatusContract Implementation

The application now implements a `ProcessingStatusContract` interface that provides unified API responses across different processing systems:

- **Consistent API Responses**: Both `AutomatedSermonController` and `LivestreamProcessingController` implement the contract
- **StandardProcessingResponse**: Unified response format for all processing status endpoints
- **Polymorphic Processing**: Single endpoint can handle different processing types via the contract
- **Enhanced Error Handling**: Standardized error responses across all processing controllers

### Key Contract Methods

1. **`getProcessingStatus(string $processingId): StandardProcessingResponse`**
   - Returns standardized processing status with additional type-specific data
   - Compatible across sermon and livestream processing systems

2. **`cancelProcessing(string $processingId): array`**
   - Provides consistent cancellation functionality
   - Returns standardized array format for API responses

3. **`canHandle(string $processingId): bool`**
   - Enables automatic routing to appropriate processing handlers
   - Supports polymorphic status checking across different processing types

### Implementation Benefits

- **API Consistency**: Unified response format across all processing types
- **Better Integration**: Easier client-side integration with consistent interfaces  
- **Enhanced Monitoring**: Standardized status checking enables better system monitoring
- **Future-Proof**: Contract-based approach makes adding new processing types easier

You are an expert in the TALL stack: Laravel, Livewire, Alpine.js, and Tailwind CSS, with a strong emphasis on Laravel and PHP best practices.

This project was originally created in Laravel 5 and has been gradually updated. It may not always abide by these rules. New code should follow these rules, and where possible we should refactor existing code. 

Key Principles

- Follow Laravel best practices and conventions.
- Use object-oriented programming with a focus on SOLID principles.
- Prefer iteration and modularization over duplication.
- Use descriptive variable and method names.
- Favor dependency injection and service containers.

PHP and Laravel Core

- Use PHP 8.1+ features when appropriate (e.g., typed properties, match expressions).
- Follow PSR-12 coding standards.
- Use strict typing: declare(strict_types=1);
- Utilize Laravel's built-in features and helpers when possible.
- Follow Laravel's directory structure and naming conventions.
- Use PascalCase for class-containing directories (e.g., app/Http/Controllers).
- Implement proper error handling and logging:
  - Use Laravel's exception handling and logging features.
  - Create custom exceptions when necessary.
  - Use try-catch blocks for expected exceptions.
- Use Laravel's validation features for form and request validation.
- Implement middleware for request filtering and modification.
- Utilize Laravel's Eloquent ORM for database interactions.
- Use Laravel's query builder for complex database queries.
- Implement proper database migrations and seeders.

Laravel Best Practices

- Use Eloquent ORM instead of raw SQL queries when possible.
- Implement Repository pattern for data access layer.
- Use Laravel's built-in authentication and authorization features.
- Utilize Laravel's caching mechanisms for improved performance.
- Implement job queues for long-running tasks.
- Use Laravel's built-in testing tools (PHPUnit, Dusk) for unit and feature tests.
- Implement API versioning for public APIs.
- Use Laravel's localization features for multi-language support.
- Implement proper CSRF protection and security measures.
- Use Laravel Mix for asset compilation.
- Implement proper database indexing for improved query performance.
- Use Laravel's built-in pagination features.
- Implement proper error logging and monitoring.

Livewire Implementation

- Create modular, reusable Livewire components.
- Use Livewire's lifecycle hooks effectively (e.g., mount, updated, etc.).
- Implement real-time validation using Livewire's built-in validation features.
- Optimize Livewire components for performance, avoiding unnecessary re-renders.
- Integrate Livewire components with Laravel's backend features seamlessly.

Alpine.js Usage

- Use Alpine.js directives (x-data, x-bind, x-on, etc.) for declarative JavaScript functionality.
- Implement small, focused Alpine.js components for specific UI interactions.
- Combine Alpine.js with Livewire for enhanced interactivity when necessary.
- Keep Alpine.js logic close to the HTML it manipulates, preferably inline.

Tailwind CSS Styling

- Utilize Tailwind's utility classes for responsive design.
- Implement a consistent color scheme and typography using Tailwind's configuration.
- Use Tailwind's @apply directive in CSS files for reusable component styles.
- Optimize for production by purging unused CSS classes.

Performance Optimization

- Implement lazy loading for Livewire components when appropriate.
- Use Laravel's caching mechanisms for frequently accessed data.
- Minimize database queries by eager loading relationships.
- Implement pagination for large data sets.
- Use Laravel's built-in scheduling features for recurring tasks.

Security Best Practices

- Always validate and sanitize user input.
- Use Laravel's CSRF protection for all forms.
- Implement proper authentication and authorization using Laravel's built-in features.
- Use Laravel's prepared statements to prevent SQL injection.
- Implement proper database transactions for data integrity.

Testing

- Write unit tests for Laravel controllers and models.
- Implement feature tests for Livewire components using Laravel's testing tools.
- Use Laravel Dusk for end-to-end testing when necessary.

Key Conventions

1. Follow Laravel's MVC architecture.
2. Use Laravel's routing system for defining application endpoints.
3. Implement proper request validation using Form Requests.
4. Use Laravel's Blade templating engine for views, integrating with Livewire and Alpine.js.
5. Implement proper database relationships using Eloquent.
6. Use Laravel's built-in authentication scaffolding.
7. Implement proper API resource transformations.
8. Use Laravel's event and listener system for decoupled code.

Dependencies

- Laravel 12+ (latest stable version)
- Livewire
- Alpine.js
- Tailwind CSS
- Composer for dependency management

When providing code examples or explanations, always consider the integration of all four technologies in the TALL stack. Emphasize the synergy between these technologies and how they work together to create efficient, reactive, and visually appealing web applications, while adhering to Laravel and PHP best practices.

## Development Commands

### Frontend Development
```bash
# Start development server with hot reload
npm run dev

# Build for production
npm run build

# Watch for changes during development
npm run watch
```

### Backend Development (using Laravel Sail)
```bash
# Start local development server
sail up

# Run migrations
sail artisan migrate

# Seed database with sample data (includes transcript files for mock transcription service)
sail artisan db:seed

# Clear application cache
sail artisan cache:clear
sail artisan config:clear
sail artisan view:clear
```

### Testing
```bash
# Run all tests
sail artisan test

# Run specific test file
sail artisan test tests/Feature/SermonPagesTest.php

# Run with coverage (if configured)
sail artisan test --coverage
```

### Code Quality
```bash
# Format code with Laravel Pint
sail composer exec pint

# Static analysis with Larastan
sail composer phpstan

# Run debugbar in development (already included)
```

## Architecture

### Core Models
- **Sermon**: Handles audio recordings with date, service type (morning/evening/other), preacher, series, and Bible reference
- **Page**: Manages static content with areas defined by `PageArea` enum (Christ, Church, Community, Members)
- **Meeting**: Manages church events with recurring meeting support via `MeetingFrequency` enum
- **User**: Authentication for members area

### Key Enums
- `SermonService`: morning, evening, other
- `PageArea`: christ, church, community, members, sermons
- `MeetingType`: regular, special, event types
- `MeetingFrequency`: daily, weekly, monthly, annually

### Route Structure
- `/` - Homepage
- `/christ/*` - Christ-focused content and sermons
- `/church/*` - Church information pages
- `/community/*` - Community events and meetings
- `/church/members/*` - Authenticated members area
- Extensive permanent redirects for legacy URLs

### Frontend Stack
- **Vite** for asset bundling (replaces Laravel Mix)
- **Tailwind CSS** for styling with custom fonts (Lato, Oswald)
- **Sass** for custom styling in `resources/css/cbc/`
- **Livewire** for interactive components (authentication)

### File Storage
- Sermon audio files stored in `storage/app/public/sermons/`
- Page images and other media in `public/images/`
- Uses Laravel's storage disk system for file management

### Unified Media Processing Architecture
- **ProcessingRouter**: Intelligent routing service that directs media uploads to appropriate processors based on user choice
- **VideoProcessingService**: Handles all video processing with dual modes (segmentation for livestreams, direct processing for sermon videos)
- **SermonProcessingService**: Focused on audio processing, transcription, and AI-powered sermon analysis
- **VideoSegmentationService**: FFmpeg-based video analysis and segment extraction (used by VideoProcessingService)
- **ProcessingStatusContract**: Interface ensuring consistent API responses across processing types
- **StandardProcessingResponse**: Unified response format for all processing status endpoints

#### Processing Pipelines

**Livestream Processing (with segmentation):**
1. **RMS Analysis**: Audio level analysis to identify music vs speech segments
2. **Segment Classification**: Automatic categorization of video segments (song/speech)
3. **Sermon Extraction**: FFmpeg-based extraction of sermon segments from full videos
4. **Video Preservation**: Both original and extracted videos are stored permanently
5. **Audio Processing**: Transcription and AI analysis of extracted sermon content
6. **Sermon Record Creation**: Complete sermon record with preserved video files

**Direct Video Processing (sermon-only videos):**
1. **Audio Extraction**: Full audio track extraction optimized for transcription
2. **Video Preservation**: Original video file stored permanently and linked to sermon
3. **Audio Processing**: Transcription and AI analysis of full audio content
4. **Sermon Record Creation**: Complete sermon record with video file preservation

**Audio Processing:**
1. **Audio Processing**: Direct transcription and AI analysis
2. **Metadata Enrichment**: AI analysis for title, preacher, series identification
3. **Sermon Record Creation**: Standard sermon record with audio file

#### Processing Status Tracking
- **Real-time Status Updates**: Granular progress tracking through processing steps
- **Enhanced Error Handling**: Detailed error messages and recovery options
- **Polymorphic Status Checking**: Unified status interface across different processing types
- **Graceful Degradation**: Fallback options for failed processing steps

#### API Endpoints

**Unified Upload Endpoints:**
- `POST /api/sermons/audio` - Audio sermon files (ProcessingRouter → SermonProcessingService)
- `POST /api/sermons/video` - Direct sermon videos (ProcessingRouter → VideoProcessingService direct processing)
- `POST /api/sermons/livestream` - Full livestream recordings (ProcessingRouter → VideoProcessingService segmentation)

**Status Management:**
- `GET /api/sermons/processing/{processingId}/status` - Unified status checking across all processing types
- `DELETE /api/sermons/processing/{processingId}` - Cancel processing operation
- `POST /api/sermons/processing/{processingId}/retry` - Retry failed processing

**Legacy Endpoints (Backwards Compatibility):**
- `POST /api/sermons/automated` - Legacy audio upload (redirects to `/audio`)
- `POST /api/livestreams/process` - Direct livestream processing (bypasses ProcessingRouter)

### Database
- Uses standard Laravel migrations in `database/migrations/`
- Factories for testing in `database/factories/`
- Seeders for sample data in `database/seeders/`

## Testing Strategy

Tests are organized into:
- **Feature Tests**: HTTP route testing, integration tests
- **Unit Tests**: Model behavior, service classes, request validation

Key test files:
- `SermonPagesTest.php`: Tests sermon display and routing
- `SermonTest.php`: Tests sermon model behavior
- `MeetingTest.php`: Tests meeting functionality
- `PostSermonRequestTest.php`: Tests sermon upload validation

## Development Notes

### Livewire Integration
The application uses Livewire 3 for authentication pages. Components are in `app/Livewire/Auth/`.

### Image Handling
Uses Intervention Image library for image processing. Service class `PageImageService` handles image uploads and processing.

### Audio Processing
Uses `owen-oj/laravel-getid3` for audio file metadata extraction when uploading sermons. The transcription system supports both OpenAI Whisper API and mock implementations:

- **OpenAI Whisper**: Production transcription with automatic chunking for long audio files (>7 minutes) to prevent timeouts
- **Mock Service**: Local development stub that returns the content from `sermon_7.md` to avoid API costs

Transcription service is configured via `TRANSCRIPTION_SERVICE_TYPE` environment variable.

### Livestream Video Processing
Uses FFmpeg for video analysis and segmentation. The `php-ffmpeg/php-ffmpeg` package provides Laravel integration for video processing tasks.

### Caching Strategy
Standard Laravel caching is used. Clear caches during development with `sail artisan cache:clear`.

## Production Considerations

- Assets are built with Vite and include compression via `vite-plugin-compression`
- Static assets are served from `public/` directory
- Audio files are served through Laravel's storage system
- Database uses standard Laravel migrations for deployment

### Livestream Processing Deployment

#### System Requirements
- **FFmpeg**: Required for video processing and audio analysis
  - Install: `sudo apt-get install ffmpeg` (Ubuntu/Debian) or `brew install ffmpeg` (macOS)
  - Verify installation: `ffmpeg -version`
- **PHP Extensions**: Ensure `php-gd`, `php-imagick`, and `php-ffmpeg` are available
- **Storage**: Adequate disk space for video files (recommend 100GB+ for production)
- **Memory**: Minimum 4GB RAM for video processing jobs
- **Queue Workers**: Configured and running for background job processing

#### Environment Configuration
Add to `.env`:
```bash
# Livestream Processing Configuration
LIVESTREAM_RMS_THRESHOLD=-30.0
LIVESTREAM_MIN_SECTION_DURATION=60.0
LIVESTREAM_MIN_SERMON_DURATION=300.0
LIVESTREAM_MAX_FILE_SIZE=2147483648  # 2GB
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
LIVESTREAM_ADMIN_EMAIL=admin@church.com
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=sermon_disk

# Transcription Configuration
TRANSCRIPTION_SERVICE_TYPE=mock  # Use 'openai' for production, 'mock' for local development
OPENAI_API_KEY=your_openai_key_here  # Only needed when using openai service type

# Queue Configuration (required for processing jobs)
QUEUE_CONNECTION=database  # or redis
```

#### Database Setup
Run migrations for livestream processing:
```bash
php artisan migrate
```

#### Storage Configuration
Ensure storage directories exist and are writable:
```bash
mkdir -p storage/app/livestreams
mkdir -p storage/app/temp
mkdir -p storage/app/sermons
mkdir -p storage/app/transcripts
chmod -R 755 storage/app/livestreams
chmod -R 755 storage/app/temp
chmod -R 755 storage/app/sermons
chmod -R 755 storage/app/transcripts
```

**Note**: The `TranscriptSeeder` will automatically create the transcripts directory and mock sermon transcript file when running `db:seed`. This ensures the mock transcription service has valid content for local development and testing.

#### Queue Worker Setup
Configure supervisord or systemd to run queue workers:

**supervisord.conf:**
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/application/artisan queue:work --sleep=3 --tries=3 --timeout=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/application/storage/logs/worker.log
```

#### Health Check Setup
Register health checks in `app/Providers/HealthServiceProvider.php`:
```php
use App\HealthChecks\FFmpegHealthCheck;
use App\HealthChecks\LivestreamQueueHealthCheck;
use App\HealthChecks\StorageSpaceHealthCheck;

Health::checks([
    FFmpegHealthCheck::new()->name('ffmpeg-availability'),
    LivestreamQueueHealthCheck::new()->name('livestream-queue'),
    StorageSpaceHealthCheck::new()->name('video-storage'),
]);
```

#### Monitoring and Alerting
- Monitor disk space usage for video storage
- Set up alerts for failed processing jobs
- Monitor queue worker health and processing times
- Configure email notifications for processing failures

#### Backup Considerations
- Original livestream files should be backed up before cleanup
- Sermon videos should be included in regular backup procedures
- Database includes processing logs and segment metadata

#### Security Notes
- Ensure video files are stored outside web root
- Configure appropriate file upload limits in web server
- Implement rate limiting on API endpoints
- Regularly rotate API tokens and update access controls