<?php

declare(strict_types=1);

namespace App\Models;

use Closure;
use Database\Factories\ScripturePassageFactory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * App\Models\ScripturePassage
 *
 * @property int $id
 * @property string $bible_id
 * @property string $normalized_reference
 * @property ?string $api_passage_id
 * @property ?string $display_reference
 * @property string $html_content
 * @property string $copyright
 * @property ?string $fums_token
 * @property Carbon $fetched_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 *
 * @mixin \Eloquent
 */
class ScripturePassage extends Model
{
    /** @use HasFactory<ScripturePassageFactory> */
    use HasFactory;

    protected $fillable = [
        'bible_id',
        'normalized_reference',
        'api_passage_id',
        'display_reference',
        'html_content',
        'copyright',
        'fums_token',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function bibleId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function normalizedReference(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function validationRules(?self $passage = null): array
    {
        $uniqueRule = Rule::unique('scripture_passages');
        if ($passage) {
            $uniqueRule->ignore($passage->id);
        }

        $trimmedTextRule = new class implements ValidationRule
        {
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
            'bible_id' => ['required', 'string', 'max:255', $trimmedTextRule, $uniqueRule->where('normalized_reference', request('normalized_reference'))],
            'normalized_reference' => ['required', 'string', 'max:255', $trimmedTextRule],
            'html_content' => ['required', 'string'],
            'copyright' => ['required', 'string'],
            'fetched_at' => ['required', 'date'],
        ];
    }

    /**
     * @return HasMany<Sermon, $this>
     */
    public function sermons(): HasMany
    {
        return $this->hasMany(Sermon::class);
    }

    public function isStale(): bool
    {
        $refreshAfterDays = (int) config('services.api_bible.refresh_after_days', 28);

        return $this->fetched_at->diffInDays(now()) >= $refreshAfterDays;
    }
}
