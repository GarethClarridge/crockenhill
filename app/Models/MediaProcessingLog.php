<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ProcessingManualReviewMetadata;
use App\Data\ProcessingMetadata;
use App\Data\ProcessingMetadataCast;
use App\Data\SermonAnalysis;
use App\Data\SermonAnalysisCast;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use Database\Factories\MediaProcessingLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $processing_id
 * @property MediaType $processing_type
 * @property ProcessingStatus $status
 * @property string|null $current_step
 * @property string|null $error_message
 * @property string $original_filename
 * @property string|null $file_hash
 * @property string|null $dedup_key
 * @property int|null $file_size
 * @property float|null $duration
 * @property Carbon|null $extracted_date
 * @property SermonService|null $extracted_service
 * @property string|null $source_file_path
 * @property string|null $stored_file_path
 * @property string|null $audio_file_path
 * @property string|null $enhanced_audio_file_path
 * @property string|null $video_file_path
 * @property string|null $transcript_file_path
 * @property string|null $rms_log_path
 * @property float|null $sermon_start_time
 * @property float|null $sermon_end_time
 * @property SermonAnalysis|null $ai_analysis
 * @property ProcessingMetadata|null $processing_metadata
 * @property string|null $threshold_method
 * @property float|null $adaptive_threshold
 * @property array<string, mixed>|null $rms_stats
 * @property array<int, array<string, mixed>>|null $visual_samples
 * @property float|null $visual_processing_time
 * @property int|null $sermon_id
 * @property int|null $owner_user_id
 * @property int|null $church_service_id
 * @property string|null $queue_name
 * @property string|null $job_id
 * @property int|null $attempt_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property bool $is_degraded_completion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChurchService|null $churchService
 * @property-read User|null $owner
 * @property-read Collection<int, SermonProcessingStep> $processingSteps
 * @property-read Sermon|null $sermon
 * @property-read Collection<int, LivestreamSegment> $segments
 * @property-read Collection<int, ServiceSection> $serviceSections
 */
class MediaProcessingLog extends Model
{
    /** @use HasFactory<MediaProcessingLogFactory> */
    use HasFactory;

    public const VIDEO_PROCESSING_MODE_FULL_VIDEO = 'full_video';

    public const VIDEO_PROCESSING_MODE_AUTO_TRIM = 'auto_trim';

