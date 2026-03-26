<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $song_id
 * @property int|null $service_section_id
 * @property int|null $church_service_id
 * @property string $video_file_path
 * @property float|null $duration
 * @property \Illuminate\Support\Carbon|null $recorded_date
 * @property bool $is_featured
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
 *
 * @mixin \Eloquent
 */
class SongVideo extends Model
{
    /** @use HasFactory<\Database\Factories\SongVideoFactory> */
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
}
