<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SongBookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_book_id
 * @property string $name
 * @property string|null $publisher
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Song> $songs
 *
 * @method static \Database\Factories\SongBookFactory factory(...$parameters)
 * @method static Builder<SongBook> newModelQuery()
 * @method static Builder<SongBook> newQuery()
 * @method static Builder<SongBook> query()
 *
 * @mixin \Eloquent
 */
class SongBook extends Model
{
    /** @use HasFactory<SongBookFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_book_id',
        'name',
        'publisher',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_book_id' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Song, $this>
     */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'song_book_song', 'song_book_id', 'song_id')
            ->withPivot('entry');
    }
}
