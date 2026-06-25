<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * App\Models\PreacherAlias
 *
 * @property int $id
 * @property int $preacher_id
 * @property string $alias
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 *
 * @mixin \Eloquent
 */
class PreacherAlias extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'preacher_id',
        'alias',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'preacher_id' => 'integer',
        ];
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $alias = null): array
    {
        $uniqueAlias = Rule::unique('preacher_aliases', 'alias');

        if ($alias) {
            $uniqueAlias->ignore($alias->id);
        }

        return [
            'preacher_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'exists:preachers,id'],
            'alias' => ['required', 'string', 'max:255', $uniqueAlias],
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function alias(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Preacher, $this>
     */
    public function preacher(): BelongsTo
    {
        return $this->belongsTo(Preacher::class);
    }
}
