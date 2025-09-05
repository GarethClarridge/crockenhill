<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\SermonProcessingLog
 *
 * @property int $id
 * @property string $processing_id
 * @property string $source_type
 * @property string $original_filename
 * @property ProcessingStatus $status
 * @property ?string $current_step
 * @property ?string $error_message
 * @property ?array $source_metadata
 * @property ?int $sermon_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?Sermon $sermon
 *
 * @method static \Database\Factories\SermonProcessingLogFactory factory(...$parameters)
 * @method static Builder|SermonProcessingLog newModelQuery()
 * @method static Builder|SermonProcessingLog newQuery()
 * @method static Builder|SermonProcessingLog query()
 * @method static Builder|SermonProcessingLog pending()
 * @method static Builder|SermonProcessingLog processing()
 * @method static Builder|SermonProcessingLog completed()
 * @method static Builder|SermonProcessingLog failed()
 * @method static Builder|SermonProcessingLog recent()
 *
 * @mixin \Eloquent
 */
class SermonProcessingLog extends Model
{
    use HasFactory;

    protected $table = 'sermon_processing_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'processing_id',
        'source_type',
        'original_filename',
        'status',
        'current_step',
        'error_message',
        'source_metadata',
        'sermon_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ProcessingStatus::class,
        'source_metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sermon associated with this processing log.
     */
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    /**
     * Scope to get pending processing logs.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PENDING);
    }

    /**
     * Scope to get processing logs that are currently being processed.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::PROCESSING);
    }

    /**
     * Scope to get completed processing logs.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::COMPLETED);
    }

    /**
     * Scope to get failed processing logs.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::FAILED);
    }

    /**
     * Scope to get recent processing logs (last 30 days).
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays(30));
    }

    /**
     * Check if the processing is complete.
     */
    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }

    /**
     * Check if the processing has failed.
     */
    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }

    /**
     * Check if the processing is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->status->isInProgress();
    }

    /**
     * Check if the processing is pending.
     */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * Mark the processing as started.
     */
    public function markAsProcessing(?string $step = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => $step,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the processing as completed.
     */
    public function markAsCompleted(?int $sermonId = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::COMPLETED,
            'current_step' => 'completed',
            'sermon_id' => $sermonId ?? $this->sermon_id,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the processing as failed.
     */
    public function markAsFailed(string $errorMessage, ?string $step = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::FAILED,
            'current_step' => $step ?? $this->current_step,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Update the current processing step.
     */
    public function updateStep(string $step): bool
    {
        return $this->update(['current_step' => $step]);
    }
}
