<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LivestreamSegmentClassification;
use Database\Factories\LivestreamSegmentFactory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property int $media_processing_log_id
 * @property int $segment_index
 * @property float $start_time
 * @property float $end_time
 * @property float $duration
 * @property LivestreamSegmentClassification $classification
 * @property float|null $avg_rms
 * @property float|null $peak_rms
 * @property bool $is_sermon_candidate
 * @property bool|null $is_sermon_segment
 * @property int|null $segment_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LivestreamSegment extends Model
{
    /** @use HasFactory<LivestreamSegmentFactory> */
    use HasFactory;

    protected $fillable = [
        'media_processing_log_id',
        'segment_index',
        'start_time',
        'end_time',
        'duration',
        'classification',
        'avg_rms',
        'peak_rms',
        'is_sermon_candidate',
        'is_sermon_segment',
        'segment_order',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'media_processing_log_id' => 'integer',
            'segment_index' => 'integer',
            'metadata' => 'array',
            'start_time' => 'float',
            'end_time' => 'float',
            'duration' => 'float',
            'classification' => LivestreamSegmentClassification::class,
            'is_sermon_segment' => 'boolean',
            'is_sermon_candidate' => 'boolean',
            'avg_rms' => 'float',
            'peak_rms' => 'float',
            'segment_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaProcessingLog, $this>
     */
    public function processingLog(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'media_processing_log_id');
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeClassifiedAs(Builder $query, LivestreamSegmentClassification|string $classification): Builder
    {
        return $query->where('classification', $classification);
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeSpeech(Builder $query): Builder
    {
        return $query->where('classification', LivestreamSegmentClassification::Speech);
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeSong(Builder $query): Builder
    {
        return $query->where('classification', LivestreamSegmentClassification::Song);
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeSilence(Builder $query): Builder
    {
        return $query->where('classification', LivestreamSegmentClassification::Silence);
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeSermonCandidates(Builder $query): Builder
    {
        return $query->where('is_sermon_candidate', true);
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeByDurationRange(Builder $query, float $minDuration, ?float $maxDuration = null): Builder
    {
        $query->where('duration', '>=', $minDuration);

        if ($maxDuration !== null) {
            $query->where('duration', '<=', $maxDuration);
        }

        return $query;
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeOrderedByTime(Builder $query): Builder
    {
        return $query->orderBy('start_time');
    }

    /**
     * @param  Builder<LivestreamSegment>  $query
     * @return Builder<LivestreamSegment>
     */
    public function scopeOrderedByDuration(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('duration', $direction === 'asc' ? 'asc' : 'desc');
    }

    public function isSpeech(): bool
    {
        return $this->classification === LivestreamSegmentClassification::Speech;
    }

    public function isSong(): bool
    {
        return $this->classification === LivestreamSegmentClassification::Song;
    }

    public function isSilence(): bool
    {
        return $this->classification === LivestreamSegmentClassification::Silence;
    }

    public function isSermonCandidate(): bool
    {
        return $this->is_sermon_candidate;
    }

    public function getDurationInMinutes(): float
    {
        return round($this->duration / 60, 2);
    }

    public function getStartTimeFormatted(): string
    {
        return $this->formatTime($this->start_time);
    }

    public function getEndTimeFormatted(): string
    {
        return $this->formatTime($this->end_time);
    }

    public function getDurationFormatted(): string
    {
        return $this->formatDuration($this->duration);
    }

    private function formatTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    private function formatDuration(float $seconds): string
    {
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;

        if ($minutes > 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;

            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        }

        return sprintf('%dm %ds', $minutes, $seconds);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function timeRange(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getStartTimeFormatted().' - '.$this->getEndTimeFormatted()
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function classificationDisplay(): Attribute
    {
        return Attribute::make(
            get: fn () => ucfirst($this->classification->value)
        );
    }

    public static function getLongestSpeechSegment(int $processingLogId): ?self
    {
        return static::query()->where('media_processing_log_id', $processingLogId)
            ->speech()
            ->orderByRaw('(end_time - start_time) DESC')
            ->first();
    }

    /**
     * @return array<string, list<string|ValidationRule|\Stringable>>
     */
    public static function validationRules(): array
    {
        return [
            'media_processing_log_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'exists:media_processing_logs,id'],
            'segment_index' => ['required', 'integer', 'min:0', 'max:65535'],
            'start_time' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
            'end_time' => ['required', 'numeric', 'min:0', 'max:9999999.999', 'gte:start_time'],
            'duration' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
            'classification' => ['required', Rule::enum(LivestreamSegmentClassification::class)],
            'is_sermon_segment' => ['sometimes', 'boolean'],
            'is_sermon_candidate' => ['sometimes', 'boolean'],
            'avg_rms' => ['nullable', 'numeric'],
            'peak_rms' => ['nullable', 'numeric'],
            'segment_order' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public static function getSegmentsSummary(int $processingLogId): array
    {
        $segments = static::query()->where('media_processing_log_id', $processingLogId)->get();

        return [
            'total_segments' => $segments->count(),
            'speech_segments' => $segments->where('classification', LivestreamSegmentClassification::Speech)->count(),
            'song_segments' => $segments->where('classification', LivestreamSegmentClassification::Song)->count(),
            'silence_segments' => $segments->where('classification', LivestreamSegmentClassification::Silence)->count(),
            'total_duration' => $segments->sum('duration'),
            'speech_duration' => $segments->where('classification', LivestreamSegmentClassification::Speech)->sum('duration'),
            'song_duration' => $segments->where('classification', LivestreamSegmentClassification::Song)->sum('duration'),
            'longest_speech_duration' => $segments->where('classification', LivestreamSegmentClassification::Speech)->max('duration'),
        ];
    }
}
