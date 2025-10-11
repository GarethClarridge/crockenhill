# Media Processing - Aggressive Refactor Plan
**Date:** October 2, 2025
**Status:** Ready for Implementation
**Approach:** Aggressive - No legacy support needed

## Context

This is a **feature in development** with:
- ✅ Working audio/video/livestream upload functionality
- ✅ Only legacy sermon data in production (created via old route)
- ❌ No API consumers to maintain compatibility with
- ❌ No production processing logs to migrate

**Strategy:** Delete old code, build clean unified architecture, maintain functionality.

---

## The Problems (Prioritized by Impact)

### 1. TWO Processing Log Models 🔴 CRITICAL
- `SermonProcessingLog` and `LivestreamProcessingLog` doing identical work
- Different status types, field names, timestamps
- Forces all downstream code to handle both types

### 2. FOUR Duplicated Jobs 🔴 CRITICAL
- `TranscribeAudio` vs `TranscribeSermonAudioFromLivestream` (~90% same code)
- `ProcessTranscriptWithAI` vs `AnalyzeSermonTranscriptFromLivestream` (~95% same code)
- ~800 lines of duplicated code that must be maintained in parallel

### 3. File Storage Chaos 🟡 HIGH
- 11 different file path fields across models
- Inconsistent naming: `filename`, `stored_file_path`, `audio_file_path`, `sermon_audio_path`
- Sermon model has legacy `filename` field (should be `audio_file_path`)

### 4. Status Result Fragmentation 🟡 HIGH
- Three different status types requiring conversion methods
- UnifiedMediaProcessor has to convert between types

### 5. Pipeline Builder Incomplete 🟢 MEDIUM
- Audio/video use builder, livestream hardcodes pipeline in service
- Should all use centralized builder

---

## The Solution: Clean Slate Refactor

### Step 1: Delete Old Models, Create Unified One ⚡

**Delete:**
- `app/Models/SermonProcessingLog.php`
- `app/Models/LivestreamProcessingLog.php`
- `database/migrations/*_create_sermon_processing_logs_table.php`
- `database/migrations/*_create_livestream_processing_logs_table.php`

**Create:**
```php
// app/Models/MediaProcessingLog.php
class MediaProcessingLog extends Model
{
    protected $fillable = [
        'processing_id',
        'processing_type',        // 'audio', 'video', 'livestream'
        'status',                 // ProcessingStatus enum
        'current_step',
        'error_message',

        // File info
        'original_filename',
        'file_size',
        'duration',

        // File paths (all nullable, populated based on type)
        'source_file_path',       // Original upload (temp)
        'audio_file_path',        // Processed audio
        'video_file_path',        // Processed video (video/livestream only)
        'transcript_file_path',   // Generated transcript

        // Livestream-specific (nullable)
        'rms_log_path',
        'sermon_start_time',
        'sermon_end_time',

        // Processing results
        'ai_analysis',            // JSON
        'processing_metadata',    // JSON - type-specific extras

        // Relationships
        'sermon_id',

        // Timestamps
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => ProcessingStatus::class,
        'ai_analysis' => 'array',
        'processing_metadata' => 'array',
        'duration' => 'float',
        'sermon_start_time' => 'float',
        'sermon_end_time' => 'float',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    public function segments(): HasMany  // Only used for livestream type
    {
        return $this->hasMany(LivestreamSegment::class, 'media_processing_log_id');
    }

    // Scopes
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('processing_type', $type);
    }

    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('processing_type', 'audio');
    }

    public function scopeVideo(Builder $query): Builder
    {
        return $query->where('processing_type', 'video');
    }

    public function scopeLivestream(Builder $query): Builder
    {
        return $query->where('processing_type', 'livestream');
    }

    // Status helpers
    public function isComplete(): bool
    {
        return $this->status === ProcessingStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::FAILED;
    }

    public function isProcessing(): bool
    {
        return $this->status === ProcessingStatus::PROCESSING;
    }

    public function isPending(): bool
    {
        return $this->status === ProcessingStatus::PENDING;
    }

    public function markAsProcessing(?string $step = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => $step,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => ProcessingStatus::COMPLETED,
            'current_step' => 'completed',
            'completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage, ?string $step = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::FAILED,
            'current_step' => $step ?? $this->current_step,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function updateStep(string $step): bool
    {
        return $this->update(['current_step' => $step]);
    }
}
```

