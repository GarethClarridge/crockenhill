# Media Processing Architecture Refactoring Plan

## Executive Summary

This document outlines a comprehensive refactoring plan for the media processing architecture in the Crockenhill Baptist Church Laravel application. The current implementation, while functional, violates several SOLID principles and Laravel best practices, making it difficult to maintain and extend.

## Current Architecture Analysis

### Strengths
- ✅ **Functional Implementation**: All three processing types (audio, video, livestream) work correctly
- ✅ **Unified API**: `ProcessingStatusContract` provides consistent API responses
- ✅ **Comprehensive Error Handling**: Good logging and error recovery mechanisms
- ✅ **Testing Coverage**: Unit tests exist for core routing functionality
- ✅ **Configuration-Driven**: Uses Laravel config for flexibility

### Critical Issues Identified

#### 1. Single Responsibility Principle (SRP) Violations

**VideoProcessingService (749 lines)**
- Video processing with segmentation
- Direct video processing without segmentation
- Audio extraction and compression
- Sermon record creation and updates
- Thumbnail generation dispatch
- Storage management (temporary → permanent)
- Error handling and cleanup
- FFmpeg operations

**SermonProcessingService (1251 lines)**
- Audio file validation and storage
- Sermon processing (sync and async)
- Processing status management
- Statistics and health monitoring
- Graceful degradation logic
- Retry mechanisms
- Manual review workflows
- System health checks

#### 2. Mixed Processing Paradigms
```php
// Livestream: Asynchronous job chains
Bus::chain([
    new GenerateRmsLog($processingLog),
    new AnalyzeSegments($processingLog),
    new ExtractSermon($processingLog),
    new SubmitToProcessing($processingLog),
    new CleanupTemporaryFiles($processingLog),
])->dispatch();

// Direct video: Synchronous processing
$result = $this->processSermonAudio($audioFile, $videoMetadata);
```

#### 3. Tight Coupling and Service Location
```php
// Direct instantiation instead of dependency injection
$sermonProcessor = app(SermonProcessingService::class);
$analysisService = app(\App\Services\SermonAnalysisService::class);
$transcriptionService = app(\App\Contracts\TranscriptionServiceInterface::class);
```

#### 4. Code Duplication
- Synchronous processing in `SermonProcessingService::processSynchronously()` duplicates logic from existing jobs
- Similar validation logic across multiple methods
- Repeated error handling patterns

#### 5. Method Complexity
- `VideoProcessingService::processDirectly()` - 79 lines
- `SermonProcessingService::processSermonAudio()` - 80 lines
- `AutomatedSermonController::getProcessingStatus()` - 45 lines
- Many private helper methods exceeding 30 lines

#### 6. Interface Segregation Issues
- `ProcessingStatusContract` forces all controllers to implement methods they may not need
- `VideoProcessingService` has both segmentation and direct processing methods in same class

## Proposed Refactoring Plan

### Phase 1: Extract Core Services (Week 1)

#### 1.1 Create AudioExtractionService
```php
interface AudioExtractionServiceInterface
{
    public function extractFromVideo(string $videoPath, float $duration): string;
    public function compressForTranscription(string $audioPath): string;
    public function validateAudioFile(UploadedFile $file): void;
}
```
**Extracts from**: `VideoProcessingService::extractFullAudioFromVideo()`, `VideoProcessingService::compressAudioForTranscription()`

#### 1.2 Create VideoStorageService Interface
```php
interface VideoStorageServiceInterface
{
    public function storeTemporary(UploadedFile $file): array;
    public function moveToPermanent(array $uploadResult): string;
    public function validateStorageSpace(int $requiredBytes): bool;
    public function cleanup(string $processingId): void;
}
```
**Extracts from**: `VideoProcessingService::moveVideoToPermanentStorage()`, storage-related methods

#### 1.3 Create ProcessingHealthService
```php
interface ProcessingHealthServiceInterface
{
    public function getSystemHealth(): array;
    public function checkQueueHealth(): array;
    public function checkStorageHealth(): array;
    public function checkProcessingHealth(): array;
    public function getProcessingStatistics(): array;
}
```
**Extracts from**: `SermonProcessingService` health check methods

#### 1.4 Create SermonMetadataService
```php
interface SermonMetadataServiceInterface
{
    public function extractFromFilename(string $filename): SermonMetadata;
    public function generateFallbackData(Sermon $sermon, ProcessingLog $log): array;
    public function generateUniqueSlug(string $title, int $excludeId): string;
}
```
**Extracts from**: `SermonProcessingService` metadata-related methods

