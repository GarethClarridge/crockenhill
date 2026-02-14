<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\PreacherAlias
 *
 * @property int $id
 * @property int $preacher_id
 * @property string $alias
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
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
     * @return BelongsTo<Preacher, $this>
     */
    public function preacher(): BelongsTo
    {
        return $this->belongsTo(Preacher::class);
    }
}
