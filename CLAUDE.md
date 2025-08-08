# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based church website for Crockenhill Baptist Church. The application manages:
- **Pages**: Static content organized by areas (Christ, Church, Community, Members)
- **Sermons**: Audio recordings with metadata (preacher, series, reference, etc.)
- **Meetings**: Church events with scheduling and location information
- **Members**: Authentication and admin areas
- **Livestream Processing**: Automated video segmentation and sermon extraction from livestream recordings

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

# Seed database with sample data
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
sail composer pint

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
Uses `owen-oj/laravel-getid3` for audio file metadata extraction when uploading sermons. The `AudioTranscriptionService` handles OpenAI Whisper API transcription with automatic chunking for long audio files (>7 minutes) to prevent timeouts.

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
chmod -R 755 storage/app/livestreams
chmod -R 755 storage/app/temp
chmod -R 755 storage/app/sermons
```

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