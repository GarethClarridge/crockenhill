<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SermonPublicationState;
use Database\Factories\SongVideoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property int $song_id
 * @property int|null $service_section_id
 * @property int|null $church_service_id
 * @property string $video_file_path
 * @property float|null $duration
 * @property Carbon|null $recorded_date
 * @property bool $is_featured
 * @property SermonPublicationState $publication_state
 * @property int|null $historic_import_operation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Song $song
 * @property-read ServiceSection|null $serviceSection
 * @property-read ChurchService|null $churchService
 *
 * @method static \Database\Factories\SongVideoFactory factory(...$parameters)
 * @method static Builder<SongVideo> newModelQuery()
 * @method static Builder<SongVideo> newQuery()
 * @method static Builder<SongVideo> query()
 * @method static Builder<SongVideo> featured()
 * @method static Builder<SongVideo> forSong(int $songId)
 * @method static Builder<SongVideo> publiclyReleased()
 *
 * @mixin \Eloquent
 */
class SongVideo extends Model
{
    /** @use HasFactory<SongVideoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'song_id',
        'service_section_id',
        'church_service_id',
        'video_file_path',
        'duration',
        'recorded_date',
        'is_featured',
        'publication_state',
        'historic_import_operation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'recorded_date' => 'date',
            'duration' => 'float',
            'publication_state' => SermonPublicationState::class,
        ];
    }

    public function isFeatured(): bool
    {
        return $this->is_featured;
    }

    /**
     * @param  Builder<SongVideo>  $query
     * @return Builder<SongVideo>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * A historic import stages its song recordings on the private quarantine
     * disk, so every public read has to consume the same whole-content
     * publication decision the imported sermons do.
     *
     * @param  Builder<SongVideo>  $query
     * @return Builder<SongVideo>
     */
    public function scopePubliclyReleased(Builder $query): Builder
    {
        return $query->where('publication_state', SermonPublicationState::Published);
    }

    /**
     * @param  Builder<SongVideo>  $query
     * @return Builder<SongVideo>
     */
    public function scopeForSong(Builder $query, int $songId): Builder
    {
        return $query->where('song_id', $songId);
    }

    /**
     * @return BelongsTo<Song, $this>
     */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /**
     * @return BelongsTo<ServiceSection, $this>
     */
    public function serviceSection(): BelongsTo
    {
        return $this->belongsTo(ServiceSection::class);
    }

    /**
     * @return BelongsTo<ChurchService, $this>
     */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function videoFilePath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $songVideo = null): array
    {
        $uniqueServiceSection = Rule::unique('song_videos', 'service_section_id');

        if ($songVideo) {
            $uniqueServiceSection->ignore($songVideo->id);
        }

        return [
            'song_id' => ['required', 'integer', 'exists:songs,id'],
            'service_section_id' => ['nullable', 'integer', 'exists:service_sections,id', $uniqueServiceSection],
            'church_service_id' => ['nullable', 'integer', 'exists:church_services,id'],
            'video_file_path' => ['required', 'string', 'max:500'],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'recorded_date' => ['nullable', 'date'],
            'is_featured' => ['required', 'boolean'],
        ];
    }
}