**Migration:**
```php
// database/migrations/YYYY_MM_DD_create_media_processing_logs_table.php
Schema::create('media_processing_logs', function (Blueprint $table) {
    $table->id();
    $table->uuid('processing_id')->unique();
    $table->enum('processing_type', ['audio', 'video', 'livestream']);
    $table->string('status'); // ProcessingStatus enum
    $table->string('current_step')->nullable();
    $table->text('error_message')->nullable();

    // File info
    $table->string('original_filename');
    $table->bigInteger('file_size')->nullable();
    $table->float('duration')->nullable();

    // File paths
    $table->string('source_file_path')->nullable();
    $table->string('audio_file_path')->nullable();
    $table->string('video_file_path')->nullable();
    $table->string('transcript_file_path')->nullable();

    // Livestream-specific
    $table->string('rms_log_path')->nullable();
    $table->float('sermon_start_time')->nullable();
    $table->float('sermon_end_time')->nullable();

    // Processing results
    $table->json('ai_analysis')->nullable();
    $table->json('processing_metadata')->nullable();

    // Relationships
    $table->foreignId('sermon_id')->nullable()->constrained()->onDelete('cascade');

    // Timestamps
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    // Indexes
    $table->index('processing_type');
    $table->index('status');
    $table->index(['processing_type', 'status']);
});
```

---

### Step 2: Delete Duplicate Jobs, Create Unified Ones ⚡

**Delete:**
- `app/Jobs/TranscribeSermonAudioFromLivestream.php`
- `app/Jobs/AnalyzeSermonTranscriptFromLivestream.php`

**Update TranscribeAudio:**
```php
// app/Jobs/TranscribeAudio.php
class TranscribeAudio extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(
        public MediaProcessingLog $processingLog
    ) {}

    public function handle(TranscriptionServiceInterface $transcriptionService): void
    {
        try {
            $this->initializeStepLogging($this->processingLog->processing_id);

            if ($this->isCancelled()) {
                return;
            }

            $this->logStepStart('transcribing', 'Starting audio transcription');
            $this->processingLog->updateStep('transcribing_audio');

            // Resolve audio path based on processing type
            $audioFilePath = $this->resolveAudioPath();

            Log::info('Transcribing audio file', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'audio_file' => $audioFilePath,
            ]);

            // Transcribe
            $transcript = $transcriptionService->transcribe($audioFilePath);

            if (empty($transcript)) {
                throw new \Exception('Transcription returned empty content');
            }

            // Store transcript
            if (!$this->processingLog->sermon_id) {
                throw new \Exception("No sermon ID found in processing log: {$this->processingLog->processing_id}");
            }

            $transcriptPath = $transcriptionService->storeTranscript(
                $this->processingLog->sermon_id,
                $transcript
            );

            // Update processing log
            $this->processingLog->update(['transcript_file_path' => $transcriptPath]);

            // Update sermon
            $this->processingLog->sermon->update(['transcript_file_path' => $transcriptPath]);

            $this->processingLog->updateStep('transcription_completed');
            $this->logStepComplete('transcribing', 'Audio transcription completed successfully');

            Log::info('Audio transcription completed', [
                'processing_id' => $this->processingLog->processing_id,
                'transcript_path' => $transcriptPath,
                'word_count' => str_word_count($transcript),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to transcribe audio', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            if ($this->processingLog->sermon_id) {
                $transcriptionService->cleanupOnFailure($this->processingLog->sermon_id);
            }

            $this->processingLog->markAsFailed($e->getMessage(), 'transcribing_audio');
            $this->logStepFailed('transcribing', $e->getMessage());

            throw $e;
        }
    }

    private function resolveAudioPath(): string
    {
        return match($this->processingLog->processing_type) {
            'audio' => $this->processingLog->source_file_path,
            'video', 'livestream' => $this->processingLog->audio_file_path,
        };
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
```

