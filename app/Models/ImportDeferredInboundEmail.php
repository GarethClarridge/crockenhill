<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportDeferredInboundEmail extends Model
{
    protected $fillable = [
        'operation_id',
        'inbound_email_id',
        'state',
        'dispatch_attempts',
        'deferred_at',
        'dispatched_at',
        'processed_at',
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
            'dispatched_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
