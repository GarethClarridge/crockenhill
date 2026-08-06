<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\HistoricMedia\HistoricPromotionBudget;
use App\Services\HistoricMedia\HistoricPromotionMeasurements;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * §13.4's deterministic-promotion benchmark, reported as §15.2's numeric
 * production-window budget.
 *
 * §13.4 requires the production-shaped rehearsal to benchmark promotion
 * separately from the media pass — per-service p95 apply time, asset-copy
 * throughput, preflight/audit time and rollback recovery — and states plainly
 * that local Whisper and AI throughput are not a proxy for any of them. This
 * command reads those timings from the convergence ledger the operation writes
 * as it runs, so the budget is observed rather than forecast.
 *
 * Unlike audit:service-evidence-coverage, this one *fails* when the budget is
 * not acceptable. G7 accepts numbers; a budget with an unmeasured phase, or one
 * whose window cannot fit a single service, is not something to accept, and
 * exiting 0 would let it pass as though it were.
 */
class HistoricPromotionBudgetCommand extends Command
{
    protected $signature = 'service-tracking:promotion-budget
        {--ledger= : Convergence ledger path (defaults to the private operation ledger)}
        {--operation-id= : Restrict the budget to one operation}
        {--cap-minutes=60 : The accepted maximum_import_ingress_blocked_minutes (§15.2 default is 60)}
        {--json : Emit the full budget as JSON}';

    protected $description = 'Report the measured deterministic-promotion budget for the production window';

    public function __construct(
        private readonly HistoricPromotionMeasurements $measurements,
        private readonly HistoricPromotionBudget $budget,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $capMinutes = $this->capMinutes();
            $entries = $this->ledger()->entries($this->stringOption('operation-id'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $samples = $this->measurements->fromLedgerEntries($entries);
        $report = $this->budget->report(
            applySeconds: $samples['apply_seconds'],
            preflightSeconds: $samples['preflight_seconds'],
            closeoutSeconds: $samples['closeout_seconds'],
            rollbackSeconds: $samples['rollback_seconds'],
            assetBytes: $samples['asset_bytes'],
            assetSeconds: $samples['asset_seconds'],
            acceptedCapMinutes: $capMinutes,
        );

        $report['operations'] = $samples['operations'];
        $report['services_applied'] = $samples['services_applied'];
        $report['services_failed'] = $samples['services_failed'];

        if ((bool) $this->option('json')) {
            // Zero fractions are preserved so a measured 60.0 seconds stays a
            // duration in the artifact G7 accepts, rather than decoding as an
            // integer and inviting a consumer to treat it as a count.
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));

            return $report['acceptable'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderTables($report, $samples);

        if ($report['acceptable']) {
            $this->info('The measured promotion budget fits the accepted window.');

            return self::SUCCESS;
        }

        foreach ($report['missing_measurements'] as $missing) {
            $this->error("Not measured: {$missing}.");
        }

        foreach ($report['warnings'] as $warning) {
            $this->error($warning);
        }

        $this->comment('G7 accepts numeric values from §15.2. Run the rehearsal apply, rollback and closeout so the ledger can supply them.');

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $samples
     */
    private function renderTables(array $report, array $samples): void
    {
        $this->table(['Phase', 'Samples', 'p50 s', 'p95 s', 'max s'], [
            $this->phaseRow('per-service apply', $report['per_service_apply_seconds']),
            $this->phaseRow('batch preflight', $report['preflight_seconds']),
            $this->phaseRow('closeout', $report['closeout_seconds']),
            $this->phaseRow('rollback recovery', $report['rollback_seconds']),
        ]);

        $this->table(['§15.2 window budget', 'Value'], [
            ['accepted cap (minutes)', (string) $report['accepted_cap_minutes']],
            ['preflight reserve (minutes)', $this->number($report['preflight_reserve_minutes'])],
            ['closeout reserve (minutes)', $this->number($report['closeout_reserve_minutes'])],
            ['rollback reserve (minutes)', $this->number($report['rollback_reserve_minutes'])],
            ['applying budget (minutes)', $this->number($report['applying_budget_minutes'])],
            ['services per window', $this->number($report['services_per_window'])],
            ['admission floor (minutes)', $this->number($report['admission_floor_minutes'])],
            ['latest safe start before window end (minutes)', $this->number($report['latest_safe_start_before_window_end_minutes'])],
        ]);

        $this->table(['Observed operation', 'Value'], [
            ['operations in ledger', (string) count($samples['operations'])],
            ['services applied', (string) $samples['services_applied']],
            ['services failed', (string) $samples['services_failed']],
            ['asset bytes written', (string) $report['asset_bytes_written']],
            ['asset copy (MiB/s, floor)', $this->number($report['asset_copy_mib_per_second'])],
        ]);
    }

    /**
     * @param  array{p50: float|null, p95: float|null, max: float|null, count: int}  $percentiles
     * @return list<string>
     */
    private function phaseRow(string $label, array $percentiles): array
    {
        return [
            $label,
            (string) $percentiles['count'],
            $this->number($percentiles['p50']),
            $this->number($percentiles['p95']),
            $this->number($percentiles['max']),
        ];
    }

    private function number(int|float|null $value): string
    {
        return $value === null ? 'not measured' : (string) $value;
    }

    private function capMinutes(): int
    {
        $value = filter_var($this->option('cap-minutes'), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('--cap-minutes must be a positive integer.');
        }

        return $value;
    }

    private function ledger(): HistoricConvergenceLedger
    {
        $path = $this->stringOption('ledger');

        return $path === null
            ? app(HistoricConvergenceLedger::class)
            : new HistoricConvergenceLedger($path);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
