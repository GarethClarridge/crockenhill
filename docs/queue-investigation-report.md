# Queue Investigation Report

**Date**: January 2026
**Status**: Investigation Complete
**Priority**: Configuration fix needed for production

---

## Executive Summary

The project has a well-architected queue system with 17 jobs, comprehensive error handling, and proper job chaining. However, **the queue driver is set to `sync`**, meaning all jobs execute synchronously and block HTTP requests. This must be changed for production.

---

## Current Configuration

### Environment Settings (`.env`)

```env
QUEUE_DRIVER=sync                           # ⚠️ CRITICAL: Change to 'database' for production
QUEUE_CONNECTION=database
LIVESTREAM_QUEUE_CONNECTION=database
LIVESTREAM_QUEUE_NAME=livestream-processing
SERMON_PROCESSING_QUEUE=sermon-processing
```

### Queue Names in Use

| Queue Name | Purpose | Used By |
|------------|---------|---------|
| `default` | General processing | Most jobs |
| `thumbnails` | Non-critical thumbnail generation | `GenerateThumbnail` |
| `livestream-processing` | Livestream audio extraction | `SermonJobPipelineService` |
| `video-processing` | Video processing pipeline | `UnifiedMediaProcessor` |
| `sermon-processing` | Audio sermon processing | `SermonAudioProcessingService` |

---

## Jobs Inventory

### All Jobs (`app/Jobs/`)

| Job | Retries | Timeout | Backoff | Failed Handler |
|-----|---------|---------|---------|----------------|
| `AnalyzeSegments` | 3 | 30m | `retryUntil()` | ✅ |
| `CleanupTemporaryFiles` | 1 | 5m | None | ✅ |
| `CreateSermonRecord` | 3 | 2m | ⚠️ None | ✅ |
| `ExtractAudioFromVideo` | - | - | ⚠️ None | ✅ |
| `ExtractSermon` | 3 | 1h | `retryUntil()` | ✅ |
| `GenerateRmsLog` | 3 | 1h | `retryUntil()` | ✅ |
| `GenerateThumbnail` | 1 | 5m | None | ✅ |
| `PerformVisualAnalysis` | 3 | 1h | `retryUntil()` | ✅ |
| `ProcessTranscriptWithAI` | 3 | 10m | [2m, 5m, 10m] | ✅ |
| `ProcessingJob` | - | - | (Abstract base) | - |
| `SendCompletionNotification` | 3 | 2m | [30s, 2m, 5m] | ✅ |
| `SubmitToProcessing` | 3 | 30m | `retryUntil()` | ✅ |
| `TestJob` | - | - | None | ✅ |
| `TranscribeAudio` | 3 | 30m | [1m, 5m, 15m] | ✅ |
| `UpdateSermonRecord` | 3 | 5m | [30s, 2m, 5m] | ✅ |
| `ValidateAudioFile` | - | - | ⚠️ None | ✅ |
| `ValidateVideoFile` | - | - | ⚠️ None | ✅ |

### Statistics

- **Total Jobs**: 17
- **Implement ShouldQueue**: 17 (100%)
- **Have `failed()` handler**: 17 (100%)
- **Have retry logic**: 12 (71%)
- **Have backoff strategy**: 5 (29%)

---

## Job Chaining Implementation

### Main Processing Pipelines

#### 1. Sermon Pipeline (`SermonJobPipelineService.php:37-53`)

```php
Bus::chain($jobs)
    ->catch(function (\Throwable $e) use ($processingLog) {
        // Error handling
    })
    ->onQueue($queueName)
    ->dispatch();
```

#### 2. Video Processing (`UnifiedMediaProcessor.php:204-211`)

```php
Bus::chain($jobs)
    ->catch(function (\Throwable $e) use ($processingLog) {
        // Error handling
    })
    ->onQueue('video-processing')
    ->dispatch();
```

#### 3. Livestream Segmentation (`LivestreamSegmentationService.php:279-281`)

```php
Bus::chain($jobs)
    ->catch(...)
    ->onQueue(config('media-processing.queue.name', 'default'))
    ->dispatch();
```

#### 4. Audio Processing (`SermonAudioProcessingService.php:73-80`)

```php
Bus::chain($jobs)
    ->onQueue('sermon-processing')
    ->dispatch();
```

---

## Mail Queue Implementation

### Mailable Classes (`app/Mail/`)

All implement `Queueable` and `SerializesModels`:

| Class | Purpose |
|-------|---------|
| `DiskSpaceWarning` | Critical disk space alerts |
| `LivestreamProcessingCompleted` | Success notifications |
| `LivestreamProcessingFailed` | Failure notifications with stack traces |
| `ManualReviewRequired` | Segmentation review alerts |
| `PermissionError` | File permission issues |

