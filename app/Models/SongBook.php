<?php

declare(strict_types=1);

namespace App\Models;

use Closure;
use Database\Factories\SongBookFactory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function publisher(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $songBook = null): array
    {
        $uniqueSourceBookId = Rule::unique('song_books', 'source_book_id');
        if ($songBook) {
            $uniqueSourceBookId->ignore($songBook->id);
        }

        $trimmedTextRule = new class implements ValidationRule
        {
            public bool $implicit = true;

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if ($value === null) {
                    return;
                }

                if (! is_string($value) || $value === '' || trim($value) !== $value) {
                    $fail('The :attribute field must not be empty or contain leading or trailing whitespace.');
                }
            }
        };

        return [
            'source_book_id' => ['required', 'integer', $uniqueSourceBookId],
            'name' => ['required', 'string', 'max:255', $trimmedTextRule],
            'publisher' => ['nullable', 'string', 'max:255', $trimmedTextRule],
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
