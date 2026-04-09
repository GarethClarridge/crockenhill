<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

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
    /** @use HasFactory<\Database\Factories\ScripturePassageFactory> */
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

    /**
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'bible_id' => ['required', 'string', 'max:255'],
            'normalized_reference' => ['required', 'string', 'max:255'],
            'api_passage_id' => ['nullable', 'string', 'max:255'],
            'display_reference' => ['nullable', 'string', 'max:255'],
            'fums_token' => ['nullable', 'string', 'max:255'],
            'html_content' => ['required', 'string'],
            'copyright' => ['required', 'string'],
            'fetched_at' => ['required', 'date'],
        ];
    }

    /**
     * Validate data against the model's rules.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function validate(array $data): array
    {
        return Validator::make($data, self::validationRules())->validate();
    }
}