**Update ProcessTranscriptWithAI:**
```php
// app/Jobs/ProcessTranscriptWithAI.php
class ProcessTranscriptWithAI extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(SermonAnalysisService $analysisService): void
    {
        try {
            $this->initializeStepLogging($this->processingLog->processing_id);

            if ($this->isCancelled()) {
                return;
            }

            $this->logStepStart('analyzing', 'Starting AI analysis');
            $this->processingLog->updateStep('analyzing_transcript');

            // Get transcript
            $transcriptPath = $this->processingLog->transcript_file_path;
            if (empty($transcriptPath)) {
                throw new \Exception("No transcript path available");
            }

            $transcript = Storage::get($transcriptPath);
            if (empty($transcript)) {
                throw new \Exception("Transcript file is empty or unreadable");
            }

            Log::info('Processing transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'word_count' => str_word_count($transcript),
            ]);

            // Analyze
            $existingSeries = $this->getExistingSeries();
            $analysis = $analysisService->analyzeSermon($transcript, $existingSeries);

            if (!$analysis->hasValidTranscript()) {
                throw new \Exception('AI analysis produced invalid results');
            }

            // Store in processing log
            $this->processingLog->update(['ai_analysis' => $analysis->toArray()]);

            // Update sermon
            $this->processingLog->sermon->update([
                'title' => $analysis->title,
                'summary' => $analysis->summary,
                'points' => $analysis->points,
                'reference' => $analysis->reference,
                'series' => $analysis->series,
            ]);

            $this->processingLog->updateStep('ai_analysis_completed');
            $this->logStepComplete('analyzing', 'AI analysis completed successfully');

            Log::info('AI analysis completed', [
                'processing_id' => $this->processingLog->processing_id,
                'title' => $analysis->title,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Try fallback
            $fallbackAnalysis = $this->createFallbackAnalysis();
            if ($fallbackAnalysis) {
                $this->processingLog->update(['ai_analysis' => $fallbackAnalysis->toArray()]);
                $this->processingLog->sermon->update([
                    'title' => $fallbackAnalysis->title,
                    'summary' => $fallbackAnalysis->summary,
                    'points' => $fallbackAnalysis->points,
                    'reference' => $fallbackAnalysis->reference,
                    'series' => $fallbackAnalysis->series,
                ]);
                $this->processingLog->updateStep('ai_analysis_fallback');
            } else {
                $this->processingLog->markAsFailed($e->getMessage(), 'analyzing_transcript');
                $this->logStepFailed('analyzing', $e->getMessage());
                throw $e;
            }
        }
    }

    private function getExistingSeries(): array
    {
        return Sermon::whereNotNull('series')
            ->where('series', '!=', '')
            ->distinct()
            ->pluck('series')
            ->filter()
            ->values()
            ->toArray();
    }

    private function createFallbackAnalysis(): ?SermonAnalysis
    {
        try {
            $fallbackTitle = $this->generateFallbackTitle();
            $transcript = Storage::get($this->processingLog->transcript_file_path);

            return SermonAnalysis::create(
                title: $fallbackTitle,
                series: null,
                reference: null,
                points: ['Main Message'],
                summary: null,
                transcript: $transcript
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    private function generateFallbackTitle(): string
    {
        $filename = pathinfo($this->processingLog->original_filename, PATHINFO_FILENAME);
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/[-_]+/', ' ', $title);
        $title = trim($title);

        if (empty($title) || strlen($title) < 3) {
            return 'Sermon - ' . $this->processingLog->created_at->format('F j, Y');
        }

        return Str::title($title);
    }

    public function backoff(): array
    {
        return [120, 300, 600];
    }
}
```

---

### Step 3: Standardize Sermon File Fields ⚡

