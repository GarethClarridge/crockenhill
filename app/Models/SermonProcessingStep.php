<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\SermonProcessingStep
 *
 * @property int $id
 * @property string $processing_id
 * @property string $step
 * @property string $status
 * @property ?string $message
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SermonProcessingStep extends Model
{
    /** @use HasFactory<\Database\Factories\SermonProcessingStepFactory> */
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
            'status' => ProcessingStatus::STARTED->value,
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
            'status' => ProcessingStatus::COMPLETED->value,
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
            'status' => ProcessingStatus::FAILED->value,
            'message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as cancelled.
     */
    public function markAsCancelled(?string $message = null): bool
    {
        return $this->update([
            'status' => ProcessingStatus::CANCELLED->value,
            'message' => $message ?? 'Cancelled by user',
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if the step is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === ProcessingStatus::COMPLETED->value;
    }

    /**
     * Check if the step has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::FAILED->value;
    }

    /**
     * Check if the step is in progress.
     */
    public function isStarted(): bool
    {
        return $this->status === ProcessingStatus::STARTED->value;
    }

    /**
     * Check if the step is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::CANCELLED->value;
    }
}
