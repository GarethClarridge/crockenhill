<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SongAuthorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $display_name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Song> $songs
 *
 * @method static \Database\Factories\SongAuthorFactory factory(...$parameters)
 * @method static Builder<SongAuthor> newModelQuery()
 * @method static Builder<SongAuthor> newQuery()
 * @method static Builder<SongAuthor> query()
 *
 * @mixin \Eloquent
 */
class SongAuthor extends Model
{
    /** @use HasFactory<SongAuthorFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'first_name',
        'last_name',
    ];

    /**
     * @return BelongsToMany<Song, $this>
     */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'song_author_song', 'song_author_id', 'song_id')
            ->withPivot('author_type');
    }
}