**Migration:**
```php
// database/migrations/YYYY_MM_DD_standardize_sermon_file_paths.php
Schema::table('sermons', function (Blueprint $table) {
    // Rename for consistency
    $table->renameColumn('filename', 'audio_file_path');
    $table->renameColumn('transcript_path', 'transcript_file_path');
    $table->renameColumn('thumbnail_path', 'thumbnail_file_path');

    // video_file_path already has correct name
});
```

**Update Sermon Model:**
```php
// app/Models/Sermon.php
protected $fillable = [
    // ... existing fields ...
    'audio_file_path',         // Renamed from 'filename'
    'video_file_path',
    'transcript_file_path',    // Renamed from 'transcript_path'
    'thumbnail_file_path',     // Renamed from 'thumbnail_path'
    // ... rest ...
];
```

---

### Step 4: Simplify All Jobs to Use MediaProcessingLog ⚡

**Update these jobs:**
- `CreateSermonRecord` - remove livestream detection logic
- `GenerateThumbnail` - simplify constructor
- `ExtractAudioFromVideo` - use unified log
- `ValidateAudioFile` - use unified log
- `ValidateVideoFile` - use unified log

**Example - CreateSermonRecord:**
```php
class CreateSermonRecord extends ProcessingJob implements ShouldQueue
{
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(SermonProcessingLogger $logger): void
    {
        // Much simpler - just check processing_type field
        $isVideoUpload = $this->processingLog->processing_type === 'video';
        $isLivestream = $this->processingLog->processing_type === 'livestream';

        // Rest of logic stays the same but cleaner
    }
}
```

**Example - GenerateThumbnail:**
```php
class GenerateThumbnail implements ShouldQueue
{
    // SIMPLIFIED: Only one constructor
    public function __construct(
        public MediaProcessingLog $processingLog
    ) {}

    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        // Resolve video path based on type
        $videoPath = match($this->processingLog->processing_type) {
            'video' => $this->processingLog->video_file_path,
            'livestream' => $this->processingLog->video_file_path,
            default => null,
        };

        if (!$videoPath) {
            Log::info('No video available for thumbnail generation', [
                'processing_type' => $this->processingLog->processing_type,
            ]);
            return;
        }

        // Generate thumbnail
        // ...
    }
}
```

---

### Step 5: Complete ProcessingPipelineBuilder ⚡

**Update ProcessingPipelineBuilder:**
```php
// app/Services/ProcessingPipelineBuilder.php
class ProcessingPipelineBuilder
{
    public function buildPipeline(MediaProcessingLog $log): array
    {
        return match($log->processing_type) {
            'audio' => $this->buildAudioPipeline($log),
            'video' => $this->buildVideoPipeline($log),
            'livestream' => $this->buildLivestreamPipeline($log),
        };
    }

    private function buildAudioPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new CreateSermonRecord($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new SendCompletionNotification($log),
        ];
    }

    private function buildVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new CreateSermonRecord($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
        ];
    }

    private function buildLivestreamPipeline(MediaProcessingLog $log): array
    {
        return [
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new ExtractSermon($log),
            new CreateSermonRecord($log),        // UNIFIED
            new TranscribeAudio($log),           // UNIFIED
            new ProcessTranscriptWithAI($log),   // UNIFIED
            new GenerateThumbnail($log),
            new CleanupTemporaryFiles($log),
        ];
    }
}
```

---

### Step 6: Delete Status Conversion, Use Single DTO ⚡

**Delete:**
- `app/Data/ProcessingStatusResult.php`
- `app/Data/LivestreamProcessingStatus.php`
- All conversion methods in `UnifiedMediaProcessor`

**Keep/Update:**
```php
// app/Data/StandardProcessingResponse.php - This becomes THE status DTO
class StandardProcessingResponse
{
    public static function fromProcessingLog(MediaProcessingLog $log): self
    {
        $metadata = match($log->processing_type) {
            'livestream' => [
                'segments_count' => $log->segments->count(),
                'sermon_duration' => $log->sermon_end_time - $log->sermon_start_time,
            ],
            'video' => [
                'has_thumbnail' => $log->sermon?->hasThumbnail(),
                'video_duration' => $log->duration,
            ],
            'audio' => [
                'audio_duration' => $log->duration,
            ],
        };

        return new self(
            processingId: $log->processing_id,
            status: $log->status->value,
            currentStep: $log->current_step,
            progressPercentage: self::calculateProgress($log),
            errorMessage: $log->error_message,
            sermonId: $log->sermon_id,
            sermonUrl: $log->sermon ? "/christ/sermons/{$log->sermon->slug}" : null,
            startedAt: $log->started_at,
            updatedAt: $log->updated_at,
            additionalData: $metadata,
        );
    }
}
```

