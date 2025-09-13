# Processing Contracts Architecture

## Overview

The Processing Contracts system provides a unified interface for all media processing controllers, ensuring consistent API responses and enabling polymorphic processing operations across different processing types.

## Core Components

### ProcessingStatusContract Interface

The `ProcessingStatusContract` interface defines the required methods for all processing controllers:

```php
interface ProcessingStatusContract
{
    public function getProcessingStatus(string $processingId): StandardProcessingResponse;
    public function cancelProcessing(string $processingId): array;
    public function canHandle(string $processingId): bool;
}
```

### StandardProcessingResponse Data Structure

The `StandardProcessingResponse` class provides a unified response format across all processing types:

```php
class StandardProcessingResponse
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $processingId = null,
        public readonly ?string $status = null,
        public readonly ?string $currentStep = null,
        public readonly int $progressPercentage = 0,
        public readonly ?string $errorMessage = null,
        public readonly ?int $sermonId = null,
        public readonly ?string $sermonUrl = null,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $updatedAt = null,
        public readonly ?string $estimatedCompletion = null,
        public readonly array $additionalData = []
    ) {}
}
```

## Contract Implementation

### AutomatedSermonController Implementation

```php
class AutomatedSermonController extends Controller implements ProcessingStatusContract
{
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        // Get status from SermonProcessingLog
        $result = $this->sermonProcessingService->getProcessingStatus($processingId);
        
        return StandardProcessingResponse::fromProcessingStatusResult($result);
    }

    public function cancelProcessing(string $processingId): array
    {
        return $this->sermonProcessingService->cancelProcessing($processingId);
    }

    public function canHandle(string $processingId): bool
    {
        return SermonProcessingLog::where('processing_id', $processingId)->exists();
    }
}
```

### LivestreamProcessingController Implementation

```php
class LivestreamProcessingController extends Controller implements ProcessingStatusContract  
{
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        // Get status from LivestreamProcessingLog
        $status = $this->videoProcessingService->getProcessingStatus($processingId);
        
        return StandardProcessingResponse::found(
            processingId: $status->processingId,
            status: $status->status,
            currentStep: $status->currentStep,
            progressPercentage: $status->progressPercentage,
            // ... additional data
        );
    }

    public function cancelProcessing(string $processingId): array
    {
        $cancelled = $this->videoProcessingService->cancelProcessing($processingId);
        return ['success' => $cancelled, 'processing_id' => $processingId];
    }

    public function canHandle(string $processingId): bool
    {
        return LivestreamProcessingLog::where('processing_id', $processingId)->exists();
    }
}
```

## Benefits of Contract-Based Architecture

### 1. API Consistency

#### Unified Response Format
All processing status endpoints return the same response structure:

```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "processing",
  "current_step": "transcription", 
  "progress_percentage": 75,
  "started_at": "2024-01-15T10:00:00Z",
  "updated_at": "2024-01-15T10:15:00Z"
}
```

This consistency enables:
- **Easier client integration**: Frontend code can handle all processing types identically
- **Better documentation**: Single response format to document
- **Reduced complexity**: No need to handle different response formats per processor

#### Standardized Error Handling
```json
{
  "found": false,
  "message": "Processing record not found"
}
```

### 2. Polymorphic Processing

The contract system enables polymorphic operations across different processing types:

```php
// Generic status checker that works with any processing type
class ProcessingStatusChecker
{
    public function checkStatus(string $processingId): StandardProcessingResponse
    {
        $controllers = [
            app(AutomatedSermonController::class),
            app(LivestreamProcessingController::class),
        ];
        
        foreach ($controllers as $controller) {
            if ($controller->canHandle($processingId)) {
                return $controller->getProcessingStatus($processingId);
            }
        }
        
        return StandardProcessingResponse::notFound();
    }
}
```

This enables:
- **Single status endpoint**: One endpoint can handle all processing types
- **Automatic routing**: System automatically finds appropriate handler
- **Easy extension**: New processing types integrate seamlessly

### 3. Enhanced Monitoring

The standardized interface enables comprehensive monitoring:

```php
class ProcessingMonitor
{
    public function getSystemStatus(): array
    {
        $controllers = $this->getAllProcessingControllers();
        $stats = [];
        
        foreach ($controllers as $type => $controller) {
            $stats[$type] = [
                'active_processing' => $this->countActive($controller),
                'failed_processing' => $this->countFailed($controller),
                'success_rate' => $this->calculateSuccessRate($controller),
            ];
        }
        
        return $stats;
    }
}
```

## StandardProcessingResponse Features

### Factory Methods

