<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SpeakerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\SpeakerProfile
 *
 * @property int $id
 * @property int $preacher_id
 * @property string $provider
 * @property string $model_version
 * @property array<int, float> $centroid_embedding
 * @property int $sample_count
 * @property ?float $quality_score
 * @property ?float $accept_threshold
 * @property ?float $margin_threshold
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 *
 * @method static \Database\Factories\SpeakerProfileFactory factory(...$parameters)
 * @method static Builder|SpeakerProfile newModelQuery()
 * @method static Builder|SpeakerProfile newQuery()
 * @method static Builder|SpeakerProfile query()
 * @method static Builder|SpeakerProfile active()
 *
 * @mixin \Eloquent
 */
class SpeakerProfile extends Model
{
    /** @use HasFactory<SpeakerProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'preacher_id',
        'provider',
        'model_version',
        'centroid_embedding',
        'sample_count',
        'quality_score',
        'accept_threshold',
        'margin_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'preacher_id' => 'integer',
            'centroid_embedding' => 'array',
            'sample_count' => 'integer',
            'quality_score' => 'float',
            'accept_threshold' => 'float',
            'margin_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Preacher, $this>
     */
    public function preacher(): BelongsTo
    {
        return $this->belongsTo(Preacher::class);
    }

    /**
     * @return HasMany<SpeakerSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(SpeakerSample::class);
    }

    /**
     * @param  Builder<SpeakerProfile>  $query
     * @return Builder<SpeakerProfile>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getEffectiveAcceptThreshold(): float
    {
        return $this->accept_threshold
            ?? (float) config('media-processing.speaker_identification.accept_threshold', 0.75);
    }

    public function getEffectiveMarginThreshold(): float
    {
        return $this->margin_threshold
            ?? (float) config('media-processing.speaker_identification.margin_threshold', 0.10);
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'preacher_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:9223372036854775807', 'exists:preachers,id'],
            'provider' => ['sometimes', 'required', 'string', 'max:50'],
            'model_version' => ['sometimes', 'required', 'string', 'max:50'],
            'centroid_embedding' => ['sometimes', 'required', 'array'],
            'sample_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'accept_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'margin_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['boolean'],
        ];
    }
}
