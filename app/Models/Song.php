<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $canonical_key
 * @property string|null $first_line_key
 * @property string|null $slug
 * @property string $title
 * @property string|null $praise_number
 * @property string|null $alternate_title
 * @property string $lyrics_xml
 * @property string|null $lyrics_plain
 * @property string|null $verse_order
 * @property string|null $copyright
 * @property string|null $comments
 * @property string|null $ccli_number
 * @property array<string, mixed>|null $import_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, SongAuthor> $authors
 * @property-read Collection<int, SongBook> $books
 * @property-read Collection<int, ChurchServiceItem> $churchServiceItems
 * @property-read Collection<int, SongVideo> $videos
 * @property-read SongVideo|null $featuredVideo
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
    /** @use HasFactory<SongFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Storage length of the first_line_key column; derived keys are clamped to
     * it so a run-together lyrics paragraph cannot overflow the column on sync.
     */
    public const int FIRST_LINE_KEY_MAX_LENGTH = 255;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'canonical_key',
        'first_line_key',
        'slug',
        'title',
        'praise_number',
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
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function canonicalKey(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::canonicalizeKey($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function alternateTitle(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $song = null): array
    {
        $slugRule = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $uniqueSlug = Rule::unique('songs', 'slug');

        $ignoreId = $song?->id;

        if ($song) {
            $uniqueSlug->ignore($song->id);
        }

        $slugRule[] = $uniqueSlug;

        // Uniqueness must be checked against the *normalised* key, because the attribute
        // setter applies canonicalizeKey() before persistence. Comparing the raw input
        // against stored (already-normalised) values would miss collisions like "My Song@x"
        // vs. the stored "my song".
        $uniqueCanonicalKey = function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            $normalised = self::canonicalizeKey((string) $value);
            $query = self::query()->where('canonical_key', $normalised);
            if (filled($ignoreId)) {
                $query->where('id', '!=', $ignoreId);
            }
            if ($query->exists()) {
                $fail('The canonical key has already been taken.');
            }
        };

        return [
            // Security: Explicit length constraints are enforced on all text fields to provide
            // Defense in Depth against Denial of Service (DoS) attempts with oversized payloads.
            'title' => ['required', 'string', 'max:100'],
            'slug' => $slugRule,
            'canonical_key' => ['required', 'string', 'max:255', $uniqueCanonicalKey],
            'alternate_title' => ['nullable', 'string', 'max:255'],
            'lyrics_xml' => ['required', 'string', 'max:100000'],
            'lyrics_plain' => ['nullable', 'string', 'max:100000'],
            'verse_order' => ['nullable', 'string', 'max:255'],
            'ccli_number' => ['nullable', 'string', 'max:255'],
            'copyright' => ['nullable', 'string', 'max:1000'],
            'comments' => ['nullable', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'import_metadata' => 'array',
        ];
    }

    /**
     * The Praise! hymn-book number embedded in an OpenLP-imported title as a "#NNN" suffix
     * (letter variants like "#046A" included). Returned verbatim without the hash.
     */
    public static function praiseNumberFromTitle(?string $title): ?string
    {
        if (is_string($title) && preg_match('/#(\d{1,4}[a-z]?)\b/i', $title, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Comparison form of a Praise! number: leading zeros and case never distinguish hymns
     * ("046A" and "46a" are the same entry).
     */
    public static function normalisePraiseNumber(string $number): string
    {
        $normalised = strtolower(ltrim(trim($number), '0'));

        return $normalised === '' ? '0' : $normalised;
    }

    public static function canonicalizeKey(string $value): string
    {
        // OpenLP search_title uses @ to delimit alternate search text — strip it.
        $atPos = strpos($value, '@');
        if ($atPos !== false) {
            $value = substr($value, 0, $atPos);
        }

        $normalised = trim(Str::lower($value));
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        return trim($normalised);
    }

    /**
     * The canonicalised first non-empty lyrics line — the key a sung opening
     * is heard as ("What love could remember") when it differs from the
     * catalogued title ("His Mercy Is More"). Stored at catalog sync and
     * consulted alongside canonical_key when matching heard titles.
     */
    public static function firstLineKeyFromLyrics(?string $lyricsPlain): ?string
    {
        if (! is_string($lyricsPlain) || trim($lyricsPlain) === '') {
            return null;
        }

        foreach (preg_split('/\r?\n/', $lyricsPlain) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            // Clamp to the first_line_key column length: a run-together lyrics
            // paragraph makes this "first line" the whole song, which would
            // overflow the column on sync.
            $key = mb_substr(self::canonicalizeKey($line), 0, self::FIRST_LINE_KEY_MAX_LENGTH);

            return $key === '' ? null : $key;
        }

        return null;
    }

    /**
     * Canonical match keys for a title, including a leading "O"/"Oh" variant so a hymn catalogued
     * as "O Jesus I Have Promised" still matches a transcript of "Oh Jesus I Have Promised" (F9).
     *
     * The stored {@see self::$canonical_key} is uniquely indexed and left untouched; these
     * variants are only used when comparing, which keeps two genuinely distinct "O…"/"Oh…" songs
     * able to coexist.
     *
     * @return array<int, string>
     */
    public static function matchKeyVariants(string $value): array
    {
        $key = self::canonicalizeKey($value);

        if (blank($key)) {
            return [];
        }

        $variants = [$key];

        if (str_starts_with($key, 'oh ')) {
            $variants[] = 'o '.substr($key, 3);
        } elseif (str_starts_with($key, 'o ')) {
            $variants[] = 'oh '.substr($key, 2);
        }

        return array_values(array_unique($variants));
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

    /**
     * @return HasMany<SongVideo, $this>
     */
    public function videos(): HasMany
    {
        return $this->hasMany(SongVideo::class)->orderByRaw('recorded_date IS NULL, recorded_date DESC');
    }

    /**
     * @return HasOne<SongVideo, $this>
     */
    public function featuredVideo(): HasOne
    {
        return $this->hasOne(SongVideo::class)->where('is_featured', true);
    }

    public function displayVideo(): ?SongVideo
    {
        $featured = $this->featuredVideo()->first();
        if (filled($featured)) {
            return $featured;
        }

        return $this->hasOne(SongVideo::class)
            ->whereNotNull('recorded_date')
            ->orderByDesc('recorded_date')
            ->first();
    }
}