#### Success Response
```php
StandardProcessingResponse::found(
    processingId: $id,
    status: 'completed',
    sermonId: 123,
    sermonUrl: '/christ/sermons/sermon-slug'
);
```

#### Error Response
```php
StandardProcessingResponse::error('Processing failed due to transcription timeout');
```

#### Not Found Response  
```php
StandardProcessingResponse::notFound();
```

### Status Checking Methods

```php
$response = StandardProcessingResponse::found(/* ... */);

$response->isComplete();    // true if status === 'completed'
$response->isFailed();      // true if status === 'failed'  
$response->isInProgress();  // true if status in ['pending', 'processing']
```

### Backward Compatibility

The system includes legacy support:

```php
public static function fromProcessingStatusResult(ProcessingStatusResult $result): self
{
    // Converts legacy ProcessingStatusResult to StandardProcessingResponse
    return self::found(
        processingId: $result->processingId,
        status: $result->status->value,
        currentStep: $result->currentStep,
        // ...
    );
}
```

## Additional Data Support

### Type-Specific Information

Each processing type can include additional data in the response:

```php
// Livestream processing additional data
$additionalData = [
    'segments_found' => 12,
    'sermon_segments' => 3,
    'total_duration' => '01:32:45',
    'sermon_start_time' => '00:45:30',
    'sermon_end_time' => '01:15:20'
];

StandardProcessingResponse::found(
    processingId: $id,
    status: 'completed',
    additionalData: $additionalData
);
```

### Client Compatibility

The response includes both new and legacy field names:

```php
public function toArray(): array
{
    $response = [
        'found' => $this->found,
        'processing_id' => $this->processingId,
        'status' => $this->status,
        'started_at' => $this->startedAt?->toISOString(),
        'updated_at' => $this->updatedAt?->toISOString(),
        'created_at' => $this->startedAt?->toISOString(), // Backwards compatibility
    ];
    
    // Merge additional data
    return array_merge($response, $this->additionalData);
}
```

## Error Handling

### Consistent Error Responses

```php
try {
    $status = $this->getProcessingStatus($processingId);
    return response()->json($status->toArray());
} catch (ProcessingNotFoundException $e) {
    return response()->json(['found' => false, 'message' => 'Processing record not found'], 404);
} catch (\Exception $e) {
    return response()->json([
        'found' => false,
        'message' => 'Unable to retrieve processing status',
        'error' => $e->getMessage()
    ], 500);
}
```

### Graceful Degradation

```php
public function getProcessingStatus(string $processingId): StandardProcessingResponse
{
    try {
        $result = $this->service->getDetailedStatus($processingId);
        return StandardProcessingResponse::found(/* detailed status */);
    } catch (ServiceException $e) {
        // Fall back to basic status if detailed status fails
        return StandardProcessingResponse::found(
            processingId: $processingId,
            status: 'unknown',
            errorMessage: 'Status temporarily unavailable'
        );
    }
}
```

## Testing Benefits

The contract system enables comprehensive testing:

### Contract Compliance Testing
```php
class ProcessingContractTest extends TestCase
{
    public function test_all_controllers_implement_contract()
    {
        $controllers = [
            AutomatedSermonController::class,
            LivestreamProcessingController::class,
        ];
        
        foreach ($controllers as $controllerClass) {
            $this->assertInstanceOf(ProcessingStatusContract::class, app($controllerClass));
        }
    }
}
```

### Response Format Testing
```php  
public function test_status_response_format()
{
    $response = $this->controller->getProcessingStatus($processingId);
    
    $this->assertInstanceOf(StandardProcessingResponse::class, $response);
    $this->assertTrue($response->found);
    $this->assertEquals($processingId, $response->processingId);
    $this->assertArrayHasKey('processing_id', $response->toArray());
}
```

## Future Extension

### Adding New Processing Types

1. **Create new controller implementing contract:**
```php
class NewProcessingController extends Controller implements ProcessingStatusContract
{
    // Implement required methods
}
```

2. **Register in monitoring system:**
```php
$processingControllers = [
    'sermon' => AutomatedSermonController::class,
    'livestream' => LivestreamProcessingController::class, 
    'new_type' => NewProcessingController::class,
];
```

3. **No changes needed to client code** - contract ensures compatibility

### Enhanced Response Data

New fields can be added to StandardProcessingResponse without breaking existing clients:

```php
public function __construct(
    // ... existing parameters
    public readonly ?array $mediaMetadata = null,
    public readonly ?string $thumbnailUrl = null,
) {}
```

The contract-based architecture provides a solid foundation for consistent API responses, polymorphic processing operations, and easy extensibility as the media processing system evolves.