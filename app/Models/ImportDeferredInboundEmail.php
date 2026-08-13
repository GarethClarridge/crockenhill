<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order of service that arrived while an import window held ingress closed.
 *
 * HIR6's state contract:
 *
 * ```text
 * pending ──claim──> dispatching ──dispatch accepted──> dispatched ──job success──> processed
 *    ^                    │                                  │
 *    └── dispatch fail ───┘                                  └── job fail ──┘
 *    └──────────── expired claim / confirmed lost job reconciliation ───────┘
 * ```
 *
 * Only `processed` with a non-null `processed_at` is terminal. `dispatched`
 * means the queue accepted the job and nothing more.
 *
 * @property int $id
 * @property string $operation_id
 * @property int $inbound_email_id
 * @property string $state
 * @property string|null $dispatch_token
 * @property CarbonImmutable|null $dispatch_claimed_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property int $dispatch_attempts
 * @property CarbonImmutable $deferred_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $last_failed_at
 * @property string|null $last_error
 * @property int $failure_count
 * @property-read InboundEmail|null $inboundEmail
 */
class ImportDeferredInboundEmail extends Model
{
    public const string StatePending = 'pending';

    public const string StateDispatching = 'dispatching';

    public const string StateDispatched = 'dispatched';

    public const string StateProcessed = 'processed';

    /** Every state a row can hold, oldest first. */
    public const array States = [
        self::StatePending,
        self::StateDispatching,
        self::StateDispatched,
        self::StateProcessed,
    ];

    protected $fillable = [
        'operation_id',
        'inbound_email_id',
        'state',
        'dispatch_token',
        'dispatch_claimed_at',
        'lease_expires_at',
        'dispatch_attempts',
        'deferred_at',
        'dispatched_at',
        'processed_at',
        'last_failed_at',
        'last_error',
        'failure_count',
    ];

    /** @return BelongsTo<InboundEmail, $this> */
    public function inboundEmail(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class);
    }

    /** @return BelongsTo<ImportIngressLock, $this> */
    public function ingressLock(): BelongsTo
    {
        return $this->belongsTo(ImportIngressLock::class, 'operation_id', 'operation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deferred_at' => 'immutable_datetime',
            'dispatch_claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
            'failure_count' => 'integer',
            'dispatch_attempts' => 'integer',
        ];
    }
}
