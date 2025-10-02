# Media Processing Architectural Cleanup Plan
## Ecosystem Preservation + Strategic Integration Approach

## Executive Summary

This document outlines a strategic architectural cleanup plan that **completely preserves the working livestream processing ecosystem** while fixing the broken audio/video processing by integrating them into the existing, proven job chains at appropriate entry points. This approach eliminates code duplication while respecting the sophisticated livestream architecture.

## Current State Analysis

### Working Livestream Ecosystem ✅ (PRESERVE COMPLETELY)
- **Complete Processing Chain**: UI → `/api/livestreams/process` → `LivestreamProcessingController` → `VideoProcessingService.processWithSegmentation()` → `LivestreamSegmentationService` → hardcoded job chain
- **Sophisticated Job Chain**: RMS Analysis → Segmentation → Sermon Extraction → Transcription → AI Analysis → Thumbnails
- **Dedicated Infrastructure**: `LivestreamProcessingLog` model, `LivestreamSegment` model, specialized jobs (`GenerateRmsLog`, `AnalyzeSegments`, `ExtractSermon`)
- **S3 Hybrid Processing**: Working DigitalOcean Spaces integration with local temp processing
- **Service Ecosystem**: `VideoSegmentationService`, `LivestreamSegmentationService`, `LivestreamStatusService`

### Broken Entry Points ❌ (FIX TO JOIN WORKING ECOSYSTEM)
- **Audio Processing**: `/api/sermons/audio` → Broken `AudioProcessingStrategy` → Should join existing sermon processing jobs
- **Video Processing**: `/api/sermons/video` → Broken `DirectVideoProcessingStrategy` → Should extract audio then join sermon processing
- **Strategy Pattern**: Half-implemented pattern that should be deleted entirely
- **Fragmented Configuration**: Two config files that can be merged
- **AutomatedSermonController**: Broken controller that should be replaced with simple dispatcher

## Architectural Principles

### Ecosystem Preservation Strategy
- **Complete Livestream Preservation**: The entire livestream processing ecosystem stays exactly as-is (services, jobs, models, logic)
- **Strategic Integration**: Audio/video processing joins existing, proven job chains at appropriate entry points
- **Delete Broken Code**: Remove the half-implemented strategy pattern entirely
- **Minimal Changes**: Only change what's necessary to fix broken functionality

### Integration Points Strategy
- **Livestream Processing**: Completely untouched (Video → RMS → Segmentation → Extract → Sermon Processing)
- **Video Processing**: Extract audio → Join at sermon processing step (reuse `TranscribeAudio`, `ProcessTranscriptWithAI`, etc.)
- **Audio Processing**: Jump directly to sermon processing step (reuse existing transcription/analysis jobs)

### Design Constraints
- **Zero Livestream Changes**: Preserve every aspect of the working livestream system
- **Leverage Existing Jobs**: Reuse proven job classes instead of duplicating logic
- **Simple Dispatcher**: Replace complex strategy pattern with simple routing logic

## Phase 1: Strategic Integration Implementation

### 1.1 Simple Configuration Consolidation

