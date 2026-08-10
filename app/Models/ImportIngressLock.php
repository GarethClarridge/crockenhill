<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportIngressLockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A held or historic block on new import work. See §15.2 of the historic archive
 * readiness plan: production windows refuse fresh media and archive-import
 * submissions rather than racing them.
 *
 * @property int $id
 * @property string $operation_id
 * @property string $reason
 * @property string|null $blocked_by
 * @property Carbon $blocked_at
 * @property Carbon|null $released_at
 * @property array<string, mixed>|null $queue_pause_accounting
 * @property int|null $is_active
 */
class ImportIngressLock extends Model
{
    /** @use HasFactory<ImportIngressLockFactory> */
    use HasFactory;

    protected $fillable = [
        'operation_id',
        'reason',
        'blocked_by',
        'blocked_at',
        'released_at',
        'queue_pause_accounting',
        'is_active',
    ];

    /** @param Builder<ImportIngressLock> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    /** @return HasMany<ImportDeferredInboundEmail, $this> */
    public function deferredInboundEmails(): HasMany
    {
        return $this->hasMany(ImportDeferredInboundEmail::class, 'operation_id', 'operation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'released_at' => 'datetime',
            'queue_pause_accounting' => 'array',
        ];
    }
}
