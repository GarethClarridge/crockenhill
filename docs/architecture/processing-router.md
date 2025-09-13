# ProcessingRouter Architecture

## Overview

The `ProcessingRouter` is the central component of the unified media processing architecture. It acts as an intelligent routing layer that directs different types of media uploads to appropriate processing services based on explicit user choices.

## Design Philosophy

### Smart Routing Without Auto-Detection

The ProcessingRouter takes a deliberately simple approach to routing - it relies on **explicit user choice** rather than complex auto-detection logic.

**Why This Approach?**
- ✅ **Reliable**: No ambiguity about processing intent
- ✅ **Fast**: No expensive file analysis required
- ✅ **Transparent**: Users understand exactly what processing will occur  
- ✅ **Maintainable**: Simple routing logic reduces complexity

### Dependency Injection Architecture

```php
public function __construct(
    private readonly VideoProcessingService $videoProcessor,
    private readonly SermonProcessingService $sermonProcessor
) {}
```

The ProcessingRouter uses constructor dependency injection to access the two core processing services, enabling clean separation of concerns and easy testing.

## Routing Logic

### Media Type Routing

```php
// Audio files → Sermon processing pipeline
$result = $this->sermonProcessor->processSermon($file);

// Sermon videos → Direct video processing (no segmentation)
$result = $this->videoProcessor->processDirectly($file);

// Livestream videos → Video processing with segmentation
$result = $this->videoProcessor->processWithSegmentation($file);
```

### Route Methods

#### `routeAudio(UploadedFile $file): ProcessingResult`

Routes audio files to the SermonProcessingService for:
- Audio transcription
- AI metadata extraction  
- Sermon record creation

**Processing Flow:**
```
Audio File → Transcription → AI Analysis → Sermon Record
```

#### `routeSermonVideo(UploadedFile $file): ProcessingResult` 

Routes sermon-only video files to VideoProcessingService direct processing:
- Full video audio extraction
- Transcription and AI analysis
- Video file preservation
- Sermon record creation with video link

**Processing Flow:**
```
Video File → Audio Extraction → Transcription → AI Analysis → Sermon Record + Video Storage
```

#### `routeLivestreamVideo(UploadedFile $file): ProcessingResult`

Routes full livestream recordings to VideoProcessingService segmentation pipeline:
- RMS audio level analysis
- Segment classification (music vs speech)
- Sermon portion extraction
- Video preservation of both full and extracted content
- Standard sermon processing of extracted audio

**Processing Flow:**
```
Livestream → RMS Analysis → Segmentation → Sermon Extraction → Audio Processing → Sermon Record + Videos
```

## Validation System

### File Type Validation

The ProcessingRouter includes comprehensive validation before routing:

```php
public function validateFileForType(UploadedFile $file, string $type): array
{
    // Validates:
    // - File size against type-specific limits
    // - File extension against allowed types
    // - File integrity
    
    return [
        'valid' => bool,
        'errors' => array
    ];
}
```

### Configuration-Driven Limits

```php
public function getSupportedTypes(): array
{
    return [
        'livestream' => [
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'max_size' => config('livestream-processing.max_file_size', 2147483648), // 2GB
        ],
        'sermon_video' => [
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'max_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
        ],
        'audio' => [
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'max_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
        ],
    ];
}
```

This configuration-driven approach allows different file size limits and formats per processing type without hardcoding values.

## Integration with Controllers

### AutomatedSermonController Integration

The ProcessingRouter integrates seamlessly with the controller layer:

```php
public function __construct(
    private readonly ProcessingRouter $processingRouter,
    private readonly SermonProcessingService $sermonProcessingService
) {}

public function uploadLivestream(Request $request): JsonResponse
{
    $file = $request->file('file');
    $result = $this->processingRouter->routeLivestreamVideo($file);
    return response()->json($result->toArray(), 202);
}

public function uploadVideo(SermonVideoUploadRequest $request): JsonResponse  
{
    $file = $request->file('file');
    $result = $this->processingRouter->routeSermonVideo($file);
    return response()->json($result->toArray(), 202);
}

public function upload(AutomatedSermonUploadRequest $request): JsonResponse
{
    $file = $request->file('file');
    $result = $this->processingRouter->routeAudio($file);
    return response()->json($result->toArray(), 202);
}
```

## Error Handling & Logging

### Comprehensive Logging

The ProcessingRouter logs all routing decisions:

```php
Log::info('Routing to livestream video processing', [
    'filename' => $file->getClientOriginalName(),
    'size' => $file->getSize(),
]);
```

This provides visibility into:
- Which files are being processed
- Routing decisions made
- File metadata for debugging

### Standardized Error Responses

All routing methods return `ProcessingResult` objects with standardized formats:

```php
// Success
ProcessingResult::success(
    processingId: $processingId,
    message: 'Processing initiated successfully',
    statusUrl: $statusUrl
);

// Failure  
ProcessingResult::failure(
    processingId: $failureId,
    message: 'Processing failed: ' . $errorMessage,
    errorCode: 'PROCESSING_ERROR'
);
```

## Monitoring & Statistics

### Routing Statistics

```php
public function getRoutingStatistics(): array
{
    return [
        'supported_types' => array_keys($this->getSupportedTypes()),
        'routes_available' => [
            'livestream' => 'VideoProcessingService::processWithSegmentation',
            'sermon_video' => 'VideoProcessingService::processDirectly', 
            'audio' => 'SermonProcessingService::processSermon',
        ],
        'validation_rules' => $this->getSupportedTypes(),
    ];
}
```

This enables monitoring dashboards to show:
- Available processing routes
- Configuration limits
- Validation rules per type

## Extension Points

### Adding New Media Types

To add a new media type:

1. **Add route method:**
```php
public function routeNewType(UploadedFile $file): ProcessingResult
{
    return $this->appropriateService->processNewType($file);
}
```

2. **Update supported types configuration:**
```php
'new_type' => [
    'allowed_extensions' => ['ext1', 'ext2'],
    'max_size' => $configValue,
],
```

3. **Add controller endpoint:**
```php
Route::post('new-type', [AutomatedSermonController::class, 'uploadNewType']);
```

### Custom Validation Rules

The validation system can be extended with custom rules per media type:

```php
protected function validateCustomRules(UploadedFile $file, string $type): array
{
    // Add type-specific validation logic
    return $errors;
}
```

## Benefits of the ProcessingRouter Architecture

### Unified Entry Point
- **Single responsibility**: Routes media to appropriate processors
- **Consistent interface**: All processing types use same routing pattern
- **Easy testing**: Simple dependency injection enables unit testing

### Separation of Concerns
- **Router**: Handles routing and validation only
- **Processors**: Focus on their specific processing logic  
- **Controllers**: Handle HTTP concerns and response formatting

### Configuration Management
- **Centralized limits**: File size and format rules in one place
- **Environment-specific**: Can vary limits between dev/staging/production
- **Type-specific**: Different rules per media type

### Extensibility
- **New media types**: Easy to add without changing existing code
- **New processors**: Can be integrated via dependency injection
- **Custom validation**: Extensible validation system

### Monitoring & Debugging
- **Comprehensive logging**: All routing decisions are logged
- **Statistics endpoint**: Real-time routing statistics
- **Error tracking**: Standardized error handling and reporting

This architecture provides the foundation for the unified media processing pipeline while maintaining clean separation of concerns and extensibility for future requirements.