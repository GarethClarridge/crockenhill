<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $processing_id
 * @property string $processing_type
 * @property ProcessingStatus $status
 * @property string|null $current_step
 * @property string|null $error_message
 * @property string $original_filename
 * @property int|null $file_size
 * @property float|null $duration
 * @property string|null $source_file_path
 * @property string|null $stored_file_path
 * @property string|null $audio_file_path
 * @property string|null $video_file_path
 * @property string|null $transcript_file_path
 * @property string|null $rms_log_path
 * @property float|null $sermon_start_time
 * @property float|null $sermon_end_time
 * @property array|null $ai_analysis
 * @property array|null $processing_metadata
 * @property string|null $threshold_method
 * @property float|null $adaptive_threshold
 * @property array|null $rms_stats
 * @property array|null $visual_samples
 * @property array|null $song_clusters
 * @property int|null $visual_sample_count
 * @property float|null $visual_processing_time
 * @property int|null $sermon_id
 * @property int|null $owner_user_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $owner
 * @property-read Sermon|null $sermon
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LivestreamSegment> $segments
 */
class MediaProcessingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_id',
        'processing_type',
        'status',
        'current_step',
        'error_message',

        // File info
        'original_filename',
        'file_size',
        'duration',

        // File paths
        'source_file_path',
        'stored_file_path', // Alias for source_file_path (backward compatibility)
        'audio_file_path',
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
            'status' => ProcessingStatus::class,
            'ai_analysis' => 'array',
            'processing_metadata' => 'array',
            'rms_stats' => 'array',
            'visual_samples' => 'array',
            'song_clusters' => 'array',
            'duration' => 'float',
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

    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function segments(): HasMany
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

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PROCESSING);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PENDING);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::COMPLETED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::FAILED);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

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

    public function markAsProcessing(?string $step = null): bool
    {
        if ($this->fresh()->isCancelled()) {
            return false;
        }

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

    public function markAsCancelled(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::CANCELLED,
            'current_step' => 'cancelled',
            'error_message' => $message ?? 'Processing cancelled by user',
            'completed_at' => now(),
        ]);
    }

    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::CANCELLED;
    }

    public function updateStep(string $step): bool
    {
        return $this->update(['current_step' => $step]);
    }

    // Accessors for backward compatibility

    /**
     * Accessor for stored_file_path (maps to source_file_path)
     */
    protected function storedFilePath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->source_file_path,
            set: fn ($value) => ['source_file_path' => $value]
        );
    }
}
