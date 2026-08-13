<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Services\Import\HorizonPauseAccounting;
use App\Services\Import\ImportIngressGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator control for §15.2's production import window.
 *
 * Blocking ingress is not the same as taking the site down: public reads stay
 * online, and `artisan down` remains prohibited for this operation unless
 * separately approved.
 */
class ImportIngressCommand extends Command
{
    protected $signature = 'import:ingress
        {action : block, release, drain or status}
        {--operation= : The operation id this window runs under}
        {--reason= : Why ingress is blocked, recorded for the closeout report}
        {--by= : The operator blocking ingress}';

    protected $description = 'Block, release, drain or report the import ingress lock for a production import window';

    public function __construct(
        private readonly HorizonPauseAccounting $pauseAccounting,
    ) {
        parent::__construct();
    }

    public function handle(ImportIngressGate $gate): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'block' => $this->block($gate),
            'release' => $this->release($gate),
            'drain' => $this->drain($gate),
            'status' => $this->status($gate),
            default => $this->invalidAction($action),
        };
    }

    private function block(ImportIngressGate $gate): int
    {
        $operationId = (string) $this->option('operation');
        $reason = (string) $this->option('reason');

        if ($operationId === '' || $reason === '') {
            $this->error('Blocking ingress requires --operation and --reason; both are recorded in the closeout report.');

            return self::FAILURE;
        }

        try {
            $lock = $gate->block($operationId, $reason, $this->option('by'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Import ingress blocked by operation {$lock->operation_id}.");
        $this->line('New media-processing and archive-import submissions are refused. Public reads are unaffected.');
        $this->line('Inbound order-of-service email is still staged durably and will process on release.');

        $this->reportQueuePause($lock);

        return self::SUCCESS;
    }

    /**
     * §15.2's fourth ingress requirement. Blocking submissions is only half of
     * "ingress blocked" — the affected queues have to stop too, and this
     * application cannot stop them without also stopping `default`. Naming the
     * supervisors and the collateral here means the operator pauses the right
     * things and knows, before the window opens, what else they are stopping.
     */
    private function reportQueuePause(ImportIngressLock $lock): void
    {
        $accounting = $lock->queue_pause_accounting ?? [];

        /** @var array<string, list<string>> $supervisors */
        $supervisors = $accounting['supervisors_to_pause'] ?? [];

        if ($supervisors === []) {
            return;
        }

        $this->newLine();
        $this->line('Pause the affected Horizon supervisors now:');

        foreach (array_keys($supervisors) as $supervisor) {
            $this->line("  php artisan horizon:pause-supervisor {$supervisor}");
        }

        if (($accounting['queue_granular_pause_possible'] ?? false) === true) {
            $this->line($this->pauseAccounting->summarise($accounting));

            return;
        }

        $this->newLine();
        $this->warn($this->pauseAccounting->summarise($accounting));
    }

    private function release(ImportIngressGate $gate): int
    {
        $operationId = (string) $this->option('operation');

        if ($operationId === '') {
            $this->error('Releasing ingress requires --operation, so a window cannot be reopened by mistake.');

            return self::FAILURE;
        }

        try {
            $lock = $gate->release($operationId);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $minutes = (int) $lock->blocked_at->diffInMinutes($lock->released_at);
        $deferred = $gate->dispatchDeferredInboundEmail($operationId);

        $this->info("Import ingress released after {$minutes} minute(s).");

        if ($deferred > 0) {
            $this->line("Queued {$deferred} order-of-service email(s) staged during the window.");
        }

        $this->line('Record this against the accepted maximum_import_ingress_blocked_minutes budget.');

        $accounting = $lock->queue_pause_accounting ?? [];

        if ($accounting !== []) {
            $this->newLine();
            $this->line('Resume the paused Horizon supervisors: php artisan horizon:continue-supervisor <name>');
            $this->line($this->pauseAccounting->summarise($accounting));
        }

        return self::SUCCESS;
    }

    /**
     * HIR6. A released window can retry its own outbox without reopening
     * anything: `drain` re-claims whatever is claimable — rows still pending,
     * and rows a dead drain abandoned mid-claim — and never touches the ordinary
     * inbox. Idempotent, so running it twice is not a second import.
     */
    private function drain(ImportIngressGate $gate): int
    {
        $operationId = (string) $this->option('operation');

        if ($operationId === '') {
            $this->error('Draining the deferred outbox requires --operation, so one window cannot drain another.');

            return self::FAILURE;
        }

        try {
            $dispatched = $gate->dispatchDeferredInboundEmail($operationId);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued {$dispatched} deferred order-of-service email(s) for operation {$operationId}.");
        $this->reportDeferredOutbox($gate, $operationId);
        $this->line('Closeout stays blocked until every row is processed; a queued job is not a finished one.');

        return self::SUCCESS;
    }

    /**
     * Exact per-state counts, plus the oldest lease still outstanding — what an
     * operator needs to tell "still working" from "stuck".
     */
    private function reportDeferredOutbox(ImportIngressGate $gate, string $operationId): void
    {
        $counts = $gate->deferredInboundStateCounts($operationId);

        if (array_sum($counts) === 0) {
            return;
        }

        $this->newLine();
        $this->table(
            ['State', 'Rows'],
            array_map(
                static fn (string $state, int $count): array => [$state, (string) $count],
                array_keys($counts),
                $counts,
            ),
        );

        $oldestLease = ImportDeferredInboundEmail::query()
            ->where('operation_id', $operationId)
            ->where('state', ImportDeferredInboundEmail::StateDispatching)
            ->min('lease_expires_at');

        if ($oldestLease !== null) {
            $this->line("Oldest outstanding claim lease expires: {$oldestLease}");
        }
    }

    private function status(ImportIngressGate $gate): int
    {
        $lock = $gate->active();

        if (! $lock instanceof ImportIngressLock) {
            $this->info('Import ingress is open.');

            return self::SUCCESS;
        }

        $this->warn("Import ingress is blocked by operation {$lock->operation_id}.");
        $this->line("Reason: {$lock->reason}");
        $this->line("Blocked for: {$gate->blockedMinutes()} minute(s)");
        $this->reportDeferredOutbox($gate, $lock->operation_id);

        $accounting = $lock->queue_pause_accounting ?? [];

        if ($accounting !== []) {
            $this->line($this->pauseAccounting->summarise($accounting));
        }

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action '{$action}'. Use block, release or status.");

        return self::FAILURE;
    }
}