    protected $fillable = [
        'processing_id',
        'processing_type',
        'status',
        'current_step',
        'error_message',

        // File info
        'original_filename',
        'file_hash',
        'dedup_key',
        'file_size',
        'duration',
        'extracted_date',
        'extracted_service',

        // File paths
        'source_file_path',
        'stored_file_path', // Alias for source_file_path (backward compatibility)
        'audio_file_path',
        'enhanced_audio_file_path',
        'video_file_path',
        'transcript_file_path',

        // Livestream-specific
        'rms_log_path',
        'sermon_start_time',
        'sermon_end_time',

        // Processing results
        'ai_analysis',
        'processing_metadata',

        // Adaptive threshold fields (for livestream processing)
        'threshold_method',
        'adaptive_threshold',
        'rms_stats',

        // Relationships
        'sermon_id',
        'owner_user_id',
        'church_service_id',

        // Queue correlation
        'queue_name',
        'job_id',
        'attempt_count',

        // Timestamps
        'started_at',
        'completed_at',
        'is_degraded_completion',

        // Supersession (a later/better run for the same service won)
        'superseded_at',
        'superseded_by_processing_log_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'processing_type' => MediaType::class,
            'status' => ProcessingStatus::class,
            'ai_analysis' => SermonAnalysisCast::class,
            'processing_metadata' => ProcessingMetadataCast::class,
            'rms_stats' => 'array',
            'duration' => 'float',
            'extracted_date' => 'date',
            'extracted_service' => SermonService::class,
            'sermon_start_time' => 'float',
            'sermon_end_time' => 'float',
            'adaptive_threshold' => 'float',
            'file_size' => 'integer',
            'sermon_id' => 'integer',
            'owner_user_id' => 'integer',
            'church_service_id' => 'integer',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'is_degraded_completion' => 'boolean',
        ];
    }

    // Relationships

    /**
     * @return BelongsTo<Sermon, $this>
     */
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<ChurchService, $this>
     */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /**
     * @return HasMany<LivestreamSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(LivestreamSegment::class, 'media_processing_log_id');
    }

    /**
     * @return HasMany<ServiceSection, $this>
     */
    public function serviceSections(): HasMany
    {
        return $this->hasMany(ServiceSection::class, 'media_processing_log_id');
    }

    /**
     * @return HasMany<SermonProcessingStep, $this>
     */
    public function processingSteps(): HasMany
    {
        return $this->hasMany(SermonProcessingStep::class, 'processing_id', 'processing_id');
    }

    // Scopes

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeByType(Builder $query, MediaType $type): Builder
    {
        return $query->where('processing_type', $type->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Audio->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeVideo(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Video->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeLivestream(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Livestream->value);
    }

    /**
     * Runs that produce service sections: livestreams and auto-trim video
     * runs — the SQL mirror of usesSegmentationPipeline().
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    /**
     * Runs left in place but no longer the authoritative processing of their
     * service — a later/better run for the same service won. Their sections are
     * kept for audit but excluded from every review and timeline surface.
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeNotSuperseded(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_at');
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeSegmentationPipeline(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('processing_type', MediaType::Livestream->value)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('processing_type', MediaType::Video->value)
                        ->where(function (Builder $query): void {
                            $query
                                ->where('processing_metadata->video_processing_mode', self::VIDEO_PROCESSING_MODE_AUTO_TRIM)
                                ->orWhere('processing_metadata->trim_requested', true);
                        });
                });
        });
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Processing->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Pending->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Completed->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Failed->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeAwaitingManualSermonReview(Builder $query): Builder
    {
        return $query
            ->whereNull('superseded_at')
            ->where('status', ProcessingStatus::Failed->value)
            ->where('current_step', 'manual_review_required')
            ->where(function (Builder $query): void {
                $query->whereNotNull('processing_metadata->manual_review->reason_code');

                foreach (self::legacyManualReviewReasonPatterns() as $pattern) {
                    $query->orWhere(function (Builder $query) use ($pattern): void {
                        // Legacy fallback rows only ever existed for livestream runs.
                        $query
                            ->where('processing_type', MediaType::Livestream->value)
                            ->where('error_message', 'like', '%'.$pattern.'%');
                    });
                }
            });
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canAccessAdmin()) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
    }

    // Status helpers

    public function isComplete(): bool
    {
        return $this->status === ProcessingStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::Failed;
    }

    public function isProcessing(): bool
    {
        return $this->status === ProcessingStatus::Processing;
    }

    public function isPending(): bool
    {
        return $this->status === ProcessingStatus::Pending;
    }

    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::Cancelled;
    }

    public function isDegradedCompletion(): bool
    {
        return $this->is_degraded_completion;
    }

    /**
     * @return array<string, mixed>
     */
    public function manualReviewMetadata(): array
    {
        $manualReview = $this->processing_metadata?->manualReview;

        if ($manualReview instanceof ProcessingManualReviewMetadata) {
            return $manualReview->toArray();
        }

        return $this->legacyManualReviewMetadata();
    }

    public function manuallyConfirmedSegmentId(): ?int
    {
        return $this->processing_metadata?->manualReview?->confirmedSegmentId;
    }

    /**
     * Duration of the extracted sermon media, in seconds.
     *
     * A concat plan joins several source spans across a gap, so the media that
     * was actually cut is the sum of those spans — not sermon_end_time minus
     * sermon_start_time, which spans the gap too. The run bounds stay the true
     * source window (so segment_end_time remains a real livestream timestamp),
     * and callers read the honest duration here. Returns null when no
     * extraction plan has been recorded, leaving callers to fall back to the
     * continuous span.
     */
    public function extractedSermonMediaDuration(): ?float
    {
        return $this->recordedSermonExtraction()['duration'] ?? null;
    }

    /**
     * The recorded sermon extraction plan's overall source span and true media
     * duration. `start`/`end` are the first span's start and the last span's
     * end (the true source window); `duration` sums the spans, excluding any
     * concat gap between them. Callers compare start/end against a Sermon's
     * current bounds to detect a later manual edit that invalidates duration.
     *
     * @return array{start: float, end: float, duration: float}|null
     */
    public function recordedSermonExtraction(): ?array
    {
        $segments = data_get($this->processing_metadata?->toArray(), 'sermon_extraction_plan.segments');

        if (! is_array($segments) || $segments === []) {
            return null;
        }

        $segments = array_values($segments);
        $duration = 0.0;
        foreach ($segments as $segment) {
            $duration += max(0.0, (float) ($segment['end_time'] ?? 0.0) - (float) ($segment['start_time'] ?? 0.0));
        }

        if ($duration <= 0.0) {
            return null;
        }

        return [
            'start' => (float) ($segments[0]['start_time'] ?? 0.0),
            'end' => (float) ($segments[count($segments) - 1]['end_time'] ?? 0.0),
            'duration' => $duration,
        ];
    }

    public function requiresManualSermonReview(): bool
    {
        $manualReviewStatus = $this->processing_metadata?->manualReview?->status;

        if ($manualReviewStatus === 'required') {
            return $this->canUseManualSermonReview()
                && $this->status === ProcessingStatus::Failed
                && $this->current_step === 'manual_review_required';
        }

        if ($manualReviewStatus === 'confirmed') {
            return false;
        }

        return $this->legacyManualReviewReasonCode() !== null
            && $this->status === ProcessingStatus::Failed
            && $this->current_step === 'manual_review_required'
            && $this->processing_type === MediaType::Livestream;
    }

    public function videoProcessingMode(): string
    {
        if ($this->processing_type !== MediaType::Video) {
            return self::VIDEO_PROCESSING_MODE_FULL_VIDEO;
        }

        $mode = $this->processing_metadata?->videoProcessingMode;

        if ($mode === self::VIDEO_PROCESSING_MODE_AUTO_TRIM) {
            return self::VIDEO_PROCESSING_MODE_AUTO_TRIM;
        }

        return $this->processing_metadata?->trimRequested === true
            ? self::VIDEO_PROCESSING_MODE_AUTO_TRIM
            : self::VIDEO_PROCESSING_MODE_FULL_VIDEO;
    }

    /**
     * @return array<string, mixed>
     */
    public function videoQualityMetadata(): array
    {
        $metadata = $this->processing_metadata?->videoQuality;

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function putVideoQualityMetadata(array $metadata): void
    {
        $processingMetadata = $this->processing_metadata?->toArray() ?? [];
        $processingMetadata['video_quality'] = $metadata;

        $this->forceFill(['processing_metadata' => $processingMetadata])->save();
    }

    /**
     * Temp-disk-relative path of the stored full-service transcript JSON, if
     * the LLM-first structure pipeline has produced one for this run.
     */
    public function serviceTranscriptPath(): ?string
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];
        $path = $metadata['service_transcript_path'] ?? null;

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    /**
     * Whether the full-service transcript artifact both is recorded and still
     * exists on the temp disk (it deliberately survives run cleanup).
     */
    public function hasStoredServiceTranscript(): bool
    {
        $transcriptPath = $this->serviceTranscriptPath();

        if ($transcriptPath === null) {
            return false;
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        return Storage::disk($tempDisk)->exists($transcriptPath);
    }

    public function putServiceTranscriptPath(string $path): void
    {
        $processingMetadata = $this->processing_metadata?->toArray() ?? [];
        $processingMetadata['service_transcript_path'] = $path;

        $this->forceFill(['processing_metadata' => $processingMetadata])->save();
    }

    public function isAutoTrimVideoRun(): bool
    {
        return $this->processing_type === MediaType::Video
            && $this->videoProcessingMode() === self::VIDEO_PROCESSING_MODE_AUTO_TRIM;
    }

    public function usesSegmentationPipeline(): bool
    {
        return $this->processing_type === MediaType::Livestream
            || $this->isAutoTrimVideoRun();
    }

    public function canUseManualSermonReview(): bool
    {
        return $this->usesSegmentationPipeline();
    }

    public static function makeDedupKey(string $fileHash, MediaType $mediaType, string $videoMode, ?int $ownerUserId): string
    {
        $callerScope = $ownerUserId === null ? 'system' : "user:{$ownerUserId}";

        return "{$fileHash}:{$callerScope}:{$mediaType->value}:{$videoMode}";
    }

    public function buildDedupKey(): ?string
    {
        if ($this->file_hash === null) {
            return null;
        }

        return self::makeDedupKey(
            $this->file_hash,
            $this->processing_type,
            $this->videoProcessingMode(),
            $this->owner_user_id
        );
    }

    public function processingPipelineProfile(): string
    {
        return match (true) {
            $this->processing_type === MediaType::Audio => 'audio',
            $this->processing_type === MediaType::Livestream => 'livestream',
            $this->isAutoTrimVideoRun() => 'video_auto_trim',
            default => 'video',
        };
    }

    // Accessors for backward compatibility

    /**
     * Accessor for stored_file_path (maps to source_file_path).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function storedFilePath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->source_file_path,
            set: fn ($value) => ['source_file_path' => $value]
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function originalFilename(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyManualReviewMetadata(): array
    {
        $reasonCode = $this->legacyManualReviewReasonCode();
        if ($reasonCode === null) {
            return [];
        }

        return [
            'status' => 'required',
            'reason_code' => $reasonCode,
            'reason_message' => $this->legacyManualReviewReasonMessage(),
            'flagged_at' => $this->updated_at?->toIso8601String(),
            'speech_segments' => [],
        ];
    }

    private function legacyManualReviewReasonCode(): ?string
    {
        $message = $this->legacyManualReviewReasonMessage();

        return match (true) {
            $message !== null && str_contains($message, 'No speech block met the 20-minute sermon threshold.') => 'no_qualifying_speech_block',
            $message !== null && str_contains($message, 'Multiple speech blocks met the 20-minute sermon threshold.') => 'multiple_qualifying_speech_blocks',
            $message !== null && str_contains($message, 'The longest speech block was not at least 1.5x longer than the next-longest speech block.') => 'ratio_below_threshold',
            $message !== null && str_contains($message, 'Sermon auto-selection confidence was insufficient.') => 'manual_confidence_review',
            default => null,
        };
    }

    private function legacyManualReviewReasonMessage(): ?string
    {
        if (
            $this->processing_type !== MediaType::Livestream
            || $this->status !== ProcessingStatus::Failed
            || $this->current_step !== 'manual_review_required'
            || ! is_string($this->error_message)
            || $this->error_message === ''
        ) {
            return null;
        }

        return str_starts_with($this->error_message, 'Manual Review Note: ')
            ? substr($this->error_message, strlen('Manual Review Note: '))
            : $this->error_message;
    }

    /**
     * Get all temporary file paths associated with this processing run.
     *
     * @return list<string>
     */
    public function temporaryFilePaths(): array
    {
        $tempFiles = [];

        if (filled($this->source_file_path)) {
            $tempFiles[] = (string) $this->source_file_path;
        }

        if (filled($this->enhanced_audio_file_path)) {
            $tempFiles[] = (string) $this->enhanced_audio_file_path;
        }

        if (filled($this->rms_log_path)) {
            $tempFiles[] = (string) $this->rms_log_path;
        }

        if (filled($this->video_file_path) && str_contains((string) $this->video_file_path, 'temp/')) {
            $tempFiles[] = (string) $this->video_file_path;
        }

        $metadata = $this->processing_metadata?->toArray() ?? [];

        // service_transcript_path is deliberately absent: the full-service
        // transcript is a small JSON artifact that `structure:evaluate
        // --processing-id` loads after the run completes, so run cleanup must
        // not delete it. Re-runs overwrite it in place (keyed by processing id).
        foreach (['extracted_segment_path', 'extracted_audio_path', 'temp_video_path'] as $key) {
            $path = $metadata[$key] ?? null;
            if (filled($path) && is_string($path)) {
                $tempFiles[] = $path;
            }
        }

        if ($this->processing_type === MediaType::Video) {
            $storedPath = $this->stored_file_path;
            if (filled($storedPath) && str_contains((string) $storedPath, 'temp/')) {
                $tempFiles[] = (string) $storedPath;
            }
        }

        return array_values(array_unique($tempFiles));
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'processing_id' => ['sometimes', 'required', 'string', 'size:36'],
            'processing_type' => ['sometimes', 'required', Rule::enum(MediaType::class)],
            'status' => ['sometimes', 'required', Rule::enum(ProcessingStatus::class)],
            'original_filename' => ['sometimes', 'required', 'string', 'max:255'],
            'file_hash' => ['nullable', 'string', 'max:64'],
            'file_size' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'extracted_date' => ['nullable', 'date'],
            'extracted_service' => ['nullable', Rule::enum(SermonService::class)],
            'sermon_start_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'sermon_end_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999', 'gte:sermon_start_time'],
            'sermon_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:sermons,id'],
            'owner_user_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:users,id'],
            'church_service_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:church_services,id'],
            'error_message' => ['nullable', 'string'],
            'current_step' => ['nullable', 'string', 'max:255'],
            'source_file_path' => ['nullable', 'string', 'max:255'],
            'stored_file_path' => ['nullable', 'string', 'max:255'],
            'audio_file_path' => ['nullable', 'string', 'max:255'],
            'video_file_path' => ['nullable', 'string', 'max:255'],
            'transcript_file_path' => ['nullable', 'string', 'max:255'],
            'enhanced_audio_file_path' => ['nullable', 'string', 'max:255'],
            'rms_log_path' => ['nullable', 'string', 'max:255'],
            'threshold_method' => ['nullable', 'string', 'max:255'],
            'adaptive_threshold' => ['nullable', 'numeric'],
            'queue_name' => ['nullable', 'string', 'max:255'],
            'job_id' => ['nullable', 'string', 'max:255'],
            'dedup_key' => ['nullable', 'string', 'max:128'],
            'attempt_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_degraded_completion' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function legacyManualReviewReasonPatterns(): array
    {
        return [
            'No speech block met the 20-minute sermon threshold.',
            'Multiple speech blocks met the 20-minute sermon threshold.',
            'The longest speech block was not at least 1.5x longer than the next-longest speech block.',
            'Sermon auto-selection confidence was insufficient.',
        ];
    }
}