### Mail Dispatch Pattern (`LivestreamErrorHandler.php`)

```php
// Line 187-188
Mail::to(config('media-processing.email.admin_email'))
    ->queue(new DiskSpaceWarning($processingId));

// Line 223-224
Mail::to(config('media-processing.email.admin_email'))
    ->queue(new LivestreamProcessingFailed($processingId, $exception, $step));
```

**Issue**: Failed mail sends are only logged (lines 189-193), not retried.

---

## What's Working Well

### Strengths

1. **Proper ShouldQueue Implementation**
   - All jobs use standard Laravel queue traits
   - Proper model serialization with `SerializesModels`

2. **Comprehensive Error Handling**
   - All jobs implement `failed()` callback
   - Processing logs updated on failure
   - Step-by-step tracking with logging methods

3. **Queue Segregation**
   - Separate queues prevent bottlenecks
   - Non-critical work (thumbnails) isolated

4. **Job Chaining**
   - Uses `Bus::chain()` for sequential execution
   - Proper error handling with `.catch()`

5. **Mail Queuing**
   - All mailables support queuing
   - Uses `Mail::queue()` for async delivery

---

## Issues & Gaps

### Critical

| Issue | Location | Impact |
|-------|----------|--------|
| `QUEUE_DRIVER=sync` | `.env:27` | All jobs block HTTP requests |

### High Priority

| Issue | Location | Recommendation |
|-------|----------|----------------|
| Missing `backoff()` | `CreateSermonRecord.php` | Add: `[30, 120, 300]` |
| No retry config | `ValidateAudioFile.php` | Add retry/backoff methods |
| No retry config | `ValidateVideoFile.php` | Add retry/backoff methods |
| No retry config | `ExtractAudioFromVideo.php` | Add retry/backoff methods |

### Medium Priority

| Issue | Location | Impact |
|-------|----------|--------|
| Very long timeouts (1hr) | `GenerateRmsLog`, `PerformVisualAnalysis` | May block queue workers |
| Silent mail failures | `LivestreamErrorHandler.php:189-193` | Failed emails not retried |
| No queue monitoring | - | Can't detect stuck jobs |

---

## Recommended Actions

### 1. Production Configuration (Critical)

Update `.env` for production:

```env
QUEUE_DRIVER=database
QUEUE_CONNECTION=database
```

Start queue worker:

```bash
php artisan queue:work --queue=livestream-processing,video-processing,sermon-processing,thumbnails,default
```

### 2. Add Missing Backoff to CreateSermonRecord

```php
// app/Jobs/CreateSermonRecord.php

public function backoff(): array
{
    return [30, 120, 300]; // 30s, 2m, 5m
}
```

### 3. Add Retry Config to Validation Jobs

```php
// app/Jobs/ValidateAudioFile.php and ValidateVideoFile.php

public int $tries = 3;
public int $timeout = 300;

public function backoff(): array
{
    return [30, 60, 120];
}

public function retryUntil(): \DateTime
{
    return now()->addMinutes(30);
}
```

### 4. Improve Mail Error Handling

```php
// app/Services/LivestreamErrorHandler.php

// Consider using retry logic
retry(3, function () use ($processingId) {
    Mail::to(config('media-processing.email.admin_email'))
        ->queue(new DiskSpaceWarning($processingId));
}, 1000);
```

### 5. Add Queue Monitoring (Future)

Consider implementing:
- Laravel Horizon for Redis-based queues
- Custom health check endpoint
- Failed job alerts
- Processing time metrics

---

## Queue Worker Commands

### Development

```bash
# Process jobs synchronously (current behavior with sync driver)
# No worker needed

# To test with database driver:
QUEUE_DRIVER=database php artisan queue:work
```

### Production