**Replace ALL configs with `config/media-processing.php`:**
```php
<?php

return [
    'types' => [
        'audio' => [
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'allowed_mimes' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/m4a'],
            'queue' => 'audio-processing',
            'description' => 'Audio sermon files',
        ],
        'video' => [
            'max_file_size' => 1024 * 1024 * 1024, // 1GB (reasonable middle ground)
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
            'queue' => 'video-processing',
            'description' => 'Direct sermon video files',
        ],
        'livestream' => [
            'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            'queue' => 'livestream-processing',
            'description' => 'Full livestream recordings requiring segmentation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3/DigitalOcean Spaces Storage Configuration
    |--------------------------------------------------------------------------
    |
    | CRITICAL: This system uses sophisticated hybrid processing for S3-compatible
    | storage (DigitalOcean Spaces). The system automatically detects S3-compatible
    | disks and uses hybrid processing: local temp processing → cloud upload.
    |
    | - sermon_disk: Final storage (can be do_spaces, s3, or local)
    | - temp_disk: MUST be 'local' for FFmpeg processing
    | - storage_disk: General file storage
    |
    */
    'storage' => [
        // Main storage disk - can be S3-compatible (do_spaces) or local
        'disk' => env('MEDIA_STORAGE_DISK', 'do_spaces'),
        'sermon_disk' => env('SERMON_STORAGE_DISK', 'do_spaces'),

        // Temporary processing - MUST be local for FFmpeg
        'temp_disk' => 'local',

        // Storage paths
        'paths' => [
            'audio' => env('MEDIA_AUDIO_PATH', 'sermons/audio'),
            'video' => env('MEDIA_VIDEO_PATH', 'sermons/video'),
            'temp' => env('MEDIA_TEMP_PATH', 'temp/media-processing'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Hybrid Processing Configuration
    |--------------------------------------------------------------------------
    |
    | The system auto-detects S3-compatible disks and uses hybrid processing:
    | 1. Process files locally in temp directory
    | 2. Upload final results to S3-compatible storage
    | 3. Clean up local temp files
    |
    */
    's3_processing' => [
        'upload_timeout' => env('S3_UPLOAD_TIMEOUT', 300), // 5 minutes
        'retry_attempts' => env('S3_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('S3_RETRY_DELAY', 5), // seconds
        'cleanup_temp_files' => env('S3_CLEANUP_TEMP', true),
        'multipart_threshold' => env('S3_MULTIPART_THRESHOLD', 100 * 1024 * 1024), // 100MB
    ],

    'processing' => [
        'timeout' => 7200, // 2 hours
        'retry_attempts' => 3,
        'retry_delay' => 60,
        'max_concurrent_jobs' => 2,
    ],

    'transcription' => [
        'service' => env('TRANSCRIPTION_SERVICE_TYPE', 'openai'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'max_file_size' => 25 * 1024 * 1024, // 25MB
        'timeout' => 300,
    ],

    'ffmpeg' => [
        'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Livestream Segmentation Configuration
    |--------------------------------------------------------------------------
    |
    | RMS analysis and segmentation settings for livestream processing
    |
    */
    'segmentation' => [
        'rms_threshold' => (float) env('RMS_THRESHOLD', -45.0),
        'min_section_duration' => (float) env('MIN_SECTION_DURATION', 60.0),
        'min_sermon_duration' => (float) env('MIN_SERMON_DURATION', 300.0),
        'adaptive_thresholds' => [
            'enabled' => env('ADAPTIVE_THRESHOLDS_ENABLED', true),
            'speech_percentile' => env('SPEECH_PERCENTILE', 30),
            'fallback_enabled' => env('ADAPTIVE_FALLBACK_ENABLED', true),
            'min_threshold' => (float) env('MIN_THRESHOLD', -80.0),
            'max_threshold' => (float) env('MAX_THRESHOLD', -20.0),
            'min_sample_count' => env('MIN_SAMPLE_COUNT', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Extraction for Transcription
    |--------------------------------------------------------------------------
    |
    | Optimized audio extraction settings for transcription services
    |
    */
    'audio_extraction' => [
        'transcription_optimized' => [
            'bitrate' => 48, // kbps
            'sample_rate' => 16000, // Hz
            'channels' => 1, // mono
            'max_file_size' => 25 * 1024 * 1024, // 25MB OpenAI Whisper limit
        ],
        'fallback_compression' => [
            'bitrate' => 32, // kbps
            'sample_rate' => 16000, // Hz
            'channels' => 1, // mono
        ],
        'validation' => [
            'max_duration_minutes' => 150,
            'size_check_enabled' => true,
            'quality_check_enabled' => true,
        ],
    ],
];
```

**Delete these files entirely:**
- `config/sermon-processing.php`
- `config/livestream-processing.php`

### 1.2 Single Unified Controller

