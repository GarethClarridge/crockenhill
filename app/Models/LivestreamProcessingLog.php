<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

class LivestreamProcessingLog extends Model
{
    use HasFactory;
    protected $fillable = [
        'processing_id',
        'status',
        'original_filename',
        'original_file_path',
        'file_size',
        'file_format',
        'duration',
        'rms_log_path',
        'sermon_audio_path',
        'sermon_video_path',
        'sermon_start_time',
        'sermon_end_time',
        'sermon_id',
        'error_message',
        'processing_metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'processing_metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration' => 'float',
        'sermon_start_time' => 'float',
        'sermon_end_time' => 'float',
        'file_size' => 'integer',
    ];

    public function segments(): HasMany
    {
        return $this->hasMany(LivestreamSegment::class, 'processing_log_id');
    }

    public function sermon(): HasOne
    {
        return $this->hasOne(Sermon::class, 'livestream_processing_id');
    }

    public function speechSegments(): HasMany
    {
        return $this->segments()->where('classification', 'speech');
    }

    public function songSegments(): HasMany
    {
        return $this->segments()->where('classification', 'song');
    }

    public function sermonCandidateSegment(): HasOne
    {
        return $this->hasOne(LivestreamSegment::class, 'processing_log_id')
            ->where('is_sermon_candidate', true);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public static function findByProcessingId(string $processingId): ?self
    {
        return static::where('processing_id', $processingId)->first();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['processing', 'segmentation_complete', 'extraction_complete', 'sermon_submitted']);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function updateStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }

    protected function processingDuration(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->started_at && $this->completed_at) {
                    return $this->started_at->diffInSeconds($this->completed_at);
                }
                return null;
            }
        );
    }

    protected function fileSizeFormatted(): Attribute
    {
        return Attribute::make(
            get: function () {
                $bytes = $this->file_size;
                $units = ['B', 'KB', 'MB', 'GB'];
                $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
                return number_format($bytes / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
            }
        );
    }
}
