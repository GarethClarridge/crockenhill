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