**Create `app/Http/Controllers/Api/MediaController.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\ProcessingStatusContract;
use App\Data\StandardProcessingResponse;
use App\Http\Controllers\Controller;
use App\Services\ProcessingLogService;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller implements ProcessingStatusContract
{
    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
        private readonly ProcessingLogService $processingLogService
    ) {}

    /**
     * Upload and process media file - handles all types (audio, video, livestream)
     */
    public function upload(Request $request, string $type): JsonResponse
    {
        // Validate type
        if (!in_array($type, ['audio', 'video', 'livestream'])) {
            return response()->json([
                'success' => false,
                'message' => "Unsupported media type: {$type}",
                'error_code' => 'INVALID_MEDIA_TYPE',
            ], 400);
        }

        try {
            // Dynamic validation based on type
            $config = config("media-processing.types.{$type}");
            $maxSizeKB = $config['max_file_size'] / 1024;
            $allowedExtensions = implode(',', $config['allowed_extensions']);

            $request->validate([
                'file' => "required|file|mimes:{$allowedExtensions}|max:{$maxSizeKB}",
            ]);

            $file = $request->file('file');

            Log::info('Media upload initiated', [
                'type' => $type,
                'user_id' => $request->user()?->id,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            $result = $this->mediaProcessor->process($type, $file);

            if ($result->success) {
                return response()->json($result->toArray(), 202);
            } else {
                return response()->json($result->toArray(), 422);
            }

        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'type' => $type,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Media upload failed',
                'error_code' => 'UPLOAD_FAILED',
            ], 500);
        }
    }

    /**
     * Get processing status - unified for all media types
     */
    public function status(Request $request, string $processingId): JsonResponse
    {
        try {
            $includeLogs = $request->boolean('include_logs');
            $logLimit = $request->integer('log_limit', 20);

            $response = $includeLogs
                ? $this->getProcessingStatusWithLogs($processingId, true, $logLimit)
                : $this->getProcessingStatus($processingId);

            if (!$response->found) {
                return response()->json($response->toArray(), 404);
            }

            return response()->json($response->toArray(), 200);

        } catch (\Exception $e) {
            Log::error('Status check failed', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['found' => false, 'message' => 'Status check failed'], 500);
        }
    }

    // Implement ProcessingStatusContract methods
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        return $this->mediaProcessor->getStatus($processingId);
    }

    public function getProcessingStatusWithLogs(string $processingId, bool $includeLogs, int $logLimit): StandardProcessingResponse
    {
        $baseStatus = $this->getProcessingStatus($processingId);

        if (!$baseStatus->found || !$includeLogs) {
            return $baseStatus;
        }

        $logs = $this->processingLogService->getProcessingLogs($processingId, $logLimit);
        $metrics = $this->processingLogService->getPerformanceMetrics($processingId);

        return StandardProcessingResponse::withLogs(
            processingId: $baseStatus->processingId,
            status: $baseStatus->status,
            currentStep: $baseStatus->currentStep,
            progressPercentage: $baseStatus->progressPercentage,
            errorMessage: $baseStatus->errorMessage,
            sermonId: $baseStatus->sermonId,
            sermonUrl: $baseStatus->sermonUrl,
            startedAt: $baseStatus->startedAt,
            updatedAt: $baseStatus->updatedAt,
            estimatedCompletion: $baseStatus->estimatedCompletion,
            additionalData: $baseStatus->additionalData,
            logs: $logs,
            metrics: $metrics
        );
    }

    public function cancelProcessing(string $processingId): array
    {
        return $this->mediaProcessor->cancel($processingId);
    }

    public function canHandle(string $processingId): bool
    {
        return $this->mediaProcessor->canHandle($processingId);
    }

    /**
     * Cancel processing
     */
    public function cancel(Request $request, string $processingId): JsonResponse
    {
        try {
            $result = $this->cancelProcessing($processingId);
            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Cancel failed'], 500);
        }
    }

    /**
     * Retry processing
     */
    public function retry(Request $request, string $processingId): JsonResponse
    {
        try {
            $result = $this->mediaProcessor->retry($processingId);
            return response()->json($result->toArray(), $result->success ? 202 : 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Retry failed'], 500);
        }
    }
}
```

### 1.3 Single Processing Service

