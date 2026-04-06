<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ProcessingManualReviewMetadata;
use App\Data\ProcessingMetadata;
use App\Data\ProcessingMetadataCast;
use App\Data\SermonAnalysis;
use App\Data\SermonAnalysisCast;
use App\Data\SongClusterCollection;
use App\Data\SongClusterCollectionCast;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $processing_id
 * @property MediaType $processing_type
 * @property ProcessingStatus $status
 * @property string|null $current_step
 * @property string|null $error_message
 * @property string $original_filename
 * @property string|null $file_hash
 * @property int|null $file_size
 * @property float|null $duration
 * @property \Illuminate\Support\Carbon|null $extracted_date
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
 * @property SongClusterCollection|null $song_clusters
 * @property int|null $visual_sample_count
 * @property float|null $visual_processing_time
 * @property int|null $sermon_id
 * @property int|null $owner_user_id
 * @property int|null $church_service_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ChurchService|null $churchService
 * @property-read User|null $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SermonProcessingStep> $processingSteps
 * @property-read Sermon|null $sermon
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LivestreamSegment> $segments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceSection> $serviceSections
 */
class MediaProcessingLog extends Model
{
    /** @use HasFactory<\Database\Factories\MediaProcessingLogFactory> */
    use HasFactory;

    protected $fillable = [
        'processing_id',
        'processing_type',
        'status',
        'current_step',
        'error_message',

        // File info
        'original_filename',
        'file_hash',
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

        // Visual analysis fields
        'visual_samples',
        'song_clusters',
        'visual_sample_count',
        'visual_processing_time',

        // Relationships
        'sermon_id',
        'owner_user_id',
        'church_service_id',

        // Timestamps
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'processing_type' => MediaType::class,
            'status' => ProcessingStatus::class,
            'ai_analysis' => SermonAnalysisCast::class,
            'processing_metadata' => ProcessingMetadataCast::class,
            'rms_stats' => 'array',
            'visual_samples' => 'array',
            'song_clusters' => SongClusterCollectionCast::class,
            'duration' => 'float',
            'extracted_date' => 'date',
            'extracted_service' => SermonService::class,
            'sermon_start_time' => 'float',
            'sermon_end_time' => 'float',
            'adaptive_threshold' => 'float',
            'visual_sample_count' => 'integer',
            'visual_processing_time' => 'float',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PROCESSING->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PENDING->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::COMPLETED->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::FAILED->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeAwaitingManualSermonReview(Builder $query): Builder
    {
        return $query
            ->where('processing_type', MediaType::Livestream->value)
            ->where('status', ProcessingStatus::FAILED->value)
            ->where('current_step', 'manual_review_required')
            ->where(function (Builder $query): void {
                $query->whereNotNull('processing_metadata->manual_review->reason_code');

                foreach (self::legacyManualReviewReasonPatterns() as $pattern) {
                    $query->orWhere('error_message', 'like', '%'.$pattern.'%');
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
        if ($user->is_admin) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
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

    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::CANCELLED;
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

    public function requiresManualSermonReview(): bool
    {
        $manualReviewStatus = $this->processing_metadata?->manualReview?->status;

        if ($manualReviewStatus === 'required') {
            return true;
        }

        if ($manualReviewStatus === 'confirmed') {
            return false;
        }

        return $this->legacyManualReviewReasonCode() !== null
            && $this->status === ProcessingStatus::FAILED
            && $this->current_step === 'manual_review_required'
            && $this->processing_type === MediaType::Livestream;
    }

    // Accessors for backward compatibility

    /**
     * Accessor for stored_file_path (maps to source_file_path)
     */
    /**
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
            || $this->status !== ProcessingStatus::FAILED
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
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'file_size' => ['nullable', 'integer', 'min:0'],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'sermon_start_time' => ['nullable', 'numeric', 'min:0'],
            'sermon_end_time' => ['nullable', 'numeric', 'min:0', 'gte:sermon_start_time'],
            'visual_sample_count' => ['nullable', 'integer', 'min:0'],
            'visual_processing_time' => ['nullable', 'numeric', 'min:0'],
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
