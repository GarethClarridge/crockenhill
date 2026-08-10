<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Jobs\ProcessInboundOosEmail;
use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §15.2. "Ingress blocked" means new media-processing and archive-import
 * submissions are refused and the affected queues are paused. It does **not**
 * mean the site is down: ordinary public reads stay online throughout, and
 * `artisan down` is prohibited for this operation unless separately approved.
 *
 * Losslessness is the constraint that shapes this class. Refusing an
 * authenticated upload is safe, because the operator still holds the file and
 * the response says when to retry. Refusing an inbound webhook is not, because
 * the sender is Mailgun and a refusal risks the order of service being dropped.
 * So the gate distinguishes *admitting new work* from *accepting durable
 * evidence*, and only the former is blocked.
 */
class ImportIngressGate
{
    public function __construct(
        private readonly HorizonPauseAccounting $pauseAccounting,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function isBlocked(): bool
    {
        return $this->active() instanceof ImportIngressLock;
    }

    public function active(): ?ImportIngressLock
    {
        return ImportIngressLock::query()->active()->first();
    }

    public function activeForUpdate(): ?ImportIngressLock
    {
        return ImportIngressLock::query()->active()->lockForUpdate()->first();
    }

    public function deferInboundEmail(ImportIngressLock $lock, InboundEmail $email): ImportDeferredInboundEmail
    {
        if ($lock->released_at !== null || $lock->is_active !== 1) {
            throw new RuntimeException('Inbound email cannot be deferred to a released import window.');
        }

        return ImportDeferredInboundEmail::query()->firstOrCreate(
            ['operation_id' => $lock->operation_id, 'inbound_email_id' => $email->id],
            ['state' => 'pending', 'dispatch_attempts' => 0, 'deferred_at' => now()],
        );
    }

    /**
     * Refuse new import work for the duration of one operation.
     *
     * The unique index on `is_active` means a second concurrent block fails at
     * the database rather than quietly producing two locks that release
     * independently.
     *
     * The queue-pause accounting is captured here rather than at release because
     * the collateral depth at the moment the window opens is only observable
     * then; asking for it afterwards would leave the closeout report with a
     * backlog figure and no baseline to read it against.
     */
    public function block(string $operationId, string $reason, ?string $blockedBy = null): ImportIngressLock
    {
        return DB::transaction(function () use ($operationId, $reason, $blockedBy): ImportIngressLock {
            $existing = $this->active();

            if ($existing instanceof ImportIngressLock) {
                throw new RuntimeException(
                    "Import ingress is already blocked by operation {$existing->operation_id}. Release it before starting another."
                );
            }

            return ImportIngressLock::query()->create([
                'operation_id' => $operationId,
                'reason' => $reason,
                'blocked_by' => $blockedBy,
                'blocked_at' => now(),
                'released_at' => null,
                'queue_pause_accounting' => $this->pauseAccounting->atBlock(),
                'is_active' => 1,
            ]);
        });
    }

    /**
     * Resume ingress. Returns the released lock so a caller can report the
     * window's actual duration against §15.2's accepted budget.
     */
    public function release(string $operationId): ImportIngressLock
    {
        return DB::transaction(function () use ($operationId): ImportIngressLock {
            $lock = ImportIngressLock::query()->active()->lockForUpdate()->first();

            if (! $lock instanceof ImportIngressLock) {
                throw new RuntimeException('Import ingress is not currently blocked.');
            }

            if ($lock->operation_id !== $operationId) {
                throw new RuntimeException(
                    "Import ingress is blocked by operation {$lock->operation_id}, not {$operationId}."
                );
            }

            $releasedAt = now();
            $blockedMinutes = (int) $lock->blocked_at->diffInMinutes($releasedAt);

            $lock->forceFill([
                'released_at' => $releasedAt,
                'queue_pause_accounting' => $this->pauseAccounting->atRelease(
                    $lock->queue_pause_accounting ?? $this->pauseAccounting->atBlock(),
                    $blockedMinutes,
                ),
                'is_active' => null,
            ])->save();

            return $lock;
        });
    }

    /**
     * Dispatch the order-of-service email that arrived while the window was
     * closed. Staging without this would be lossless only in the sense that the
     * rows still exist — §15.2 wants the inbound path whole, so the deferral has
     * to end by itself rather than waiting for someone to notice.
     *
     * Pending is the state the webhook leaves a deferred email in, and the state
     * a redelivered failure is reset to, so it is the correct thing to sweep.
     *
     * @return int the number of emails handed back to the queue
     */
    public function dispatchDeferredInboundEmail(string $operationId): int
    {
        $dispatched = 0;

        ImportDeferredInboundEmail::query()
            ->with('inboundEmail')
            ->where('operation_id', $operationId)
            ->where('state', 'pending')
            ->orderBy('id')
            ->each(function (ImportDeferredInboundEmail $deferred) use (&$dispatched): void {
                $email = $deferred->inboundEmail;

                if (! $email instanceof InboundEmail) {
                    throw new RuntimeException('Deferred inbound email lost its durable source row.');
                }

                $this->dispatcher->dispatch(new ProcessInboundOosEmail($email, $deferred->id));
                $deferred->forceFill([
                    'state' => 'dispatched',
                    'dispatch_attempts' => $deferred->dispatch_attempts + 1,
                    'dispatched_at' => now(),
                ])->save();
                $dispatched++;
            });

        return $dispatched;
    }

    public function assertDeferredInboundEmailReconciled(string $operationId): void
    {
        if (ImportDeferredInboundEmail::query()
            ->where('operation_id', $operationId)
            ->whereNotIn('state', ['dispatched', 'processed'])
            ->exists()) {
            throw new RuntimeException('Import operation still has undrained deferred inbound email.');
        }
    }

    /**
     * How long the current window has been blocking ingress, in whole minutes.
     * §15.2 records a numeric `maximum_import_ingress_blocked_minutes`; this is
     * what an operator or report checks it against.
     */
    public function blockedMinutes(): ?int
    {
        $lock = $this->active();

        if (! $lock instanceof ImportIngressLock) {
            return null;
        }

        return (int) $lock->blocked_at->diffInMinutes(now());
    }
}
