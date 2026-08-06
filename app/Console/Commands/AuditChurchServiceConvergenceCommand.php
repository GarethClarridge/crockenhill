<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\HistoricConvergenceLedger;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Recurring read-only verification for reviewed service convergence bundles.
 */
class AuditChurchServiceConvergenceCommand extends Command
{
    protected $signature = 'service-tracking:audit-convergence
        {bundle : Private Bundle B JSON file}
        {--media-bundle= : Optional private Bundle A JSON file for exact media-graph and asset auditing}
        {--operation-id= : Record this audit as a closeout measurement against the named operation}
        {--report= : Optional JSON report path below storage/app/private}';

    protected $description = 'Compare production church services with an exact reviewed convergence bundle';

    public function handle(ChurchServiceConvergenceAuditor $auditor, HistoricConvergenceLedger $ledger): int
    {
        try {
            $bundle = $this->readBundle((string) $this->argument('bundle'));
            $mediaBundle = $this->mediaBundle();
            $auditStartedAt = hrtime(true);
            $report = $auditor->audit($bundle, $mediaBundle);
            $this->recordCloseout($ledger, $auditStartedAt, (bool) $report['passed']);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $reportPath = $this->reportPath();

            if ($reportPath !== null) {
                $this->writeReport($reportPath, $json);
                $this->line("Audit report: {$reportPath}");
            } else {
                $this->line($json);
            }
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $report['passed']) {
            $this->error("Convergence audit failed for {$report['totals']['failed']} service(s).");

            return self::FAILURE;
        }

        $this->info("Convergence audit passed for {$report['totals']['passed']} service(s).");

        return self::SUCCESS;
    }

    /**
     * §15.2's closeout reserve is the exact audit plus the no-op rerun that must
     * still fit inside the window after the last service commits. It is only
     * recorded when an operation is named, so a routine recurring audit — this
     * command's normal use — does not add samples to a window budget it has
     * nothing to do with.
     */
    private function recordCloseout(HistoricConvergenceLedger $ledger, int $startedAt, bool $passed): void
    {
        $operationId = $this->option('operation-id');

        if (! is_string($operationId) || trim($operationId) === '') {
            return;
        }

        $ledger->recordCloseout(
            trim($operationId),
            'exact_audit',
            round((hrtime(true) - $startedAt) / 1_000_000_000, 6),
            $passed,
        );
    }

    /** @return array<string, mixed>|null */
    private function mediaBundle(): ?array
    {
        $option = $this->option('media-bundle');

        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        return $this->readBundle(trim($option));
    }

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The convergence bundle is missing.');
        }

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($bundle)) {
            throw new RuntimeException('The convergence bundle must contain a JSON object.');
        }

        return $bundle;
    }

    private function reportPath(): ?string
    {
        $option = $this->option('report');

        if (! is_string($option) || $option === '') {
            return null;
        }

        $privateRoot = storage_path('app/private');
        $path = str_starts_with($option, '/')
            ? $option
            : "{$privateRoot}/{$option}";
        $parent = realpath(dirname($path));
        $resolvedRoot = realpath($privateRoot);

        if ($parent === false || $resolvedRoot === false || ! str_starts_with($parent.'/', $resolvedRoot.'/')) {
            throw new RuntimeException('The audit report must be written below storage/app/private.');
        }

        return $path;
    }

    private function writeReport(string $path, string $json): void
    {
        if (file_put_contents($path, $json.PHP_EOL) === false) {
            throw new RuntimeException('The convergence audit report could not be written.');
        }

        @chmod($path, 0600);
    }
}
