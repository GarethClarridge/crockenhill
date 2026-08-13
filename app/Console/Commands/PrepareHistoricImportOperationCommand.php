<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\HistoricImportOperationIdentity;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportRuntimePreflight;
use App\Services\Import\HistoricImportTargetFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic archive operation has closed and its acceptance
 * and rollback-retention windows have expired.
 */
class PrepareHistoricImportOperationCommand extends Command
{
    protected $signature = 'historic-import:prepare-operation
        {batch? : Immutable approved batch key}
        {plan-hash? : Approved operation plan SHA-256}
        {--anchors : Read-only: report this host'."'".'s resource anchors and exit without touching anything}
        {--manifest=* : Exact source=sha256 manifest binding; repeat for every source}
        {--runtime-evidence= : Exact definitive-runtime evidence JSON}
        {--deadline= : Accepted ISO-8601 operation deadline}
        {--max-cost= : Accepted maximum cost in minor currency units}';

    protected $description = 'Create or resolve the immutable target-bound historic import operation';

    public function handle(
        HistoricImportTargetFingerprint $target,
        HistoricImportRuntimePreflight $runtime,
        HistoricImportJournal $journal,
    ): int {
        if ($this->option('anchors')) {
            return $this->reportAnchors(
                app(HistoricImportResourceIdentity::class),
                app(HistoricImportProductionGuard::class),
            );
        }

        try {
            if (! is_string($this->argument('batch')) || ! is_string($this->argument('plan-hash'))) {
                throw new RuntimeException(
                    'Historic import operation requires the batch key and plan hash arguments, or --anchors to report resource identity only.',
                );
            }

            $deadline = Carbon::parse((string) $this->option('deadline'));
            $maxCost = filter_var($this->option('max-cost'), FILTER_VALIDATE_INT);

            if ($deadline->isPast() || ! is_int($maxCost) || $maxCost < 0) {
                throw new RuntimeException('Historic import operation requires a future --deadline and non-negative --max-cost.');
            }

            $identity = HistoricImportOperationIdentity::fromBindings(
                batchKey: trim((string) $this->argument('batch')),
                manifestHashes: $this->manifestHashes(),
                planHash: strtolower(trim((string) $this->argument('plan-hash'))),
                targetFingerprint: $target->hash(),
                runtimeFingerprint: $runtime->fingerprint($this->runtimeEvidence()),
            );
            $operation = HistoricImportOperation::query()->firstOrCreate(
                ['binding_hash' => $identity->bindingHash],
                [
                    ...$identity->toArray(),
                    'state' => HistoricImportOperationState::Planned,
                    'accepted_deadline' => $deadline,
                    'max_cost_minor_units' => $maxCost,
                ],
            );

            if (! $operation->wasRecentlyCreated
                && ($operation->accepted_deadline?->toIso8601String() !== $deadline->toIso8601String()
                    || $operation->max_cost_minor_units !== $maxCost)) {
                throw new RuntimeException('Existing immutable operation has different deadline or cost authority.');
            }

            if ($operation->wasRecentlyCreated) {
                $journal->append($operation, 'operation_prepared', [
                    'binding_hash' => $operation->binding_hash,
                    'target_fingerprint' => $operation->target_fingerprint,
                    'accepted_deadline' => $deadline->toIso8601String(),
                    'max_cost_minor_units' => $maxCost,
                ]);
            }

            $this->info("Operation ID: {$operation->operation_id}");
            $this->line("Target fingerprint: {$operation->target_fingerprint}");
            $this->line("Runtime fingerprint: {$operation->runtime_fingerprint}");
            $this->line("Binding hash: {$operation->binding_hash}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * The read-only anchor diagnostic HIR1 requires, on the surface that
     * already reports the operation's fingerprints rather than as a throwaway
     * command of its own.
     *
     * Prints hashes and a verdict only. The underlying material — server
     * identity, schema name, bucket, endpoint, local root — is deliberately
     * absent, because this output is what an operator pastes into a plan, a
     * ticket or a chat window.
     *
     * The verdict is three-valued on purpose. `unknown` covers an unconfigured
     * or unobservable anchor, and must never be read as `rehearsal`: not
     * knowing whether a target is production is a different state from knowing
     * it is not.
     */
    private function reportAnchors(
        HistoricImportResourceIdentity $resources,
        HistoricImportProductionGuard $guard,
    ): int {
        $configurationError = $guard->anchorConfigurationError();

        if ($configurationError !== null) {
            $this->error($configurationError);

            return self::FAILURE;
        }

        try {
            $observed = ['database' => $resources->databaseAnchor(), 'storage' => $resources->storageAnchor()];
        } catch (Throwable $exception) {
            $this->error("Resource anchors cannot be observed on this host: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $storageMatch = $guard->matchesProductionStorageAnchor();

        $this->line("Observed database anchor: {$observed['database']}");
        $this->line("Observed storage anchor:  {$observed['storage']}");
        $this->newLine();
        $this->line('Verdict: '.($guard->guardsCurrentEnvironment() ? 'production' : 'rehearsal'));
        $this->line('Storage anchor: '.match ($storageMatch) {
            true => 'matches production (recorded only — HIR-D2 means this does not arm the guard)',
            false => 'does not match production',
            null => 'unknown (unconfigured or unobservable)',
        });

        if ($storageMatch === true && ! $guard->guardsCurrentEnvironment()) {
            $this->newLine();
            $this->warn('This is a rehearsal target writing to the production public store.');
            $this->warn('That is the accepted HIR-D2 residual risk; a release from here would publish to production.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function manifestHashes(): array
    {
        $values = $this->option('manifest');
        $hashes = [];

        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                throw new RuntimeException('Every --manifest must use source=sha256.');
            }

            [$source, $hash] = explode('=', $value, 2);
            $source = trim($source);
            $hash = strtolower(trim($hash));

            if ($source === '' || isset($hashes[$source]) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw new RuntimeException('Historic import manifest bindings must be unique source=sha256 values.');
            }

            $hashes[$source] = $hash;
        }

        if ($hashes === []) {
            throw new RuntimeException('Historic import operation requires at least one --manifest binding.');
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /** @return array<string, mixed> */
    private function runtimeEvidence(): array
    {
        $path = $this->option('runtime-evidence');

        if (! is_string($path) || trim($path) === '' || ! is_file(trim($path))) {
            throw new RuntimeException('Historic import operation requires --runtime-evidence.');
        }

        $evidence = json_decode((string) file_get_contents(trim($path)), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($evidence)) {
            throw new RuntimeException('Historic import runtime evidence must be a JSON object.');
        }

        return $evidence;
    }
}