```bash
# Start worker with all queues
php artisan queue:work --queue=livestream-processing,video-processing,sermon-processing,thumbnails,default

# With memory limit and sleep time
php artisan queue:work --memory=512 --sleep=3 --tries=3

# Process specific queue only
php artisan queue:work --queue=thumbnails

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Supervisor Configuration (Production)

```ini
[program:crockenhill-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/worker.log
stopwaitsecs=3600
```

---

## Files Reference

### Configuration

- `config/queue.php` - Queue driver configuration
- `config/media-processing.php` - Media processing queue settings
- `config/thumbnail-generation.php:141-144` - Thumbnail queue config
- `.env` - Environment-specific settings

### Job Files

- `app/Jobs/` - All 17 job classes
- `app/Jobs/ProcessingJob.php` - Abstract base class

### Services (Job Dispatching)

- `app/Services/SermonJobPipelineService.php:37-53, 112-137`
- `app/Services/UnifiedMediaProcessor.php:204-211`
- `app/Services/LivestreamSegmentationService.php:180, 279-281`
- `app/Services/SermonAudioProcessingService.php:73-80, 321-336`

### Mail

- `app/Mail/` - All 5 mailable classes
- `app/Services/LivestreamErrorHandler.php:187-238` - Mail dispatch

---

## Laravel 12 Features to Implement

The project is running Laravel 12 (`"laravel/framework": "^12.0"`) but is not using several powerful queue features introduced in recent versions.

### High Priority

#### 1. WithoutOverlapping Middleware

Prevents concurrent processing of the same sermon - critical for media processing jobs.

```php
// app/Jobs/TranscribeAudio.php (and similar jobs)
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping($this->processingLog->processing_id))
            ->releaseAfter(60)      // Release lock after 60s if job fails
            ->expireAfter(1800)     // Lock expires after 30 minutes
    ];
}
```

**Apply to**: `TranscribeAudio`, `GenerateRmsLog`, `ExtractSermon`, `ProcessTranscriptWithAI`, `ExtractAudioFromVideo`

#### 2. ThrottlesExceptions Middleware

Essential for jobs calling external APIs (OpenAI). Throttles retries when an API is consistently failing.

```php
// app/Jobs/TranscribeAudio.php
use Illuminate\Queue\Middleware\ThrottlesExceptions;

public function middleware(): array
{
    return [
        (new ThrottlesExceptions(3, 5 * 60))  // 3 failures within 5 minutes triggers throttle
            ->backoff(5)                        // 5 minute backoff before retrying
            ->by('openai-api'),                 // Throttle key (shared across jobs)
    ];
}
```

**Apply to**: `TranscribeAudio`, `ProcessTranscriptWithAI` (both call OpenAI API)

#### 3. ShouldBeUnique Interface

Prevents duplicate jobs from being queued for the same processing task.

```php
// app/Jobs/TranscribeAudio.php
use Illuminate\Contracts\Queue\ShouldBeUnique;

class TranscribeAudio extends ProcessingJob implements ShouldQueue, ShouldBeUnique
{
    public $uniqueFor = 3600; // 1 hour uniqueness window

    public function uniqueId(): string
    {
        return $this->processingLog->processing_id;
    }
}
```

**Apply to**: All media processing jobs to prevent accidental re-queuing

### Medium Priority

#### 4. Background Queue Connection (Laravel 12.37+)

New driver that uses `Concurrently::defer()` for lightweight async processing without a queue worker.

```php
// config/queue.php - add this connection
'connections' => [
    // ... existing connections

    'background' => [
        'driver' => 'background',
    ],
],
```

Usage:
```php
// Ideal for lightweight, non-critical jobs
GenerateThumbnail::dispatch($sermon)->onConnection('background');
CleanupTemporaryFiles::dispatch($paths)->onConnection('background');
SendCompletionNotification::dispatch($log)->onConnection('background');
```

**Ideal for**: `GenerateThumbnail`, `CleanupTemporaryFiles`, `SendCompletionNotification`

#### 5. Job Batching with Progress Tracking

Enhanced alternative to `Bus::chain()` with progress monitoring and partial failure handling.

```php
// app/Services/SermonJobPipelineService.php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

