<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
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
    public function isBlocked(): bool
    {
        return $this->active() instanceof ImportIngressLock;
    }

    public function active(): ?ImportIngressLock
    {
        return ImportIngressLock::query()->active()->first();
    }

    /**
     * Refuse new import work for the duration of one operation.
     *
     * The unique index on `is_active` means a second concurrent block fails at
     * the database rather than quietly producing two locks that release
     * independently.
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

            $lock->forceFill([
                'released_at' => now(),
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
    public function dispatchDeferredInboundEmail(): int
    {
        $dispatched = 0;

        InboundEmail::query()
            ->where('status', InboundEmailStatus::Pending)
            ->orderBy('id')
            ->each(function (InboundEmail $email) use (&$dispatched): void {
                ProcessInboundOosEmail::dispatch($email);
                $dispatched++;
            });

        return $dispatched;
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