### Phase 2: Implement Strategy Pattern (Week 2)

#### 2.1 Create Processing Strategy Interface
```php
interface ProcessingStrategyInterface
{
    public function supports(string $type): bool;
    public function process(UploadedFile $file, array $options = []): ProcessingResult;
    public function validateFile(UploadedFile $file): array;
    public function getConfiguration(): array;
}
```

#### 2.2 Implement Concrete Strategies
```php
class AudioProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private SermonProcessingService $sermonProcessor,
        private AudioExtractionServiceInterface $audioExtractor
    ) {}

    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        // Use job chains for consistency
        return $this->sermonProcessor->processSermon($file);
    }
}

class DirectVideoProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private VideoProcessingService $videoProcessor,
        private AudioExtractionServiceInterface $audioExtractor,
        private SermonProcessingService $sermonProcessor
    ) {}

    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        // Refactor to use job chains instead of synchronous processing
        return $this->dispatchVideoProcessingJobs($file, $options);
    }
}

class LivestreamProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private VideoProcessingService $videoProcessor
    ) {}

    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        return $this->videoProcessor->processWithSegmentation($file);
    }
}
```

#### 2.3 Refactor ProcessingRouter
```php
class ProcessingRouter
{
    public function __construct(
        private ProcessingStrategyRegistry $strategyRegistry
    ) {}

    public function route(string $type, UploadedFile $file, array $options = []): ProcessingResult
    {
        $strategy = $this->strategyRegistry->getStrategy($type);

        if (!$strategy) {
            throw new UnsupportedProcessingTypeException($type);
        }

        $validation = $strategy->validateFile($file);
        if (!$validation['valid']) {
            throw new InvalidFileException($validation['errors']);
        }

        return $strategy->process($file, $options);
    }
}
```

### Phase 3: Unify Processing Paradigm (Week 3)

#### 3.1 Create Processing Pipeline Builder
```php
class ProcessingPipelineBuilder
{
    public function buildAudioPipeline(ProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new CreateSermonRecord($log),
            new SendCompletionNotification($log),
        ];
    }

    public function buildDirectVideoPipeline(ProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new CreateSermonRecord($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
        ];
    }

    public function buildLivestreamPipeline(ProcessingLog $log): array
    {
        return [
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new GenerateThumbnail($log),
            new CleanupTemporaryFiles($log),
        ];
    }
}
```

#### 3.2 Remove Synchronous Processing
- Delete `SermonProcessingService::processSynchronously()`
- Delete `SermonProcessingService::createSermonRecordSync()`
- Delete `SermonProcessingService::transcribeAudioSync()`
- Delete `SermonProcessingService::processTranscriptWithAISync()`

#### 3.3 Create New Jobs for Direct Video Processing
```php
class ExtractAudioFromVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private ProcessingLog $processingLog
    ) {}

    public function handle(AudioExtractionServiceInterface $audioExtractor): void
    {
        // Extract audio from video for transcription
    }
}
```

### Phase 4: Improve Dependency Injection (Week 4)

#### 4.1 Create Service Interfaces
```php
// Create interfaces for all services
interface VideoProcessingServiceInterface {}
interface SermonProcessingServiceInterface {}
interface ProcessingRouterInterface {}
```

#### 4.2 Register Services in Service Provider
```php
class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AudioExtractionServiceInterface::class, AudioExtractionService::class);
        $this->app->bind(VideoStorageServiceInterface::class, VideoStorageService::class);
        $this->app->bind(ProcessingHealthServiceInterface::class, ProcessingHealthService::class);
        $this->app->bind(SermonMetadataServiceInterface::class, SermonMetadataService::class);

        $this->app->singleton(ProcessingStrategyRegistry::class, function ($app) {
            return new ProcessingStrategyRegistry([
                'audio' => $app->make(AudioProcessingStrategy::class),
                'sermon_video' => $app->make(DirectVideoProcessingStrategy::class),
                'livestream' => $app->make(LivestreamProcessingStrategy::class),
            ]);
        });
    }
}
```

#### 4.3 Update Constructor Dependencies
```php
class VideoProcessingService
{
    public function __construct(
        private VideoStorageServiceInterface $storageService,
        private VideoSegmentationService $segmentationService,
        private AudioExtractionServiceInterface $audioExtractor,
        private ThumbnailGenerationService $thumbnailService
    ) {}
}
```