$batch = Bus::batch([
    new ValidateVideoFile($processingLog),
    new ExtractAudioFromVideo($processingLog),
    new TranscribeAudio($processingLog),
    new ProcessTranscriptWithAI($processingLog),
])->then(function (Batch $batch) {
    // All jobs completed successfully
    Log::info('Batch completed', ['batch_id' => $batch->id]);
})->catch(function (Batch $batch, Throwable $e) {
    // First failure detected
    Log::error('Batch failed', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
})->finally(function (Batch $batch) {
    // Batch finished (success or failure)
})->allowFailures()  // Optional: continue even if some jobs fail
  ->name('sermon-processing-' . $processingLog->processing_id)
  ->onQueue('sermon-processing')
  ->dispatch();

// Store batch ID for progress tracking
$processingLog->update(['batch_id' => $batch->id]);

// Check progress from API endpoint
$batch = Bus::findBatch($batchId);
return [
    'progress' => $batch->progress(),      // 0-100
    'pending' => $batch->pendingJobs,
    'failed' => $batch->failedJobs,
    'finished' => $batch->finished(),
];
```

**Requires migration**:
```bash
php artisan make:queue-batches-table
php artisan migrate
```

#### 6. Rate Limiting Middleware

Control API call frequency to prevent rate limiting from external services.

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('openai', function (object $job) {
        return Limit::perMinute(10); // Max 10 transcriptions per minute
    });

    RateLimiter::for('ffmpeg', function (object $job) {
        return Limit::perMinute(2); // Max 2 concurrent video processes
    });
}
```

```php
// app/Jobs/TranscribeAudio.php
use Illuminate\Queue\Middleware\RateLimited;

public function middleware(): array
{
    return [
        new RateLimited('openai'),
    ];
}
```

### Lower Priority

#### 7. Queue Failover Configuration

Automatic failover between queue connections for production resilience.

```php
// config/queue.php
'connections' => [
    'failover' => [
        'driver' => 'failover',
        'connections' => ['redis', 'database', 'sync'],
    ],
],
```

#### 8. ShouldBeEncrypted Interface

Encrypt job payloads at rest (if handling sensitive sermon metadata).

```php
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class UpdateSermonRecord implements ShouldQueue, ShouldBeEncrypted
{
    // Job payload will be automatically encrypted
}
```

#### 9. Skip Middleware

Conditionally skip job execution based on runtime conditions.

```php
use Illuminate\Queue\Middleware\Skip;

public function middleware(): array
{
    return [
        Skip::when(function (): bool {
            // Skip if sermon was deleted while job was queued
            return !Sermon::where('id', $this->sermonId)->exists();
        }),
    ];
}
```

### Combined Example: Full Job Implementation

Here's how `TranscribeAudio` would look with all recommended Laravel 12 features:

```php
<?php

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Models\MediaProcessingLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class TranscribeAudio extends ProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;
    public $uniqueFor = 3600; // Prevent duplicate jobs for 1 hour

    public function __construct(
        public MediaProcessingLog $processingLog
    ) {}

    /**
     * Unique identifier for ShouldBeUnique
     */
    public function uniqueId(): string
    {
        return $this->processingLog->processing_id;
    }

    /**
     * Laravel 12 job middleware
     */
    public function middleware(): array
    {
        return [
            // Prevent concurrent processing of same sermon
            (new WithoutOverlapping($this->processingLog->processing_id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout),

            // Throttle retries when OpenAI API is failing
            (new ThrottlesExceptions(3, 5 * 60))
                ->backoff(5)
                ->by('openai-api'),

            // Rate limit API calls
            new RateLimited('openai'),
        ];
    }

    public function handle(TranscriptionServiceInterface $transcriptionService): void
    {
        // ... existing implementation
    }

    public function failed(\Throwable $exception): void
    {
        // ... existing implementation
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
```

### Implementation Priority Matrix

| Feature | Priority | Impact | Effort | Apply To |
|---------|----------|--------|--------|----------|
| `WithoutOverlapping` | High | Prevents duplicate processing | Low | All media jobs |
| `ThrottlesExceptions` | High | Graceful API failure handling | Low | API-calling jobs |
| `ShouldBeUnique` | High | Prevents queue spam | Low | All jobs |
| Background connection | Medium | Lighter jobs without worker | Low | Thumbnails, cleanup, notifications |
| Job batching | Medium | Progress tracking, better monitoring | Medium | Processing pipelines |
| Rate limiting | Medium | API cost control | Low | `TranscribeAudio`, `ProcessTranscriptWithAI` |
| Queue failover | Lower | Production resilience | Low | Production config |
| `ShouldBeEncrypted` | Lower | Security enhancement | Low | If needed |
| `Skip` middleware | Lower | Graceful handling of stale jobs | Low | All jobs |

### Migration Checklist

- [ ] Add `middleware()` method to all jobs
- [ ] Implement `ShouldBeUnique` on processing jobs
- [ ] Configure rate limiters in `AppServiceProvider`
- [ ] Add `background` queue connection to `config/queue.php`
- [ ] Run `php artisan make:queue-batches-table` if using batching
- [ ] Update job dispatching to use batches where appropriate
- [ ] Test all middleware combinations in development

---

## Future Considerations

### Potential Queue Candidates

Operations that could benefit from queuing if they become slow:

1. **Podcast feed generation** - After sermon creation
2. **Image optimization** - `PageImageService` currently synchronous
3. **Bulk admin operations** - Using `Bus::batch()`
4. **Cache warming** - Pre-compute expensive queries
5. **Report generation** - Admin analytics

### Architecture Improvements

1. **Redis driver** - Better performance than database
2. **Laravel Horizon** - Dashboard and monitoring for Redis queues
3. **Job batching** - `Bus::batch()` for related jobs with progress tracking
4. **Rate limiting** - Prevent overwhelming external APIs (OpenAI, FFmpeg)

---

## Conclusion

The queue infrastructure is well-designed with proper error handling, job chaining, and queue segregation. The primary issue is the `sync` driver configuration that negates all async benefits.

**Immediate action required**: Change `QUEUE_DRIVER` to `database` for production and ensure queue workers are running.
