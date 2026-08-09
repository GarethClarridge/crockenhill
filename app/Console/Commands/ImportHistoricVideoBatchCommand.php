<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Media\Video\HistoricVideoCurationManifest;
use App\Services\Media\Video\HistoricVideoImporter;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Throwable;

class ImportHistoricVideoBatchCommand extends Command
{
    protected $signature = 'sermons:import-historic-videos
                            {--dir= : Root directory (default: /Volumes/CBC Drive/ServiceVideos)}
                            {--manifest= : Approved historic-video curation manifest}
                            {--plan-hash= : Exact plan_hash emitted by the approved manifest preflight}
                            {--from= : Only files from this date (YYYY-MM-DD)}
                            {--until= : Only files up to this date (YYYY-MM-DD)}
                            {--include-unclassified : Process files outside morning (10:00-12:59) and evening (17:00-21:00) windows}
                            {--default-year= : Fallback year for YouTubeDownloads files lacking a year}
                            {--min-size-mb=30 : Skip files smaller than this (MB)}
                            {--no-concat : Disable multi-segment concatenation; process each segment separately}
                            {--reencode-mismatched : Re-encode segments with mismatched codecs before concatenation}
                            {--allow-local-storage : Allow running with SERMON_STORAGE_DISK=local}
                            {--temp-disk-min-free-gb= : Minimum free space (GB) on the pipeline temp disk before dispatch (default: media-processing.storage.temp_disk_min_free_gb)}
                            {--parallel=1 : Max concurrent in-flight dispatches}
                            {--poll-interval=30 : Seconds between status polls when serial-dispatching}
                            {--per-file-timeout=7200 : Max seconds to wait for a single file\'s pipeline to finish}
                            {--dry-run : Show what would happen, no work}
                            {--delay=0 : Seconds between dispatches}
                            {--limit=0 : Max sermons to import this run (0 = no limit)}
                            {--report= : Write a permission-restricted JSON report (the curation plan during a manifest dry run)}
                            {--force : Bypass date/service existence and in-flight skip checks}';

    protected $description = 'Import historic video recordings into the livestream processing pipeline';

    public function handle(
        HistoricVideoImporter $importer,
        HistoricVideoCurationManifest $curationManifest,
        HistoricStagingGuard $stagingGuard,
        HistoricImportProductionGuard $productionGuard,
    ): int {
        $dirOption = $this->option('dir');
        $directory = is_string($dirOption) && trim($dirOption) !== ''
            ? (realpath($dirOption) ?: $dirOption)
            : '/Volumes/CBC Drive/ServiceVideos';

        if (! is_dir($directory)) {
            $this->error("Directory does not exist: {$directory}");

            return self::FAILURE;
        }

        if (! $this->checkStorageDisk()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $includeUnclassified = (bool) $this->option('include-unclassified');
        $noConcat = (bool) $this->option('no-concat');
        $reEncodeMismatched = (bool) $this->option('reencode-mismatched');
        $delay = max(0, (int) $this->option('delay'));
        $minSizeMb = max(1, (int) $this->option('min-size-mb'));
        $tempDiskMinFreeGbOption = $this->option('temp-disk-min-free-gb');
        $tempDiskMinFreeGb = max(1, (int) ($tempDiskMinFreeGbOption ?? config('media-processing.storage.temp_disk_min_free_gb', 20)));
        $parallel = max(1, (int) $this->option('parallel'));
        $pollInterval = max(5, (int) $this->option('poll-interval'));
        $perFileTimeout = max(60, (int) $this->option('per-file-timeout'));
        $limit = max(0, (int) $this->option('limit'));
        $reportOption = $this->option('report');
        $reportPath = is_string($reportOption) && trim($reportOption) !== '' ? $reportOption : null;

        $defaultYear = null;
        $defaultYearOption = $this->option('default-year');
        if (is_string($defaultYearOption) && trim($defaultYearOption) !== '') {
            $defaultYear = (int) $defaultYearOption;
        }

        $from = $this->parseDate($this->option('from'), '--from');
        $until = $this->parseDate($this->option('until'), '--until');

        if ($from === false || $until === false) {
            return self::FAILURE;
        }

        $approvedWorkItems = null;
        $manifestOption = $this->option('manifest');
        $manifestPath = is_string($manifestOption) && trim($manifestOption) !== ''
            ? trim($manifestOption)
            : null;
        $plan = null;
        $stagingContext = null;

        if ($manifestPath !== null) {
            try {
                $plan = $curationManifest->plan($directory, $manifestPath);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $approvedWorkItems = $plan->workItems;
            $defaultYear = null;
            $includeUnclassified = false;
            $from = null;
            $until = null;
        }

        if (! $dryRun) {
            if ($force || $limit > 0 || $this->option('from') !== null || $this->option('until') !== null) {
                $this->error('Definitive historic-video runs forbid --force, --limit, --from and --until; use immutable manifest checkpoints instead.');

                return self::FAILURE;
            }

            $refusal = $productionGuard->refusalFor('sermons:import-historic-videos');

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }

            if ($plan === null) {
                $this->error('An approved historic-video manifest is required before dispatch.');

                return self::FAILURE;
            }

            $providedPlanHash = $this->option('plan-hash');

            if (! is_string($providedPlanHash) || ! hash_equals($plan->planHash, $providedPlanHash)) {
                $this->error('The supplied --plan-hash does not match the approved historic-video manifest plan.');

                return self::FAILURE;
            }

            try {
                $stagingContext = $stagingGuard->contextForApprovedPlan($plan->manifestHash, $plan->planHash);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('Dry run enabled. No files will be dispatched and no processing logs will be created.');

            if ($plan !== null) {
                if (! $this->writeDryRunPlanReport($plan->report(), $manifestPath)) {
                    return self::FAILURE;
                }

                $reportPath = null;
            }
        }

        $this->line('Directory: '.$directory);
        $this->line('Sermon storage disk: '.config('media-processing.storage.sermon_disk', 'local'));

        if ($parallel > 1) {
            $this->line("Parallel dispatch: {$parallel} concurrent jobs");
        } else {
            $this->line('Serial dispatch: waiting for each file before dispatching next');
        }

        try {
            $metrics = $importer->import(
                directory: $directory,
                dryRun: $dryRun,
                delay: $delay,
                force: $force,
                minSizeMb: $minSizeMb,
                includeUnclassified: $includeUnclassified,
                defaultYear: $defaultYear,
                noConcat: $noConcat,
                reEncodeMismatched: $reEncodeMismatched,
                tempDiskMinFreeGb: $tempDiskMinFreeGb,
                parallel: $parallel,
                pollIntervalSeconds: $pollInterval,
                perFileTimeoutSeconds: $perFileTimeout,
                limit: $limit,
                onProgress: function (string $tag, string $label, ?string $detail): void {
                    $line = "{$tag} {$label}";

                    if ($detail !== null) {
                        $line .= " → {$detail}";
                    }

                    $this->line($line);
                },
                from: $from instanceof Carbon ? $from : null,
                until: $until instanceof Carbon ? $until : null,
                reportPath: $reportPath,
                approvedWorkItems: $approvedWorkItems,
                stagingContext: $stagingContext,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Historic video import completed.');

        $totalSkipped = $metrics['skipped_exists']
            + $metrics['skipped_inflight']
            + $metrics['skipped_pending_review']
            + $metrics['skipped_small']
            + $metrics['skipped_audio_dup']
            + $metrics['skipped_no_date']
            + $metrics['skipped_unclassified']
            + $metrics['skipped_low_disk'];

        $this->table(
            ['Metric', 'Value'],
            [
                ['Dispatched', (string) $metrics['dispatched']],
                ['  → Concatenated (lossless)', (string) $metrics['concatenated']],
                ['  → Concatenated (re-encoded)', (string) $metrics['concatenated_reencoded']],
                ['Skipped (total)', (string) $totalSkipped],
                ['  → Already processed', (string) $metrics['skipped_exists']],
                ['  → In-flight', (string) $metrics['skipped_inflight']],
                ['  → Pending manual review', (string) $metrics['skipped_pending_review']],
                ['  → Too small', (string) $metrics['skipped_small']],
                ['  → Audio duplicate', (string) $metrics['skipped_audio_dup']],
                ['  → No parseable date', (string) $metrics['skipped_no_date']],
                ['  → Unclassified time window', (string) $metrics['skipped_unclassified']],
                ['  → Temp disk too full', (string) $metrics['skipped_low_disk']],
                ['Errors', (string) $metrics['errors']],
                ['Bytes processed', $this->formatBytes($metrics['bytes_processed'])],
                ['Bytes skipped', $this->formatBytes($metrics['bytes_skipped'])],
            ]
        );

        if ($metrics['errors'] > 0 || (! $dryRun && $totalSkipped > 0)) {
            $this->error('Approved historic-video corpus closeout is incomplete; skipped, timed-out and failed items are not exact completion.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function checkStorageDisk(): bool
    {
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'local');
        $diskConfiguration = Config::get("filesystems.disks.{$sermonDisk}");

        if (! is_array($diskConfiguration) || ! isset($diskConfiguration['driver'])) {
            $this->error("SERMON_STORAGE_DISK '{$sermonDisk}' is not configured.");

            return false;
        }

        // The private staging disk is the sanctioned destination for a historic batch,
        // so it satisfies this check on its own. Recommending a shared S3 disk here is
        // what would put local output into production's canonical asset namespace.
        if ($sermonDisk === (string) config('media-processing.storage.historic_staging_disk')) {
            return true;
        }

        if ($diskConfiguration['driver'] === 'local' && ! $this->option('allow-local-storage')) {
            $this->error(
                "SERMON_STORAGE_DISK '{$sermonDisk}' uses the local filesystem driver. Importing the archive may fill local disk."
                .PHP_EOL.'Point it at the private historic staging disk, or pass --allow-local-storage to override.'
            );

            return false;
        }

        return true;
    }

    private function parseDate(mixed $option, string $flagName): Carbon|false|null
    {
        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', trim($option));

            if (! $date instanceof Carbon) {
                $this->error("{$flagName} must be in YYYY-MM-DD format, got: {$option}");

                return false;
            }

            return $date->startOfDay();
        } catch (Throwable) {
            $this->error("{$flagName} must be in YYYY-MM-DD format, got: {$option}");

            return false;
        }
    }

    /** @param array<string, mixed> $report */
    private function writeDryRunPlanReport(array $report, string $manifestPath): bool
    {
        $reportOption = $this->option('report');
        $reportPath = is_string($reportOption) && trim($reportOption) !== ''
            ? trim($reportOption)
            : "{$manifestPath}.plan.json";
        $reportDirectory = dirname($reportPath);

        if (! is_dir($reportDirectory) || ! is_writable($reportDirectory)) {
            $this->error("Dry-run report directory is not writable: {$reportDirectory}");

            return false;
        }

        if (file_put_contents($reportPath, CanonicalJson::encode($report).PHP_EOL, LOCK_EX) === false) {
            $this->error("Unable to write dry-run report: {$reportPath}");

            return false;
        }

        @chmod($reportPath, 0600);

        $this->info('Historic-video curation preflight passed. No files were dispatched.');
        $this->line("Manifest hash: {$report['manifest_hash']}");
        $this->line("Plan hash: {$report['plan_hash']}");
        $this->line("Report: {$reportPath}");

        return true;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 2).' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return round($bytes / (1024 ** 2), 1).' MB';
        }

        return $bytes.' B';
    }
}