**Create `app/Services/UnifiedMediaProcessor.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StandardProcessingResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class UnifiedMediaProcessor
{
    public function __construct(
        private readonly VideoProcessingService $videoService,
        private readonly SermonProcessingService $sermonService
    ) {}

    public function process(string $type, UploadedFile $file): ProcessingResult
    {
        Log::info('Unified media processing started', [
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return match ($type) {
            // Audio: Jump directly to existing sermon processing (reuse existing jobs)
            'audio' => $this->sermonService->processSermon($file),

            // Video: Extract audio then join sermon processing (create new method)
            'video' => $this->processDirectVideo($file),

            // Livestream: Preserve existing system completely (no changes)
            'livestream' => $this->videoService->processWithSegmentation($file),

            default => ProcessingResult::failure(
                processingId: 'invalid-' . \Illuminate\Support\Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            ),
        };
    }

    public function getStatus(string $processingId): StandardProcessingResponse
    {
        // Try sermon processing first
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->sermonService->getProcessingStatus($processingId);
        }

        // Then try livestream processing
        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->videoService->getProcessingStatus($processingId);
        }

        return StandardProcessingResponse::notFound();
    }

    public function cancel(string $processingId): array
    {
        // Try both processing types
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->sermonService->cancelProcessing($processingId);
        }

        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->videoService->cancelProcessing($processingId);
        }

        return ['success' => false, 'message' => 'Processing ID not found'];
    }

    public function retry(string $processingId): ProcessingResult
    {
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->sermonService->retryProcessing($processingId);
        }

        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->videoService->retryProcessing($processingId);
        }

        return ProcessingResult::failure(
            processingId: $processingId,
            message: 'Processing ID not found for retry',
            errorCode: 'NOT_FOUND'
        );
    }

    public function canHandle(string $processingId): bool
    {
        return \App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists() ||
               \App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists();
    }

    /**
     * Process video by extracting audio and joining existing sermon processing
     * This reuses the proven job chain instead of creating new processing logic
     */
    private function processDirectVideo(UploadedFile $file): ProcessingResult
    {
        try {
            $processingId = \Illuminate\Support\Str::uuid()->toString();

            // Store video file temporarily
            $tempPath = $file->store('temp/video-processing');

            // Create sermon processing log (NOT livestream processing log)
            $processingLog = \App\Models\SermonProcessingLog::create([
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'stored_file_path' => $tempPath,
                'status' => \App\Enums\ProcessingStatus::PENDING,
                'current_step' => 'video_processing_initiated',
            ]);

            // Dispatch job chain that extracts audio then joins sermon processing
            \Illuminate\Support\Facades\Bus::chain([
                new \App\Jobs\ValidateVideoFile($processingLog),
                new \App\Jobs\ExtractAudioFromVideo($processingLog),  // Extract audio from video
                new \App\Jobs\TranscribeAudio($processingLog),        // Join existing sermon processing
                new \App\Jobs\ProcessTranscriptWithAI($processingLog), // Reuse existing jobs
                new \App\Jobs\CreateSermonRecord($processingLog),      // Create sermon record
                new \App\Jobs\GenerateThumbnail($processingLog),       // Generate thumbnail from video
                new \App\Jobs\SendCompletionNotification($processingLog),
            ])->catch(function (\Throwable $e) use ($processingLog) {
                $processingLog->update([
                    'status' => \App\Enums\ProcessingStatus::FAILED,
                    'error_message' => 'Video processing failed: ' . $e->getMessage(),
                ]);
            })->onQueue('video-processing')->dispatch();

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            return ProcessingResult::failure(
                processingId: 'failed-' . \Illuminate\Support\Str::uuid(),
                message: 'Failed to initiate video processing: ' . $e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }
}
```

## Phase 2: Strategy Pattern Removal + Livestream Preservation

### 2.1 Delete Strategy Pattern Entirely

**Delete these files:**
- `app/Services/ProcessingRouter.php`
- `app/Services/ProcessingStrategyRegistry.php`
- `app/Services/Strategies/AudioProcessingStrategy.php`
- `app/Services/Strategies/DirectVideoProcessingStrategy.php`
- `app/Services/Strategies/LivestreamProcessingStrategy.php`
- `app/Contracts/ProcessingStrategyInterface.php`
- `app/Contracts/ProcessingRouterInterface.php`

**Reason**: The strategy pattern was half-implemented and adds unnecessary complexity. The working livestream processing doesn't use it, and the broken audio/video processing should be fixed to use existing services directly.

### 2.2 Preserve VideoProcessingService Completely

**DO NOT MODIFY VideoProcessingService** - it's part of the working livestream ecosystem.

**Current VideoProcessingService methods (PRESERVE ALL):**
- `processWithSegmentation()` - Used by working livestream processing
- `processDirectly()` - Delegates to segmentation service
- `getProcessingStatus()` - Status management for livestream
- `retryProcessing()` - Retry logic for livestream
- `cancelProcessing()` - Cancel logic for livestream

**Critical Dependencies (PRESERVE ALL):**
- `LivestreamSegmentationService` - Core orchestration
- `LivestreamStatusService` - Status management
- `VideoSegmentationService` - RMS analysis and segmentation
- `VideoStorageService` - S3-aware storage operations

## Phase 3: Complete Route Consolidation

### 3.1 Single Route Structure

