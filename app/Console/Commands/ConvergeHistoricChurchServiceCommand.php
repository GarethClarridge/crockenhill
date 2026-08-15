<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\HistoricConvergenceOperationPlan;
use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\Import\HistoricImportProductionGuard;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary R8 production one-shot, now run as IC2/IC7 incremental convergence
 * rounds rather than a single window. Delete when historic closeout is reached
 * (docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §3.3): every
 * lane's audit report at 100% disposed membership and final releases done.
 */
class ConvergeHistoricChurchServiceCommand extends Command
{
    protected $signature = 'service-tracking:converge-historic-service
        {media-bundle : Private Bundle A JSON file}
        {convergence-bundle : Private Bundle B JSON file}
        {--media-index= : Zero-based Bundle A service index (omit for a complete batch)}
        {--convergence-index= : Zero-based Bundle B service index (omit for a complete batch)}
        {--all : Prepare and apply every matching service in manifest order}
        {--operation-id= : Stable operation identifier from an approved rehearsal}
        {--expires-at= : ISO-8601 plan expiry from the approved rehearsal}
        {--resume : Resume services completed in the private operation ledger}
        {--apply : Execute the atomic database and asset operation}
        {--plan-hash= : Exact hash printed by the dry run}';

    protected $description = 'Preflight or atomically apply one historic church-service convergence';

    public function handle(
        HistoricProcessingResultBundle $mediaBundles,
        ChurchServiceConvergenceBundle $convergenceBundles,
        ConvergeHistoricChurchService $converge,
        HistoricImportProductionGuard $productionGuard,
    ): int {
        try {
            $mediaBundle = $mediaBundles->validate($this->readBundle((string) $this->argument('media-bundle')));
            $convergenceBundle = $convergenceBundles->validate(
                $this->readBundle((string) $this->argument('convergence-bundle')),
            );
            $operationId = $this->stringOption('operation-id');
            $expiresAt = $this->stringOption('expires-at');
            $mediaIndexOption = $this->stringOption('media-index');
            $convergenceIndexOption = $this->stringOption('convergence-index');
            $all = (bool) $this->option('all') || ($mediaIndexOption === null && $convergenceIndexOption === null);

            if ($all && ($mediaIndexOption !== null || $convergenceIndexOption !== null)) {
                throw new RuntimeException('Do not combine --all with a service index.');
            }

            if (! $all && ($mediaIndexOption === null || $convergenceIndexOption === null)) {
                throw new RuntimeException('--media-index and --convergence-index must be supplied together.');
            }

            if ($this->option('apply') && $expiresAt === null) {
                throw new RuntimeException('--apply requires --expires-at from the approved dry run.');
            }

            $mediaIndex = $all ? 0 : $this->index('media-index');
            $convergenceIndex = $all ? 0 : $this->index('convergence-index');
            $plan = $all
                ? $converge->prepareBatch($mediaBundle, $convergenceBundle, $operationId, $expiresAt)
                : $converge->prepare(
                    $mediaBundle,
                    $convergenceBundle,
                    $mediaIndex,
                    $convergenceIndex,
                    $operationId,
                    $expiresAt,
                );

            if (! $this->option('apply')) {
                $this->info('Historic church-service convergence bundle pair is valid.');
                $this->line("Operation ID: {$plan->operationId}");
                $this->line("Plan hash: {$plan->planHash}");
                $this->line("Expires at: {$plan->expiresAt->format(DATE_ATOM)}");
                $this->line('No database records or destination assets were changed.');
                $this->newLine();
                $this->line('Apply this exact plan with:');
                $this->line($this->applyInvocation($plan, $all, $mediaIndex, $convergenceIndex));
                $this->line('All three of --operation-id, --expires-at and --plan-hash are required: the');
                $this->line('token binds the expiry, so omitting one mints a different plan and is refused.');

                return self::SUCCESS;
            }

            /**
             * The RG-B production apply itself (IC2 re-scoped this from the
             * one-shot G8 window to per-round approval). Guarding it with the
             * same switch is not belt-and-braces: it is what turns "approved for
             * this round" from a sentence in the plan into a precondition the
             * run cannot skip, and it gives the round audit report a recorded
             * authority to quote. The batch and plan hashes bind the approval to
             * this exact corpus and reviewed plan, not merely this operation id.
             */
            $productionRefusal = $productionGuard->refusalFor(
                'service-tracking:converge-historic-service --apply',
                $plan->operationId,
                roundCorpusHash: $plan->batchHash,
                planHash: $plan->planHash,
            );

            if ($productionRefusal !== null) {
                throw new RuntimeException($productionRefusal);
            }

            $this->assertPlanHash($plan->planHash);

            if ($all) {
                $batch = $converge->executeBatch(
                    $mediaBundle,
                    $convergenceBundle,
                    $plan->planHash,
                    (bool) $this->option('resume'),
                    $plan->operationId,
                    $plan->expiresAt->format(DATE_ATOM),
                    $plan,
                );
                $applied = $batch->applied;
                $held = $batch->held;
            } else {
                $applied = [$converge->execute(
                    $mediaBundle,
                    $convergenceBundle,
                    $mediaIndex,
                    $convergenceIndex,
                    $plan->planHash,
                    $plan,
                )];
                $held = [];
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Historic church-service convergence completed.');
        $this->line('Services applied: '.count($applied));

        foreach ($applied as $serviceResult) {
            $service = $serviceResult['church_service'];
            $this->line("Converged {$service->date->toDateString()} {$service->service->value}.");
            $this->line("Canonical hash: {$service->canonical_hash}");
            $this->line('Created assets: '.count($serviceResult['created_assets']));
        }

        /**
         * IC2: a held service is corpus-completeness residue, not a command
         * failure — it is reported here rather than refusing the services that
         * were applicable alongside it.
         */
        if ($held !== []) {
            $this->line('Services held: '.count($held));

            foreach ($held as $entry) {
                $this->line("Held {$entry['identity']}: {$entry['reason']}.");
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Bundle file is missing: {$path}");
        }

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($bundle)) {
            throw new RuntimeException("Bundle must contain a JSON object: {$path}");
        }

        return $bundle;
    }

    private function index(string $option): int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 0) {
            throw new RuntimeException("--{$option} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * The operator has to carry the operation identifier and expiry forward by
     * hand, because the plan hash binds them. Printing the exact invocation is
     * cheaper than a support round trip after a refused apply.
     */
    private function applyInvocation(
        HistoricConvergenceOperationPlan $plan,
        bool $all,
        int $mediaIndex,
        int $convergenceIndex,
    ): string {
        $scope = $all
            ? '--all'
            : "--media-index={$mediaIndex} --convergence-index={$convergenceIndex}";

        return implode(' ', [
            '  php artisan service-tracking:converge-historic-service',
            escapeshellarg((string) $this->argument('media-bundle')),
            escapeshellarg((string) $this->argument('convergence-bundle')),
            $scope,
            "--operation-id={$plan->operationId}",
            "--expires-at={$plan->expiresAt->format(DATE_ATOM)}",
            "--plan-hash={$plan->planHash}",
            '--apply',
        ]);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function assertPlanHash(string $expected): void
    {
        $actual = $this->option('plan-hash');

        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('--apply requires the exact --plan-hash printed by the dry run.');
        }
    }
}
