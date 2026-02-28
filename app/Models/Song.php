<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $canonical_key
 * @property string $title
 * @property string|null $alternate_title
 * @property string $lyrics_xml
 * @property string|null $lyrics_plain
 * @property string|null $verse_order
 * @property string|null $copyright
 * @property string|null $comments
 * @property string|null $ccli_number
 * @property array<string, mixed>|null $import_metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SongAuthor> $authors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SongBook> $books
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChurchServiceItem> $churchServiceItems
 *
 * @method static \Database\Factories\SongFactory factory(...$parameters)
 * @method static Builder<Song> newModelQuery()
 * @method static Builder<Song> newQuery()
 * @method static Builder<Song> query()
 *
 * @mixin \Eloquent
 */
class Song extends Model
{
    /** @use HasFactory<\Database\Factories\SongFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'canonical_key',
        'title',
        'alternate_title',
        'lyrics_xml',
        'lyrics_plain',
        'verse_order',
        'copyright',
        'comments',
        'ccli_number',
        'import_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'import_metadata' => 'array',
        ];
    }

    public static function canonicalizeKey(string $value): string
    {
        $normalised = trim(Str::lower($value));
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        return trim($normalised);
    }

    /**
     * @return BelongsToMany<SongAuthor, $this>
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(SongAuthor::class, 'song_author_song', 'song_id', 'song_author_id')
            ->withPivot('author_type');
    }

    /**
     * @return BelongsToMany<SongBook, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(SongBook::class, 'song_book_song', 'song_id', 'song_book_id')
            ->withPivot('entry');
    }

    /**
     * @return HasMany<ChurchServiceItem, $this>
     */
    public function churchServiceItems(): HasMany
    {
        return $this->hasMany(ChurchServiceItem::class);
    }
}
