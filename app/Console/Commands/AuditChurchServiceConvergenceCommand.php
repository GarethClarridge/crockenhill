<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\HistoricConvergenceCloseout;
use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\Import\HistoricImportOperationCloseout;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Recurring read-only verification for reviewed service convergence bundles.
 */
class AuditChurchServiceConvergenceCommand extends Command
{
    protected $signature = 'service-tracking:audit-convergence
        {bundle : Private Bundle B JSON file}
        {--media-bundle= : Optional private Bundle A JSON file for exact media-graph and asset auditing}
        {--operation-id= : Record this audit as a closeout measurement against the named operation}
        {--report= : Optional JSON report path below storage/app/private}
        {--verify-closeout : Verify the previously persisted report and ledger binding for this operation}';

    protected $description = 'Compare production church services with an exact reviewed convergence bundle';

    public function handle(
        ChurchServiceConvergenceAuditor $auditor,
        HistoricConvergenceLedger $ledger,
        HistoricConvergenceCloseout $closeout,
        HistoricImportOperationCloseout $operationCloseout,
    ): int {
        try {
            $bundle = $this->readBundle((string) $this->argument('bundle'));
            $mediaBundle = $this->mediaBundle();
            $operationId = $this->operationId();
            $reportPath = $this->reportPath();

            if ($operationId !== null && ($mediaBundle === null || $reportPath === null)) {
                throw new RuntimeException('Operation closeout requires exact Bundle A and an immutable private report path.');
            }

            $auditStartedAt = hrtime(true);
            $report = $auditor->audit($bundle, $mediaBundle);
            $binding = null;

            if ($this->option('verify-closeout')) {
                if ($operationId === null || $mediaBundle === null || $reportPath === null) {
                    throw new RuntimeException('--verify-closeout requires --operation-id.');
                }

                if ($report['passed'] !== true) {
                    throw new RuntimeException('Current exact convergence audit failed; operation closeout remains incomplete.');
                }

                $binding = $this->recordedBinding($ledger, $operationId, $mediaBundle, $bundle);
                $closeout->verifyRecordedReport(
                    $operationId,
                    $binding,
                    storage_path('app/private'),
                    $reportPath,
                );
                $event = collect($ledger->entries($operationId))->last(
                    static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
                );

                if (! is_array($event) || ! is_string($event['report_digest'] ?? null)) {
                    throw new RuntimeException('Exact closeout report event is incomplete.');
                }

                $operationCloseout->complete($operationId, [
                    ...$binding,
                    'report_digest' => $event['report_digest'],
                ]);
                $this->info('Recorded exact closeout report and operation binding verified.');
            } elseif ($operationId !== null && $mediaBundle !== null) {
                $binding = $closeout->binding($operationId, $mediaBundle, $bundle);
            }

            if ($binding !== null) {
                $report['operation_closeout'] = [
                    'operation_id' => $operationId,
                    ...$binding,
                ];
            }

            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            if ($reportPath !== null && ! $this->option('verify-closeout')) {
                $digest = $this->writeReport($reportPath, $json, $operationId !== null);
                $this->line("Audit report: {$reportPath}");

                if ($operationId !== null && $report['passed'] === true && $binding !== null) {
                    $ledger->recordExactAuditPassed(
                        $operationId,
                        round((hrtime(true) - $auditStartedAt) / 1_000_000_000, 6),
                        $binding,
                        $this->reportLocator($reportPath),
                        $digest,
                    );
                }
            } elseif (! $this->option('verify-closeout')) {
                $this->line($json);
            }
        } catch (Throwable $exception) {
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

    private function operationId(): ?string
    {
        $operationId = $this->option('operation-id');

        if (! is_string($operationId) || trim($operationId) === '') {
            return null;
        }

        return trim($operationId);
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

    private function writeReport(string $path, string $json, bool $immutable): string
    {
        if ($immutable && file_exists($path)) {
            throw new RuntimeException('The operation closeout report already exists and is immutable.');
        }

        $temporary = tempnam(dirname($path), '.historic-closeout-');

        if (! is_string($temporary)) {
            throw new RuntimeException('The convergence audit temporary report could not be created.');
        }

        $handle = fopen($temporary, 'c+b');

        if ($handle === false) {
            @unlink($temporary);
            throw new RuntimeException('The convergence audit report could not be opened.');
        }

        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('The convergence audit report permissions could not be restricted.');
            }

            $remaining = $json.PHP_EOL;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('The convergence audit report write was incomplete.');
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('The convergence audit report could not be flushed durably.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        $committed = $immutable ? link($temporary, $path) : rename($temporary, $path);

        if (! $committed) {
            @unlink($temporary);
            throw new RuntimeException('The convergence audit report could not be committed atomically.');
        }

        if ($immutable) {
            @unlink($temporary);
        }

        $digest = hash_file('sha256', $path);

        if (! is_string($digest)) {
            throw new RuntimeException('The convergence audit report could not be verified after writing.');
        }

        return $digest;
    }

    private function reportLocator(string $path): string
    {
        $root = rtrim((string) realpath(storage_path('app/private')), '/').'/';
        $resolved = realpath($path);

        if (! is_string($resolved) || ! str_starts_with($resolved, $root)) {
            throw new RuntimeException('The convergence audit report durable locator is invalid.');
        }

        return substr($resolved, strlen($root));
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array<string, mixed>
     */
    private function recordedBinding(
        HistoricConvergenceLedger $ledger,
        string $operationId,
        array $mediaBundle,
        array $convergenceBundle,
    ): array {
        $event = collect($ledger->entries($operationId))->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        );

        if (! is_array($event)) {
            throw new RuntimeException('Exact closeout has no passed durable audit event.');
        }

        $binding = array_intersect_key($event, array_flip([
            'batch_hash', 'media_bundle_hash', 'convergence_bundle_hash',
            'processing_fingerprint_hash', 'target_fingerprint', 'plan_hash',
            'content_hash', 'identity_hash', 'service_count',
        ]));

        if (($binding['batch_hash'] ?? null) !== ($mediaBundle['batch_hash'] ?? null)
            || ($binding['media_bundle_hash'] ?? null) !== ($mediaBundle['bundle_hash'] ?? null)
            || ($binding['convergence_bundle_hash'] ?? null) !== ($convergenceBundle['bundle_hash'] ?? null)
            || ($binding['processing_fingerprint_hash'] ?? null)
                !== CanonicalJson::hash($mediaBundle['processing_fingerprint'] ?? null)) {
            throw new RuntimeException('Recorded closeout bundles do not match the supplied exact bundles.');
        }

        return $binding;
    }
}
