# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based church website for Crockenhill Baptist Church. The application manages:
- **Pages**: Static content organized by areas (Christ, Church, Community, Members)
- **Sermons**: Audio recordings with metadata (preacher, series, reference, etc.)
- **Meetings**: Church events with scheduling and location information
- **Members**: Authentication and admin areas

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
Uses `owen-oj/laravel-getid3` for audio file metadata extraction when uploading sermons.

### Caching Strategy
Standard Laravel caching is used. Clear caches during development with `sail artisan cache:clear`.

## Production Considerations

- Assets are built with Vite and include compression via `vite-plugin-compression`
- Static assets are served from `public/` directory
- Audio files are served through Laravel's storage system
- Database uses standard Laravel migrations for deployment