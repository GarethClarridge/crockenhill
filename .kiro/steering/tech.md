# Technology Stack

## Core Framework & Language (TALL Stack)

- **Laravel 12**: PHP web framework with modern features
- **PHP 8.2+**: Required minimum version with strict typing
- **MySQL 8.0**: Primary database
- **Livewire 3**: Full-stack framework for reactive interfaces
- **Alpine.js**: Lightweight JavaScript framework for UI interactions
- **Tailwind CSS**: Utility-first CSS framework with custom fonts (Lato, Oswald)
- **Vite**: Modern build tool replacing Laravel Mix

## Key Dependencies

### Media Processing & AI
- **php-ffmpeg/php-ffmpeg**: Video/audio processing and analysis
- **owen-oj/laravel-getid3**: Audio metadata extraction
- **openai-php/laravel**: OpenAI Whisper transcription + GPT analysis
- **intervention/image**: Image processing for page content

### External Services & Data
- **spatie/laravel-google-calendar**: Google Calendar integration
- **spatie/laravel-data**: Structured data transfer objects
- **league/commonmark**: Markdown processing
- **techwilk/bible-verse-parser**: Bible reference parsing

### Development & Quality
- **Laravel Sail**: Docker development environment
- **PHPStan (Larastan)**: Static analysis with Laravel rules
- **Laravel Pint**: PSR-12 code formatting
- **PHPUnit**: Testing framework with Feature/Unit/Browser tests
- **Laravel Debugbar**: Development debugging

## Development Commands

### Frontend Development
```bash
# Start development with hot reload
npm run dev

# Production build with compression
npm run build

# Watch mode for development
npm run watch
```

### Backend Development (Laravel Sail)
```bash
# Start development environment
sail up

# Database setup with sample data
sail artisan migrate
sail artisan db:seed  # Includes transcript files for mock service

# Cache management
sail artisan cache:clear
sail artisan config:clear
sail artisan view:clear
```

### Testing & Quality
```bash
# Run all tests
sail artisan test

# Specific test files
sail artisan test tests/Feature/SermonPagesTest.php

# Code formatting
sail composer exec pint

# Static analysis
sail composer phpstan
```

### Queue Management (Critical for Processing)
```bash
# Start queue workers
php artisan queue:work --timeout=3600

# Queue management
php artisan queue:restart
php artisan queue:failed
php artisan queue:retry all
```

## Environment Configuration

### Core Application
```bash
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
QUEUE_CONNECTION=database  # or redis for production
```

### Media Processing
```bash
# Transcription Service
TRANSCRIPTION_SERVICE_TYPE=mock  # Use 'openai' for production
OPENAI_API_KEY=your_key_here     # Required for OpenAI service

# Livestream Processing
LIVESTREAM_RMS_THRESHOLD=-30.0
LIVESTREAM_MIN_SECTION_DURATION=60.0
LIVESTREAM_MIN_SERMON_DURATION=300.0
LIVESTREAM_MAX_FILE_SIZE=2147483648  # 2GB

# FFmpeg Paths
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
```

### Storage Configuration
```bash
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=sermon_disk
LIVESTREAM_ADMIN_EMAIL=admin@church.com
```

## Production Requirements

### System Dependencies
- **FFmpeg**: Required for video processing (`ffmpeg -version` to verify)
- **PHP Extensions**: php-gd, php-imagick, php-ffmpeg
- **Storage**: 100GB+ recommended for video files
- **Memory**: 4GB+ RAM for video processing jobs
- **Queue Workers**: Supervisord or systemd configuration required

### API Endpoints

#### Unified Processing
- `POST /api/sermons/audio` - Audio files → SermonProcessingService
- `POST /api/sermons/video` - Direct sermon videos → VideoProcessingService
- `POST /api/sermons/livestream` - Full recordings → VideoProcessingService (segmentation)

#### Status Management
- `GET /api/sermons/processing/{id}/status` - Unified status via ProcessingStatusContract
- `DELETE /api/sermons/processing/{id}` - Cancel processing
- `POST /api/sermons/processing/{id}/retry` - Retry failed processing

### Health Checks
- FFmpeg availability
- Queue worker status
- Storage space monitoring
- Processing job health