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
**Current Branch**: media-refactor

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
npm run dev          # Development with hot reload
npm run build        # Production build

# Backend (Laravel Sail)
sail up              # Start development server
sail artisan migrate # Run migrations
sail artisan db:seed # Seed with sample data + mock transcripts

# Testing
sail artisan test --parallel  # Fast parallel execution
sail artisan test             # Sequential (slower)

# Code Quality
sail composer phpstan         # Static analysis (currently 0 errors)
sail composer exec pint       # Code formatting
```

### Testing Strategy
- **Feature Tests**: HTTP routes, integration tests
- **Unit Tests**: Models, services, request validation
- **Parallel Optimized**: 60-70% faster execution, reduced memory usage
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

## Current Focus

### Recent Improvements (December 2024)
- **ProcessingStatusContract**: Unified API responses across all processing types
- **S3/Spaces Integration**: Full hybrid processing with automatic storage detection
- **Enhanced Error Handling**: Standardized responses and graceful fallback
- **Test Optimization**: 60-70% faster execution with parallel processing

### Current State
- ✅ **Code Quality**: All PHPStan errors resolved (20→0), full type safety
- ✅ **S3 Storage**: Production-ready hybrid processing with retry logic
- ✅ **Testing**: Optimized parallel execution with automatic seeding
- ✅ **API Consistency**: Unified response format across all processing endpoints

### Active Development Areas
- Media processing pipeline refinements
- Storage migration utilities
- Performance optimization for large video files

## Development Notes

- **Transcription**: Uses OpenAI Whisper API (production) or mock service (development)
- **Video Processing**: Requires FFmpeg for segmentation and analysis
- **Authentication**: Livewire 3 components in `app/Livewire/Auth/`
- **Image Processing**: Intervention Image library via `PageImageService`
- **Environment**: Configure via `TRANSCRIPTION_SERVICE_TYPE`, storage disk settings