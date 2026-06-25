<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SampleSource;
use Database\Factories\SpeakerSampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 *
 * @method static \Database\Factories\SpeakerSampleFactory factory(...$parameters)
 *
 * @mixin \Eloquent
 */
class SpeakerSample extends Model
{
    /** @use HasFactory<SpeakerSampleFactory> */
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
            'speaker_profile_id' => 'integer',
            'sermon_id' => 'integer',
            'media_processing_log_id' => 'integer',
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

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'speaker_profile_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647', 'exists:speaker_profiles,id'],
            'sermon_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'exists:sermons,id'],
            'media_processing_log_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'exists:media_processing_logs,id'],
            'embedding' => ['sometimes', 'required', 'array'],
            'quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'duration_seconds' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999.999'],
            'source' => ['sometimes', 'required', Rule::enum(SampleSource::class)],
            'approved' => ['boolean'],
        ];
    }
}