**Update UnifiedMediaProcessor:**
```php
// app/Services/UnifiedMediaProcessor.php
class UnifiedMediaProcessor
{
    public function getStatus(string $processingId): StandardProcessingResponse
    {
        $log = MediaProcessingLog::where('processing_id', $processingId)->first();

        if (!$log) {
            return StandardProcessingResponse::notFound();
        }

        return StandardProcessingResponse::fromProcessingLog($log);
    }

    // NO CONVERSION METHODS NEEDED! 🎉
}
```

---

### Step 7: Update Services to Create MediaProcessingLog ⚡

**Update SermonAudioProcessingService:**
```php
$processingLog = MediaProcessingLog::create([
    'processing_id' => $processingId,
    'processing_type' => 'audio',  // NEW
    'original_filename' => $file->getClientOriginalName(),
    'source_file_path' => $storedFilePath,
    'status' => ProcessingStatus::PENDING,
    'current_step' => 'audio_processing_initiated',
]);
```

**Update UnifiedMediaProcessor->processDirectVideo:**
```php
$processingLog = MediaProcessingLog::create([
    'processing_id' => $processingId,
    'processing_type' => 'video',  // NEW
    'original_filename' => $file->getClientOriginalName(),
    'source_file_path' => $tempPath,
    'status' => ProcessingStatus::PENDING,
    'current_step' => 'video_processing_initiated',
]);
```

**Update LivestreamSegmentationService:**
```php
$processingLog = MediaProcessingLog::create([
    'processing_id' => $processingId,
    'processing_type' => 'livestream',  // NEW
    'status' => ProcessingStatus::PENDING,
    'original_filename' => $uploadResult['original_filename'],
    'source_file_path' => $uploadResult['temp_path'],
    'file_size' => $uploadResult['file_size'],
    'duration' => $metadata['duration'],
    'processing_metadata' => [
        'upload_time' => now()->toISOString(),
        'format_details' => $metadata,
    ],
]);
```

---

### Step 8: Update LivestreamSegment Relationship ⚡

**Update LivestreamSegment model:**
```php
// app/Models/LivestreamSegment.php
class LivestreamSegment extends Model
{
    public function processingLog(): BelongsTo
    {
        // Changed from livestream_processing_log_id to media_processing_log_id
        return $this->belongsTo(MediaProcessingLog::class, 'media_processing_log_id');
    }
}
```

**Migration:**
```php
Schema::table('livestream_segments', function (Blueprint $table) {
    $table->renameColumn('processing_log_id', 'media_processing_log_id');
});
```

---

## Implementation Order (Aggressive)

### Day 1: Foundation
1. ✅ Create `MediaProcessingLog` model + migration
2. ✅ Run migration (creates new table, old ones still exist)
3. ✅ Update `LivestreamSegment` relationship + migration
4. ✅ Update all services to create `MediaProcessingLog` instead of old models
5. ✅ Test that all three upload types create correct `MediaProcessingLog`

### Day 2: Job Consolidation
1. ✅ Update `TranscribeAudio` to work with `MediaProcessingLog`
2. ✅ Delete `TranscribeSermonAudioFromLivestream`
3. ✅ Update `ProcessTranscriptWithAI` to work with `MediaProcessingLog`
4. ✅ Delete `AnalyzeSermonTranscriptFromLivestream`
5. ✅ Update all other jobs (CreateSermonRecord, GenerateThumbnail, etc.)
6. ✅ Test each pipeline end-to-end

