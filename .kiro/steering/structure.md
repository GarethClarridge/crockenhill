# Project Structure

## Application Architecture

This Laravel application follows a domain-driven approach with clear separation of concerns for church website functionality and automated media processing.

## Key Directories

### `/app` - Application Logic

#### Core Domains
- **`/Models`**: Eloquent models with enum integration
  - Core: Sermon, Meeting, Page, User, CalendarEvent
  - Processing: SermonProcessingLog, LivestreamProcessingLog
- **`/Services`**: Business logic with contract implementations
  - **ProcessingRouter**: Intelligent upload routing
  - **VideoProcessingService**: Dual-mode video processing
  - **SermonProcessingService**: Audio processing and AI analysis
  - **VideoSegmentationService**: FFmpeg-based video analysis
  - External integrations (CalendarService, PageImageService)
- **`/Jobs`**: Asynchronous processing pipelines
  - Livestream: RMS analysis, segmentation, extraction
  - Sermon: Transcription, AI analysis, record creation
  - System: Notifications, cleanup, health checks
- **`/Data`**: Spatie Data objects for type safety
  - **SermonMetadata**, **LivestreamSegment**, **StandardProcessingResponse**
- **`/Enums`**: Type-safe enumerations
  - **SermonService**: morning, evening, other
  - **PageArea**: christ, church, community, members, sermons
  - **ProcessingStatus**: pending, processing, completed, failed
- **`/Contracts`**: Interface definitions
  - **ProcessingStatusContract**: Unified processing interface
  - **TranscriptionServiceInterface**: Pluggable transcription services

#### Web Layer
- **`/Http/Controllers`**: HTTP request handling
- **`/Http/Middleware`**: Request/response middleware
- **`/Http/Requests`**: Form request validation
- **`/Http/Resources`**: API resource transformations
- **`/Livewire`**: Livewire components for dynamic UI
- **`/View/Components`**: Blade components

#### Supporting Infrastructure
- **`/Console/Commands`**: Artisan commands
- **`/Policies`**: Authorization policies
- **`/Providers`**: Service providers
- **`/Contracts`**: Interface definitions
- **`/Exceptions`**: Custom exception handling
- **`/HealthChecks`**: Application health monitoring
- **`/Logging`**: Custom log formatters
- **`/Mail`**: Email notifications

### `/config` - Configuration
- Application, database, queue, and service configurations
- Custom configs: `sermon-processing.php`, `livestream-processing.php`, `calendar.php`

### `/database` - Database Layer
- **`/migrations`**: Database schema definitions
- **`/factories`**: Model factories for testing
- **`/seeders`**: Database seeding

### `/resources` - Frontend Assets
- **`/views`**: Blade templates organized by feature
- **`/css`**: SCSS stylesheets with Tailwind
- **`/js`**: JavaScript assets and Alpine.js components

### `/storage` - File Storage
- **`/app/sermons`**: Processed sermon audio files
- **`/app/livestreams`**: Full livestream recordings and segments
- **`/app/transcripts`**: AI-generated transcripts (includes mock files for development)
- **`/app/temp`**: Temporary processing files (auto-cleanup)
- **`/app/public/sermons`**: Public sermon audio files

### `/tests` - Testing
- **`/Feature`**: Integration tests
- **`/Unit`**: Unit tests
- **`/Browser`**: Browser tests
- **`/Integration`**: Service integration tests
- **`/Performance`**: Performance tests

### `/docs` - Documentation
- **`/api`**: API documentation
- **`/architecture`**: Architecture decisions
- **`/deployment`**: Deployment guides
- **`/operations`**: Operational runbooks

## Architectural Patterns

### Contract-Based Architecture
- **ProcessingStatusContract**: Interface ensuring consistent API responses across processing types
- **StandardProcessingResponse**: Unified response format for all processing endpoints
- **Polymorphic Processing**: Single interface handling multiple processing types

### Service Layer Pattern
Business logic encapsulated in focused service classes:
- **ProcessingRouter**: Intelligent routing to appropriate processors
- **VideoProcessingService**: Dual-mode processing (segmentation vs direct)
- **SermonProcessingService**: Audio-focused processing and AI analysis
- **VideoSegmentationService**: FFmpeg-based video analysis

### Job Queue Pattern
Asynchronous processing with job chaining:
- **Livestream Pipeline**: RMS analysis → segmentation → extraction → transcription
- **Video Pipeline**: Direct processing → transcription → AI analysis
- **Audio Pipeline**: Transcription → AI analysis → record creation

### Data Transfer Objects (Spatie Laravel Data)
Type-safe data structures:
- **SermonMetadata**: Structured sermon information
- **LivestreamSegment**: Video segment data
- **StandardProcessingResponse**: Unified API responses

### Repository Pattern (Implicit)
Eloquent models with custom scopes and relationships, enhanced with enum-based type safety.

## Naming Conventions

### Classes
- **Models**: Singular nouns (Sermon, Meeting, User)
- **Services**: Descriptive names ending in "Service" (SermonProcessingService)
- **Jobs**: Action-based names (TranscribeAudio, ProcessTranscriptWithAI)
- **Data Objects**: Domain entity names (SermonMetadata, LivestreamSegment)
- **Controllers**: Resource names with "Controller" suffix

### Files & Directories
- Use PascalCase for class files
- Use kebab-case for config files
- Use snake_case for database files (migrations, seeders)
- Use camelCase for JavaScript files

### Database
- Table names: plural snake_case (sermons, calendar_events)
- Column names: snake_case
- Foreign keys: singular_table_id (sermon_id, user_id)

## Configuration Management

Environment-specific settings are managed through:
- **`.env` files**: Core application and processing configuration
- **Config files**: `/config` directory with custom configs
  - `sermon-processing.php`: Audio processing and AI settings
  - `livestream-processing.php`: Video processing and segmentation
  - `calendar.php`: Google Calendar integration
- **Feature flags**: Service switching via environment variables
  - `TRANSCRIPTION_SERVICE_TYPE`: openai|mock
  - Processing thresholds and timeouts
  - Storage disk configurations

## API Architecture

### Unified Processing Endpoints
- **Upload Routes**: `/api/sermons/{audio|video|livestream}`
- **Status Management**: `/api/sermons/processing/{id}/{status|cancel|retry}`
- **Legacy Support**: Backwards-compatible redirects for existing integrations

### Contract Implementation
All processing controllers implement `ProcessingStatusContract`:
- `getProcessingStatus()`: Returns `StandardProcessingResponse`
- `cancelProcessing()`: Standardized cancellation
- `canHandle()`: Polymorphic processing detection

### Response Format
```php
StandardProcessingResponse {
    processingId: string
    status: ProcessingStatus
    progress: int
    message: string
    additionalData: array
    createdAt: Carbon
    updatedAt: Carbon
}
```