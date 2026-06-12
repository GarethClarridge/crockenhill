<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SongAuthorFactory;
use Illuminate\Contracts\Validation\ImplicitRule;
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
     * @return Attribute<string, string>
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function firstName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function lastName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $author = null): array
    {
        $uniqueRule = Rule::unique('song_authors', 'display_name');
        $trimmedTextRule = new class implements ImplicitRule
        {
            public function passes($attribute, $value): bool
            {
                if ($value === null) {
                    return true;
                }

                return is_string($value)
                    && $value !== ''
                    && trim($value) === $value;
            }

            public function message(): string
            {
                return 'The :attribute field must not be empty or contain leading or trailing whitespace.';
            }
        };

        if ($author) {
            $uniqueRule->ignore($author->id);
        }

        return [
            'display_name' => ['required', 'string', 'max:255', $trimmedTextRule, $uniqueRule],
            'first_name' => ['nullable', 'string', 'max:255', $trimmedTextRule],
            'last_name' => ['nullable', 'string', 'max:255', $trimmedTextRule],
        ];
    }

    /**
     * @return BelongsToMany<Song, $this>
     */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'song_author_song', 'song_author_id', 'song_id')
            ->withPivot('author_type');
    }
}