### Day 3: Pipeline & Status Cleanup
1. ✅ Complete `ProcessingPipelineBuilder` for all types
2. ✅ Remove hardcoded pipeline from `LivestreamSegmentationService`
3. ✅ Delete `ProcessingStatusResult` and `LivestreamProcessingStatus`
4. ✅ Update `StandardProcessingResponse` to work from `MediaProcessingLog`
5. ✅ Remove conversion methods from `UnifiedMediaProcessor`
6. ✅ Test status endpoints

### Day 4: File Field Cleanup
1. ✅ Create migration to rename Sermon fields
2. ✅ Update Sermon model
3. ✅ Update all references to old field names
4. ✅ Test file storage/retrieval

### Day 5: Delete Old Code & Test
1. ✅ Delete `SermonProcessingLog` model
2. ✅ Delete `LivestreamProcessingLog` model
3. ✅ Drop old tables in migration
4. ✅ Delete old migrations
5. ✅ Search codebase for any remaining references
6. ✅ Run full test suite
7. ✅ Manual testing of all three upload types

**Total Time: 5 days**

---

## File Deletions Checklist

### Models
- [ ] `app/Models/SermonProcessingLog.php`
- [ ] `app/Models/LivestreamProcessingLog.php`

### Jobs
- [ ] `app/Jobs/TranscribeSermonAudioFromLivestream.php`
- [ ] `app/Jobs/AnalyzeSermonTranscriptFromLivestream.php`

### Data/DTOs
- [ ] `app/Data/ProcessingStatusResult.php`
- [ ] `app/Data/LivestreamProcessingStatus.php`
- [ ] `app/Data/LivestreamProcessingResult.php` (if not used elsewhere)

### Migrations (after new ones are stable)
- [ ] `*_create_sermon_processing_logs_table.php`
- [ ] `*_create_livestream_processing_logs_table.php`

### Tests (update, don't delete)
- [ ] Update all tests to use `MediaProcessingLog`
- [ ] Update factory files

---

## Testing Strategy

### Unit Tests
- [ ] `MediaProcessingLog` model methods
- [ ] `TranscribeAudio` with all processing types
- [ ] `ProcessTranscriptWithAI` with all processing types
- [ ] `ProcessingPipelineBuilder` for all types
- [ ] `StandardProcessingResponse::fromProcessingLog()`

### Feature Tests
- [ ] Audio upload end-to-end
- [ ] Video upload end-to-end
- [ ] Livestream upload end-to-end
- [ ] Status checking for each type
- [ ] Cancellation for each type
- [ ] Retry for each type
- [ ] Error handling for each type

### Integration Tests
- [ ] Complete pipeline for each type
- [ ] File storage verification
- [ ] Sermon record creation
- [ ] Transcript generation
- [ ] AI analysis
- [ ] Thumbnail generation (video/livestream)

---

## Success Criteria

- [ ] Audio upload works identically to before
- [ ] Video upload works identically to before
- [ ] Livestream upload works identically to before
- [ ] All tests passing
- [ ] Zero references to old model names in codebase
- [ ] Old tables dropped
- [ ] Code reduced by ~1000+ lines
- [ ] Single source of truth for processing logs
- [ ] Consistent file path naming across all models

---

## Rollback Plan

If critical issues are discovered:

1. Revert migrations (restore old tables)
2. Restore old model files from git
3. Restore old job files from git
4. Revert service changes
5. All within ~15 minutes

**Mitigation:** Test thoroughly in development before production deployment. Since this is still in development, rollback should never be needed.

---

## Summary

**What We're Doing:**
- DELETE 2 models → CREATE 1 unified model ✅
- DELETE 2 duplicate jobs → UPDATE 2 jobs to handle all types ✅
- DELETE 2 status types + conversion methods → USE 1 status type ✅
- RENAME 3 Sermon fields for consistency ✅
- COMPLETE pipeline builder for all types ✅

**Result:**
- ~1000 lines of code removed
- Single source of truth
- Consistent patterns everywhere
- Same functionality, better architecture
- 5 days to complete

**Risk:** Low - feature is in development, aggressive changes are safe
