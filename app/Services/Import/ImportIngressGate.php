<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Jobs\ProcessInboundOosEmail;
use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
            [
                'state' => ImportDeferredInboundEmail::StatePending,
                'dispatch_attempts' => 0,
                'failure_count' => 0,
                'deferred_at' => now(),
            ],
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
     * Each row is claimed in its own short transaction and dispatched outside
     * it, so no database transaction is ever open across a queue handoff. The
     * claim is what makes a crash recoverable: a row left in `dispatching` with
     * an expired lease is reclaimable, and one still inside its lease is not, so
     * a second drainer never creates a second job for the same email.
     *
     * Idempotent by construction — a repeated drain finds nothing claimable.
     *
     * @return int the number of emails handed to the queue by this drain
     */
    public function dispatchDeferredInboundEmail(string $operationId): int
    {
        $dispatched = 0;

        while (($claimed = $this->claimNextDeferredInboundEmail($operationId)) !== null) {
            [$deferred, $token] = $claimed;
            $email = $deferred->inboundEmail;

            if (! $email instanceof InboundEmail) {
                throw new RuntimeException('Deferred inbound email lost its durable source row.');
            }

            try {
                $this->dispatcher->dispatch(new ProcessInboundOosEmail($email, $deferred->id));
            } catch (Throwable $exception) {
                $this->releaseClaim($deferred->id, $token, $exception);

                throw $exception;
            }

            /**
             * Conditional on still owning the claim. A synchronous dispatcher
             * runs the job inside `dispatch()`, so by the time this executes the
             * row may already be `processed` — in which case this affects zero
             * rows rather than dragging a finished email back to `dispatched`.
             */
            ImportDeferredInboundEmail::query()
                ->whereKey($deferred->id)
                ->where('dispatch_token', $token)
                ->where('state', ImportDeferredInboundEmail::StateDispatching)
                ->update([
                    'state' => ImportDeferredInboundEmail::StateDispatched,
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Take ownership of one claimable row, or return null when there is none.
     *
     * Claimable means pending, or `dispatching` whose lease has expired — the
     * durable trace of a drain that died between claiming and dispatching. The
     * lease outlives the job's own uniqueness window, so a row can never become
     * reclaimable while its job could still run.
     *
     * @return array{ImportDeferredInboundEmail, string}|null
     */
    private function claimNextDeferredInboundEmail(string $operationId): ?array
    {
        return DB::transaction(function () use ($operationId): ?array {
            $deferred = ImportDeferredInboundEmail::query()
                ->with('inboundEmail')
                ->where('operation_id', $operationId)
                ->where(function (Builder $query): void {
                    $query->where('state', ImportDeferredInboundEmail::StatePending)
                        ->orWhere(function (Builder $stale): void {
                            $stale->where('state', ImportDeferredInboundEmail::StateDispatching)
                                ->where('lease_expires_at', '<', now());
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $deferred instanceof ImportDeferredInboundEmail) {
                return null;
            }

            $token = (string) Str::uuid();
            $deferred->forceFill([
                'state' => ImportDeferredInboundEmail::StateDispatching,
                'dispatch_token' => $token,
                'dispatch_claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds(self::leaseSeconds()),
                'dispatch_attempts' => $deferred->dispatch_attempts + 1,
            ])->save();

            return [$deferred, $token];
        });
    }

    /**
     * Hand a claim this drain could not dispatch back to `pending`, with the
     * reason recorded durably so the operator sees a failed drain rather than a
     * row that quietly stopped moving.
     */
    private function releaseClaim(int $deferredId, string $token, Throwable $exception): void
    {
        ImportDeferredInboundEmail::query()
            ->whereKey($deferredId)
            ->where('dispatch_token', $token)
            ->where('state', ImportDeferredInboundEmail::StateDispatching)
            ->update([
                'state' => ImportDeferredInboundEmail::StatePending,
                'dispatch_token' => null,
                'dispatch_claimed_at' => null,
                'lease_expires_at' => null,
                'last_failed_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 480),
                'failure_count' => DB::raw('failure_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * How long a claim owns its row.
     *
     * Derived from the job's own uniqueness window rather than chosen
     * separately: if the lease expired first, a drain could reclaim a row whose
     * job is still queued and dispatch a second one for the same email, which
     * `ShouldBeUnique` would then silently drop — leaving the row claimed by a
     * drain that no longer has a job behind it.
     */
    public static function leaseSeconds(): int
    {
        return ProcessInboundOosEmail::UniqueForSeconds + 3_600;
    }

    /**
     * Exact per-state counts for this operation, every state always present.
     *
     * @return array<string, int>
     */
    public function deferredInboundStateCounts(string $operationId): array
    {
        $counts = array_fill_keys(ImportDeferredInboundEmail::States, 0);

        $observed = ImportDeferredInboundEmail::query()
            ->where('operation_id', $operationId)
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        foreach ($observed as $state => $total) {
            $counts[(string) $state] = (int) $total;
        }

        return $counts;
    }

    /**
     * The processed rows themselves, as membership rather than a claimed count.
     *
     * @return list<array{inbound_email_id: int, processed_at: string}>
     */
    public function processedDeferredInboundMembership(string $operationId): array
    {
        $membership = [];

        $rows = ImportDeferredInboundEmail::query()
            ->where('operation_id', $operationId)
            ->where('state', ImportDeferredInboundEmail::StateProcessed)
            ->whereNotNull('processed_at')
            ->orderBy('inbound_email_id')
            ->get();

        foreach ($rows as $deferred) {
            $membership[] = [
                'inbound_email_id' => $deferred->inbound_email_id,
                'processed_at' => $deferred->processed_at?->toIso8601String() ?? '',
            ];
        }

        return $membership;
    }

    /**
     * HIR6: only `processed` with a `processed_at` is terminal.
     *
     * `dispatched` used to satisfy this, but it records a queue handoff and
     * nothing more — the same durable state as a job still queued, still
     * executing, or about to fail permanently and return the row to `pending`.
     * An operation that closed out on it could have left an order of service
     * that arrived during the freeze unimported.
     */
    public function assertDeferredInboundEmailReconciled(string $operationId): void
    {
        $unfinished = ImportDeferredInboundEmail::query()
            ->where('operation_id', $operationId)
            ->where(function (Builder $query): void {
                $query->where('state', '!=', ImportDeferredInboundEmail::StateProcessed)
                    ->orWhereNull('processed_at');
            })
            ->get();

        if ($unfinished->isEmpty()) {
            return;
        }

        $states = $unfinished
            ->countBy(fn (ImportDeferredInboundEmail $deferred): string => $deferred->state)
            ->map(fn (int $count, string $state): string => "{$state}={$count}")
            ->values()
            ->implode(', ');

        throw new RuntimeException(
            "Import operation still has undrained deferred inbound email ({$states}). "
            .'Only a processed row with a processed_at is finished; drain the outbox with '
            .'`import:ingress drain` and let its jobs run.'
        );
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
