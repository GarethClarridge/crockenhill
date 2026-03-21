<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SampleSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\SpeakerSample
 *
 * @property int $id
 * @property int $speaker_profile_id
 * @property ?int $sermon_id
 * @property ?int $media_processing_log_id
 * @property array<int, float> $embedding
 * @property float $duration_seconds
 * @property ?float $quality_score
 * @property SampleSource $source
 * @property bool $approved
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 *
 * @method static \Database\Factories\SpeakerSampleFactory factory(...$parameters)
 *
 * @mixin \Eloquent
 */
class SpeakerSample extends Model
{
    /** @use HasFactory<\Database\Factories\SpeakerSampleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'speaker_profile_id',
        'sermon_id',
        'media_processing_log_id',
        'embedding',
        'duration_seconds',
        'quality_score',
        'source',
        'approved',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'duration_seconds' => 'float',
            'quality_score' => 'float',
            'source' => SampleSource::class,
            'approved' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SpeakerProfile, $this>
     */
    public function speakerProfile(): BelongsTo
    {
        return $this->belongsTo(SpeakerProfile::class);
    }

    /**
     * @return BelongsTo<Sermon, $this>
     */
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    /**
     * @return BelongsTo<MediaProcessingLog, $this>
     */
    public function processingLog(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'media_processing_log_id');
    }
}
