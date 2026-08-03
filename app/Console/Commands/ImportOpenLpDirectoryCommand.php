<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OpenLpImportResult;
use App\Enums\ChurchServiceSource;
use App\Enums\SermonService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use App\Services\ChurchService\OpenLpCurationManifest;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Temporary R8 one-shot. Delete this command and its tests after the production OpenLP
 * convergence apply is ledgered, parity is accepted and WP11 closeout has passed.
 */
class ImportOpenLpDirectoryCommand extends Command
{
    protected $signature = 'service-tracking:import-openlp-services
                            {--path= : Path to a directory containing OpenLP .osz archives}
                            {--manifest= : Path to the private crockenhill-openlp-curation manifest}
                            {--dry-run : Validate and write the canonical import plan without database changes}
                            {--apply : Apply the previously validated canonical import plan}
                            {--plan-hash= : Exact plan_hash emitted by the dry run}
                            {--report= : Dry-run report path (defaults beside the manifest)}';

    protected $description = 'Bulk import historic OpenLP church service archives from a directory';

    public function handle(
        ImportChurchServiceFromOpenLp $importer,
        OpenLpCurationManifest $curationManifest,
    ): int {
        $pathOption = $this->option('path');
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        $manifestOption = $this->option('manifest');

        if (! is_string($pathOption) || trim($pathOption) === '') {
            $this->error('Please provide a directory path with --path=');

            return self::FAILURE;
        }

        $path = realpath($pathOption) ?: $pathOption;

        if (! is_dir($path)) {
            $this->error("Directory does not exist: {$path}");

            return self::FAILURE;
        }

        if ($dryRun === $apply) {
            $this->error('Choose exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        if (! is_string($manifestOption) || trim($manifestOption) === '') {
            $this->error('A private curation manifest is required with --manifest=');

            return self::FAILURE;
        }

        try {
            $plan = $curationManifest->plan($path, $manifestOption);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            try {
                $curationManifest->validateIncludesForDryRun($path, $plan);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            return $this->writeDryRunReport($plan->report(), $manifestOption);
        }

        $providedPlanHash = $this->option('plan-hash');

        if (! is_string($providedPlanHash) || ! hash_equals($plan->planHash, $providedPlanHash)) {
            $this->error('The supplied --plan-hash does not match the current canonical import plan.');

            return self::FAILURE;
        }

        try {
            $archivePaths = $curationManifest->verifyIncludes($path, $plan);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $metrics = [
            'archives_found' => count($plan->includes),
            'archives_processed' => 0,
            'already_present' => 0,
            'services_created' => 0,
            'services_updated' => 0,
            'needs_review' => 0,
            'failures' => 0,
        ];

        foreach ($plan->includes as $entry) {
            $archivePath = $archivePaths[$entry['relative_path']];

            if ($this->alreadyPresent($entry['sha256'], $plan->manifestHash)) {
                $metrics['already_present']++;

                continue;
            }

            try {
                $result = $this->importArchive(
                    rawDirectory: $path,
                    entry: $entry,
                    importer: $importer,
                    curationManifest: $curationManifest,
                    batchHash: $plan->manifestHash,
                );

                $metrics['archives_processed']++;
                $metrics[$result->wasCreated ? 'services_created' : 'services_updated']++;

                if ($result->churchService->needs_review) {
                    $metrics['needs_review']++;
                }
            } catch (Throwable $exception) {
                $metrics['failures']++;
                $this->error(sprintf('%s: %s', basename($archivePath), $exception->getMessage()));
            }
        }

        $this->info('OpenLP archive import completed.');
        $this->line("Path: {$path}");
        $this->line("Manifest hash: {$plan->manifestHash}");
        $this->line("Plan hash: {$plan->planHash}");

        $this->table(
            ['Metric', 'Value'],
            [
                ['Archives found', (string) $metrics['archives_found']],
                ['Archives processed', (string) $metrics['archives_processed']],
                ['Already present / no-op', (string) $metrics['already_present']],
                ['Services created', (string) $metrics['services_created']],
                ['Services updated', (string) $metrics['services_updated']],
                ['Imports flagged for review', (string) $metrics['needs_review']],
                ['Failures', (string) $metrics['failures']],
            ]
        );

        return $metrics['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function writeDryRunReport(array $report, string $manifestPath): int
    {
        $reportOption = $this->option('report');
        $reportPath = is_string($reportOption) && trim($reportOption) !== ''
            ? $reportOption
            : "{$manifestPath}.plan.json";
        $reportDirectory = dirname($reportPath);

        if (! is_dir($reportDirectory) || ! is_writable($reportDirectory)) {
            $this->error("Dry-run report directory is not writable: {$reportDirectory}");

            return self::FAILURE;
        }

        $json = CanonicalJson::encode($report).PHP_EOL;

        if (file_put_contents($reportPath, $json, LOCK_EX) === false) {
            $this->error("Unable to write dry-run report: {$reportPath}");

            return self::FAILURE;
        }

        @chmod($reportPath, 0600);

        $this->info('OpenLP curation preflight passed. No database changes were written.');
        $this->line("Manifest hash: {$report['manifest_hash']}");
        $this->line("Plan hash: {$report['plan_hash']}");
        $this->line("Report: {$reportPath}");

        return self::SUCCESS;
    }

    private function uploadedFileForArchive(string $archivePath, string $logicalFilename): UploadedFile
    {
        return new UploadedFile(
            path: $archivePath,
            originalName: $logicalFilename,
            mimeType: 'application/zip',
            test: true,
        );
    }

    /**
     * @param  array{relative_path:string,sha256:string,byte_size:int,logical_upload_filename:string,resolved_date:string,resolved_service:string,alias_reason:?string}  $entry
     */
    private function importArchive(
        string $rawDirectory,
        array $entry,
        ImportChurchServiceFromOpenLp $importer,
        OpenLpCurationManifest $curationManifest,
        string $batchHash,
    ): OpenLpImportResult {
        return DB::transaction(function () use ($rawDirectory, $entry, $importer, $curationManifest, $batchHash): OpenLpImportResult {
            // Revalidate the preflighted bytes while the per-service apply
            // transaction is held, so a replacement cannot reach source ingestion.
            $archivePath = $curationManifest->verifyInclude($rawDirectory, $entry);
            $uploadedFile = $this->uploadedFileForArchive($archivePath, $entry['logical_upload_filename']);

            return $importer->import(
                $uploadedFile,
                $batchHash,
                $entry['resolved_date'],
                SermonService::from($entry['resolved_service']),
            );
        });
    }

    private function alreadyPresent(string $inputHash, string $batchHash): bool
    {
        return ChurchServiceSourceRecord::query()
            ->where('source', ChurchServiceSource::OpenLp->value)
            ->where('input_hash', $inputHash)
            ->where('batch_hash', $batchHash)
            ->exists();
    }
}