**Replace `routes/api.php` entirely with:**
```php
<?php

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\SermonApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authentication route
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Sermon data endpoints (read-only)
Route::prefix('sermons')->name('api.sermons.')->middleware('cors')->group(function () {
    Route::get('/', [SermonApiController::class, 'index'])
        ->middleware('throttle:api')
        ->name('index');

    Route::get('{sermon}', [SermonApiController::class, 'show'])
        ->middleware('throttle:api')
        ->name('show');
});

// Unified media processing endpoints
Route::prefix('media')->name('api.media.')->middleware('cors')->group(function () {
    // Upload endpoints for each type
    Route::post('{type}', [MediaController::class, 'upload'])
        ->where('type', 'audio|video|livestream')
        ->middleware(['auth:sanctum', 'throttle:media-upload'])
        ->name('upload');

    // Processing management
    Route::prefix('processing')->name('processing.')->group(function () {
        Route::get('{processingId}/status', [MediaController::class, 'status'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('status');

        Route::delete('{processingId}', [MediaController::class, 'cancel'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('cancel');

        Route::post('{processingId}/retry', [MediaController::class, 'retry'])
            ->middleware(['auth:sanctum', 'throttle:media-retry'])
            ->name('retry');
    });
});
```

### 3.2 Update UI to Use New Endpoints

**Check current livestream UI endpoint and update to use:**
- `/api/media/livestream` instead of `/api/livestreams/process`

**Update frontend code in `resources/` to use new endpoint structure.**

## Phase 4: Service Provider Cleanup