### Phase 5: Reduce Method Complexity (Week 5)

#### 5.1 Break Down Large Methods
- Extract validation logic into dedicated validator classes
- Create specific exception classes for different error types
- Move configuration logic to dedicated config services

#### 5.2 Improve Error Handling
```php
class ProcessingExceptionHandler
{
    public function handleVideoProcessingException(\Exception $e, array $context): ProcessingResult
    {
        Log::error('Video processing failed', array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        return ProcessingResult::failure(
            processingId: $context['processing_id'],
            message: $this->getUserFriendlyMessage($e),
            errorCode: $this->mapExceptionToCode($e)
        );
    }
}
```

#### 5.3 Create Value Objects
```php
class ProcessingConfiguration
{
    public function __construct(
        public readonly array $allowedExtensions,
        public readonly int $maxFileSize,
        public readonly string $description,
        public readonly array $validationRules
    ) {}
}
```

## Implementation Guidelines

### Testing Strategy
1. **Unit Tests**: Test each extracted service independently
2. **Integration Tests**: Test strategy pattern with actual file processing
3. **Feature Tests**: Ensure API endpoints continue to work correctly
4. **Performance Tests**: Verify async processing performance

### Migration Strategy
1. **Feature Flags**: Use feature flags to switch between old and new implementations
2. **Gradual Rollout**: Deploy each phase separately with rollback capability
3. **Monitoring**: Add detailed logging and metrics for new implementation
4. **Backward Compatibility**: Maintain existing API contracts during transition

### Configuration Changes
```php
// config/media-processing.php
return [
    'strategies' => [
        'audio' => [
            'class' => AudioProcessingStrategy::class,
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'queue' => 'audio-processing',
        ],
        'sermon_video' => [
            'class' => DirectVideoProcessingStrategy::class,
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'queue' => 'video-processing',
        ],
        'livestream' => [
            'class' => LivestreamProcessingStrategy::class,
            'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'queue' => 'livestream-processing',
        ],
    ],
];
```

## Expected Outcomes

### Architectural Benefits
- ✅ **SOLID Compliance**: Each class has single responsibility
- ✅ **Consistent Processing**: All types use async job chains
- ✅ **Better Testability**: Smaller, focused classes with clear dependencies
- ✅ **Improved Maintainability**: Easier to understand and modify
- ✅ **Enhanced Extensibility**: Easy to add new processing types

### Performance Improvements
- ✅ **Unified Async Processing**: All processing types use background jobs
- ✅ **Better Resource Management**: Proper cleanup and error handling
- ✅ **Improved Scalability**: Strategy pattern allows for type-specific optimization

### Code Quality Metrics
- **Cyclomatic Complexity**: Reduce from 15+ to <10 per method
- **Lines of Code per Class**: Reduce from 750+ to <300
- **Test Coverage**: Increase from 60% to 85%+
- **Technical Debt**: Significant reduction in code smells

## Risk Assessment

### Low Risk
- **Service Extraction**: Minimal impact on existing functionality
- **Interface Creation**: Backward compatible changes
- **Configuration Updates**: Non-breaking additions

### Medium Risk
- **Strategy Pattern Implementation**: Requires careful testing of routing logic
- **Dependency Injection Changes**: May affect service resolution

### High Risk
- **Removing Synchronous Processing**: Direct impact on video upload behavior
- **Job Chain Modifications**: Could affect processing reliability

## Success Criteria

1. **Functional**: All three processing types work correctly after refactoring
2. **Performance**: No degradation in processing times
3. **Code Quality**: Significant improvement in maintainability metrics
4. **Testing**: Comprehensive test coverage for new architecture
5. **Documentation**: Updated architecture documentation

## Timeline

- **Week 1**: Phase 1 - Extract core services
- **Week 2**: Phase 2 - Implement strategy pattern
- **Week 3**: Phase 3 - Unify processing paradigm
- **Week 4**: Phase 4 - Improve dependency injection
- **Week 5**: Phase 5 - Reduce method complexity

**Total Duration**: 5 weeks with proper testing and deployment cycles

## Conclusion

This refactoring plan addresses the core architectural issues while maintaining the existing functionality and API contracts. The phased approach allows for gradual implementation with minimal risk to the production system. The end result will be a much more maintainable, testable, and extensible media processing architecture that follows Laravel best practices and SOLID principles.