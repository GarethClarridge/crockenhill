<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessingStatus;
use Database\Factories\SermonProcessingStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\SermonProcessingStep
 *
 * @property int $id
 * @property string $processing_id
 * @property string $step
 * @property ProcessingStatus $status
 * @property ?string $message
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read MediaProcessingLog|null $processingLog
 */
class SermonProcessingStep extends Model
{
    /** @use HasFactory<SermonProcessingStepFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'processing_id',
        'step',
        'status',
        'message',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Mark the step as started.
     */
    public function markAsStarted(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::Started->value,
            'message' => $message,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    /**
     * Mark the step as completed.
     */
    public function markAsCompleted(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::Completed->value,
            'message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as failed.
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => ProcessingStatus::Failed->value,
            'message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as skipped.
     */
    public function markAsSkipped(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::Skipped->value,
            'message' => $message,
            'started_at' => $this->started_at ?? now(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as cancelled.
     */
    public function markAsCancelled(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::Cancelled->value,
            'message' => $message ?? 'Cancelled by user',
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if the step is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === ProcessingStatus::Completed;
    }

    /**
     * Check if the step has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::Failed;
    }

    /**
     * Check if the step was skipped.
     */
    public function isSkipped(): bool
    {
        return $this->status === ProcessingStatus::Skipped;
    }

    /**
     * Check if the step is in progress.
     */
    public function isStarted(): bool
    {
        return $this->status === ProcessingStatus::Started;
    }

    /**
     * Check if the step is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::Cancelled;
    }

    /**
     * @return BelongsTo<MediaProcessingLog, $this>
     */
    public function processingLog(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'processing_id', 'processing_id');
    }
}