### 4.1 Simplified MediaProcessingServiceProvider

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UnifiedMediaProcessor;
use App\Services\VideoProcessingService;
use App\Services\SermonProcessingService;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the unified processor
        $this->app->bind(UnifiedMediaProcessor::class, function ($app) {
            return new UnifiedMediaProcessor(
                $app->make(VideoProcessingService::class),
                $app->make(SermonProcessingService::class)
            );
        });

        // Keep existing service registrations that work
        $this->app->bind(VideoProcessingService::class);
        $this->app->bind(SermonProcessingService::class);
    }

    public function boot(): void
    {
        // Publish config if needed
        $this->publishes([
            __DIR__.'/../../config/media-processing.php' => config_path('media-processing.php'),
        ], 'media-processing');
    }
}
```

## Phase 5: Complete File Removal

### 5.1 Delete Legacy Controllers
```bash
rm app/Http/Controllers/AutomatedSermonController.php
rm app/Http/Controllers/Api/LivestreamProcessingController.php
```

### 5.2 Delete Strategy Pattern Files
```bash
rm -rf app/Services/Strategies/
rm app/Services/ProcessingRouter.php
rm app/Services/ProcessingStrategyRegistry.php
rm app/Contracts/ProcessingStrategyInterface.php
rm app/Contracts/ProcessingRouterInterface.php
```

### 5.3 Delete Old Configs
```bash
rm config/sermon-processing.php
rm config/livestream-processing.php
```

### 5.4 Update All Config References

**Search and replace throughout codebase:**
- `config('sermon-processing.` → `config('media-processing.`
- `config('livestream-processing.` → `config('media-processing.`

## Phase 5.1: S3/DigitalOcean Spaces Storage Preservation

### CRITICAL: Storage Infrastructure Preservation

**The existing S3/DigitalOcean Spaces hybrid processing system MUST be preserved:**

1. **S3 Detection Logic**: All services use `isS3CompatibleDisk()` methods to auto-detect S3-compatible storage
2. **Hybrid Processing Pattern**:
   - S3-compatible disks: Process locally → Upload to cloud → Cleanup temp
   - Local disks: Process directly
3. **Configuration Mapping**: Update all config references but maintain storage disk selection
4. **CDN Integration**: Preserve DigitalOcean Spaces CDN endpoint handling

### 5.1.1 Update All Config References

**Critical config reference updates throughout codebase:**

```bash
# Search and replace config references
find app/ -type f -name "*.php" -exec sed -i '' \
  -e 's/config('\''sermon-processing\./config('\''media-processing\./g' \
  -e 's/config('\''livestream-processing\./config('\''media-processing\./g' {} \;
```

**Specific mappings to preserve:**
- `livestream-processing.sermon_disk` → `media-processing.storage.sermon_disk`
- `livestream-processing.temp_disk` → `media-processing.storage.temp_disk`
- `livestream-processing.storage.audio_path` → `media-processing.storage.paths.audio`
- `livestream-processing.storage.video_path` → `media-processing.storage.paths.video`
- `livestream-processing.s3_processing.*` → `media-processing.s3_processing.*`

### 5.1.2 Preserve S3-Aware Services

**These services MUST be updated to use new config but preserve all S3 logic:**
- `VideoStorageService`: S3-compatible operations with local fallback
- `VideoExtractionService`: S3-aware extraction with hybrid processing
- `SermonStorageService`: Multi-pattern file detection and CDN URL generation
- `ThumbnailGenerationService`: S3-aware thumbnail generation
- `AudioTranscriptionService`: S3-aware audio processing
- `VideoSegmentationService`: S3-aware video analysis

### 5.1.3 S3 Service Updates Example

**Update VideoExtractionService constructor:**
```php
public function __construct()
{
    // OLD: config('livestream-processing.ffmpeg_path')
    // NEW: config('media-processing.ffmpeg.ffmpeg_path')

    $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');
    $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

    // ... existing S3 logic remains unchanged

    $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
    $this->permanentDisk = config('media-processing.storage.sermon_disk', 'do_spaces');
    $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
}
```

## Phase 6: Testing and Validation

### 6.1 Critical Tests

**Must verify before deployment:**
- [ ] **S3/Spaces Storage**: DigitalOcean Spaces uploads still work
- [ ] **Hybrid Processing**: Local temp → S3 upload → cleanup works
- [ ] **CDN URLs**: Sermon URLs resolve correctly through CDN
- [ ] Livestream UI upload still works with new endpoint
- [ ] Audio upload now works through `/api/media/audio`
- [ ] Video upload now works through `/api/media/video`
- [ ] All processing pipelines complete successfully
- [ ] Status checking works across all types
- [ ] Error handling provides clear feedback

### 6.1.1 S3 Storage Specific Tests

**Critical S3 functionality tests:**
- [ ] Large file uploads (>100MB) to DigitalOcean Spaces
- [ ] Hybrid processing: local extraction → S3 upload
- [ ] CDN URL generation for sermon playback
- [ ] S3 retry logic with exponential backoff
- [ ] Temporary file cleanup after S3 upload
- [ ] S3 multipart upload for large files

### 6.2 Remove Dead Code

**After testing, remove:**
- Any unused job classes
- Any unused service methods
- Any unused configuration keys
- Any unused database migrations

## Final Architecture

### Three Distinct Processing Flows
```
Audio:      Upload → MediaController → UnifiedMediaProcessor → SermonProcessingService → Audio Jobs → Complete
Video:      Upload → MediaController → UnifiedMediaProcessor → Extract Audio → Audio Jobs → Complete
Livestream: Upload → MediaController → UnifiedMediaProcessor → VideoProcessingService → Full Livestream Chain → Complete
```

### Three Clean Endpoints (Minimal Changes)
- `POST /api/media/audio` - Joins existing sermon processing at transcription step
- `POST /api/media/video` - Extracts audio then joins sermon processing
- `POST /api/media/livestream` - **Preserves entire existing livestream system**

### Strategic Code Reuse (Not Unification)
- **Livestream Processing**: Complete preservation of existing ecosystem
- **Audio Processing**: Direct use of existing `SermonProcessingService`
- **Video Processing**: Extract audio → reuse audio processing jobs
- **Shared Infrastructure**: S3 storage, job classes, status management

### Ecosystem-Aware Status Management
- Single status endpoint: `GET /api/media/processing/{id}/status`
- Handles both `LivestreamProcessingLog` and `SermonProcessingLog` models
- Preserves existing status tracking for livestream
- Consistent response format across all types

## Benefits

### Immediate Benefits
- **Zero Risk to Production**: Complete preservation of working livestream system
- **Fixed Broken Functionality**: Audio and video uploads now work by joining proven job chains
- **Eliminated Dead Code**: Removed half-implemented strategy pattern
- **Simplified Configuration**: Single config file with backward compatibility

### Architectural Benefits
- **Preserved Working Ecosystem**: Livestream processing untouched and fully functional
- **Strategic Integration**: Audio/video processing leverages existing, proven job chains
- **Reduced Complexity**: Deleted broken strategy pattern instead of trying to fix it
- **Maintained Sophistication**: S3 hybrid processing and segmentation logic preserved

### Operational Benefits
- **Consistent Upload Experience**: Single controller with three working endpoints
- **Unified Status Management**: Single status API that handles both processing types
- **Preserved Performance**: Livestream processing maintains existing performance characteristics
- **Enhanced Reliability**: Audio/video processing now uses proven job infrastructure

### Developer Benefits
- **Clear Architecture**: Three distinct flows, each optimized for its use case
- **Easy Maintenance**: Working systems stay working, broken systems fixed with minimal code
- **Reusable Components**: Audio/video processing reuses existing job classes
- **Future-Proof Design**: Easy to extend without breaking existing functionality

This strategic cleanup preserves the sophisticated working livestream ecosystem while fixing broken functionality through minimal, targeted integration points.