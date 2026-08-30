<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\HistoricMedia\HistoricStagingHeadroom;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Media\Video\HistoricVideoCurationManifest;
use App\Services\Media\Video\HistoricVideoImporter;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Sermon\LivestreamSegmentationService;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

class ImportHistoricVideoBatchCommand extends Command
{
    /**
     * The manifest binding an operation must carry to own a historic-video dispatch, matching the
     * source kind an operator passes to `historic-import:prepare-operation --manifest=`.
     */
    private const SourceKind = 'historic_video';

    protected $signature = 'sermons:import-historic-videos
                            {--dir= : Root directory (default: /Volumes/CBC Drive/ServiceVideos)}
                            {--manifest= : Approved historic-video curation manifest}
                            {--plan-hash= : Exact plan_hash emitted by the approved manifest preflight}
                            {--operation= : Immutable historic import operation id this dispatch belongs to (required without --dry-run)}
                            {--from= : Only files from this date (YYYY-MM-DD)}
                            {--until= : Only files up to this date (YYYY-MM-DD)}
                            {--only= : Comma-separated manifest item keys to dispatch this pass (manifest mode only); the rest of the approved corpus stays pending for a later pass}
                            {--include-unclassified : Process files outside morning (10:00-12:59) and evening (17:00-21:00) windows}
                            {--default-year= : Fallback year for YouTubeDownloads files lacking a year}
                            {--min-size-mb=30 : Skip files smaller than this (MB)}
                            {--no-concat : Disable multi-segment concatenation; process each segment separately}
                            {--reencode-mismatched : Re-encode segments with mismatched codecs before concatenation}
                            {--allow-local-storage : Allow running with SERMON_STORAGE_DISK=local}
                            {--temp-disk-min-free-gb= : Minimum free space (GB) on the pipeline temp disk before dispatch (default: media-processing.storage.temp_disk_min_free_gb)}
                            {--host-capacity-evidence= : JSON evidence binding host free bytes to this operation and plan when staging capacity is unmeasurable}
                            {--parallel=1 : Worker concurrency used to calculate dispatch headroom}
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
        $operation = null;

        if ($manifestPath === null && is_string($this->option('only')) && trim((string) $this->option('only')) !== '') {
            $this->error('--only names manifest item keys, so it requires --manifest.');

            return self::FAILURE;
        }

        if ($manifestPath !== null) {
            try {
                $plan = $curationManifest->plan($directory, $manifestPath);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line('Only selected sources are verified immediately before durable staging and dispatch.');

            $approvedWorkItems = $plan->workItems;
            $defaultYear = null;
            $includeUnclassified = false;
            $from = null;
            $until = null;

            /**
             * A checkpoint selector, not a corpus filter — which is why it is permitted where
             * --from/--until/--limit are refused. Those three select a different corpus and leave
             * no trace of having done so. `--only` cannot: the manifest and plan hashes are
             * computed from the manifest's entries rather than from the work items, so a bounded
             * pass belongs to exactly the same approved round as a full one, its dispatches carry
             * the same {@see HistoricVideoImporter} dedup keys, and everything it does not touch
             * stays pending for a later pass instead of being silently dropped.
             *
             * This exists because the alternative the definitive-run guard points at — stopping
             * and resuming against manifest order — cannot produce a representative first pass.
             * The manifest is date-sorted and format tracks the recording era, so a chronological
             * prefix reaches no `.mkv` container and no concatenation above two files until it has
             * run most of the corpus.
             */
            $only = $this->parseOnly($this->option('only'));

            if ($only === false) {
                return self::FAILURE;
            }

            if ($only !== []) {
                $selected = array_values(array_filter(
                    $approvedWorkItems,
                    fn (array $item): bool => in_array($item['manifest_item_key'], $only, true),
                ));

                $found = array_map(fn (array $item): string => $item['manifest_item_key'], $selected);
                $unknown = array_values(array_diff($only, $found));

                if ($unknown !== []) {
                    $this->error(
                        'These --only keys are not included work items in the approved manifest: '
                        .implode(', ', $unknown)
                    );

                    return self::FAILURE;
                }

                $approvedWorkItems = $selected;

                $this->warn(sprintf(
                    'Bounded pass: dispatching %d of %d approved work items. The remaining %d stay pending for a later pass.',
                    count($selected),
                    count($plan->workItems),
                    count($plan->workItems) - count($selected),
                ));
            }
        }

        if (! $dryRun) {
            if ($force || $limit > 0 || $this->option('from') !== null || $this->option('until') !== null) {
                $this->error('Definitive historic-video runs forbid --force, --limit, --from and --until; use immutable manifest checkpoints instead.');

                return self::FAILURE;
            }

            if ($plan === null) {
                $this->error('An approved historic-video manifest is required before dispatch.');

                return self::FAILURE;
            }

            /**
             * Resolved before the guard so the guard can bind to it. Invariant 7 needs the
             * operation for a second reason: {@see LivestreamSegmentationService}
             * only stamps `historic_import_operation_id` on the processing log when the metadata
             * carries an `operation_id`, and {@see ProcessingNotificationRouter}
             * throws on a log that has historic metadata but no operation binding. A dispatch
             * without an operation therefore did not merely skip an approval check — it queued work
             * that fails the moment the pipeline tries to route a notification.
             */
            try {
                $operation = $this->resolveOperation($plan->manifestHash);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $refusal = $productionGuard->refusalFor(
                'sermons:import-historic-videos',
                $operation->operation_id,
                roundCorpusHash: $plan->manifestHash,
                planHash: $plan->planHash,
            );

            if ($refusal !== null) {
                $this->error($refusal);

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

        if ($approvedWorkItems !== null) {
            $headroom = $this->reportStagingHeadroom($approvedWorkItems, $parallel, $tempDiskMinFreeGb);

            if (! $dryRun && ! $headroom['measurable']) {
                try {
                    $this->assertHostCapacityEvidence($operation, $plan->planHash, $headroom);
                } catch (Throwable $exception) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }
            }
        }

        if (! $dryRun && $this->parseOnly($this->option('only')) === []) {
            $this->error('Definitive historic-video dispatch requires a non-empty --only manifest-key list.');

            return self::FAILURE;
        }

        $this->line("Dispatcher headroom assumes {$parallel} concurrent worker job(s); it records processing IDs and exits after enqueueing.");

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
                pollIntervalSeconds: 0,
                perFileTimeoutSeconds: 0,
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
                operation: $operation,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Historic video import completed.');

        /**
         * `resumed_completed` is deliberately outside this total. It counts work this exact
         * manifest already finished, which is progress carried forward rather than corpus that was
         * passed over, and folding it into "skipped" is what made a resumed pass look like a
         * no-op run.
         */
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
                ['Resumed (already completed by this manifest)', (string) $metrics['resumed_completed']],
                ['Resumed (already in flight)', (string) $metrics['resumed_inflight']],
                ['Retried (failed exact manifest run)', (string) $metrics['retried_failed']],
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

        if ($metrics['aborted_stale_mount']) {
            $this->error(
                'The source drive stopped being readable mid-pass, so dispatch stopped. Nothing already '
                .'dispatched was disturbed. Remount the drive and re-run this pass with the same --only '
                .'keys; the items it did not reach are still pending.',
            );

            return self::FAILURE;
        }

        if ($metrics['errors'] > 0 || (! $dryRun && $totalSkipped > 0)) {
            $this->error('Approved historic-video corpus closeout is incomplete; skipped, timed-out and failed items are not exact completion.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The immutable operation this dispatch belongs to, refusing anything it cannot own.
     *
     * Mirrors `service-tracking:import-historic-song-usage-reports`: name the operation, prove it
     * exists, and prove it carries a manifest binding for *this* lane's approved corpus. The last
     * check is what stops an operation prepared for the Email or hymn lane from lending its
     * identity to a video dispatch — the operation's recorded `historic_video` manifest hash must
     * be the manifest this run is about to dispatch.
     */
    private function resolveOperation(string $manifestHash): HistoricImportOperation
    {
        $operationId = $this->option('operation');
        $operationId = is_string($operationId) && trim($operationId) !== '' ? trim($operationId) : null;

        if ($operationId === null) {
            throw new RuntimeException(
                'A definitive historic-video dispatch must name its immutable operation with --operation.',
            );
        }

        $operation = HistoricImportOperation::query()->where('operation_id', $operationId)->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("Historic import operation {$operationId} does not exist.");
        }

        $bound = $operation->manifest_hashes[self::SourceKind] ?? null;

        if (! is_string($bound) || ! hash_equals($bound, $manifestHash)) {
            throw new RuntimeException(
                "Operation {$operationId} carries no '".self::SourceKind."' binding for this approved manifest.",
            );
        }

        /**
         * Invariant 7 is enforced downstream by refusing to route a notification for an operation
         * that permits them, but by then the work is queued. Refusing here means an operation
         * prepared without external containment cannot dispatch historic video at all.
         */
        if ($operation->notification_mode !== 'external_disabled') {
            throw new RuntimeException(
                "Operation {$operationId} does not suppress external notifications, so it cannot own historic video work.",
            );
        }

        return $operation;
    }

    /**
     * State what the pass needs of the drive, and whether this process can see
     * how much is there.
     *
     * The pilot-to-bulk plan was sized against "30 GB available of 461 GB", read
     * from `df` inside the container, which reports the host's boot volume and
     * not the bind-mounted drive — the drive holds 444 GiB free. The gates were
     * already standing down correctly, because the operator had declared the
     * volume unmeasurable; nothing said so out loud, so a wrong number reached a
     * plan. Now it does.
     */
    /**
     * @param  list<array<string, mixed>>  $workItems  The pass's own items, after --only narrows them
     * @return array{measurable:bool,process_reported_free_bytes:int|null,host_command:string,item_count:int,selected_source_bytes:int,largest_source_bytes:int,concurrent_working_bytes:int,minimum_free_bytes:int,required_free_bytes:int}
     */
    private function reportStagingHeadroom(
        array $workItems,
        int $parallel,
        int $tempDiskMinFreeGb,
    ): array {
        $report = app(HistoricStagingHeadroom::class)->report(
            $workItems,
            $parallel,
            $tempDiskMinFreeGb,
        );
        $gib = static fn (?int $bytes): string => $bytes === null
            ? 'unknown'
            : number_format($bytes / 1024 ** 3, 1).' GiB';

        $this->line(sprintf(
            'Pass needs: %s free (%s floor + %s for %d concurrent job(s)); %d identities, %s of source, largest %s.',
            $gib($report['required_free_bytes']),
            $gib($report['minimum_free_bytes']),
            $gib($report['concurrent_working_bytes']),
            max(1, $parallel),
            $report['item_count'],
            $gib($report['selected_source_bytes']),
            $gib($report['largest_source_bytes']),
        ));

        if ($report['measurable']) {
            $this->line('Staging free space: '.$gib($report['process_reported_free_bytes']).'.');

            return $report;
        }

        $this->warn(sprintf(
            'Staging free space is NOT measurable from this process. It reports %s, which is the '
            .'parent filesystem rather than the drive. Confirm the real figure on the host with: %s',
            $gib($report['process_reported_free_bytes']),
            $report['host_command'],
        ));

        return $report;
    }

    /**
     * @param  array{required_free_bytes:int}  $headroom
     */
    private function assertHostCapacityEvidence(
        HistoricImportOperation $operation,
        string $planHash,
        array $headroom,
    ): void {
        $path = $this->option('host-capacity-evidence');

        if (! is_string($path) || trim($path) === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Staging capacity is unmeasurable: supply readable operation-bound --host-capacity-evidence from the host df check.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || strlen($contents) > 16 * 1024) {
            throw new RuntimeException('Host capacity evidence is unreadable or exceeds the 16 KB limit.');
        }

        try {
            $evidence = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Host capacity evidence must be a JSON object.');
        }

        if (! is_array($evidence)
            || ($evidence['operation_id'] ?? null) !== $operation->operation_id
            || ($evidence['plan_hash'] ?? null) !== $planHash
            || ! is_int($evidence['available_bytes'] ?? null)) {
            throw new RuntimeException('Host capacity evidence must bind this operation and plan and contain integer available_bytes.');
        }

        if ($evidence['available_bytes'] < $headroom['required_free_bytes']) {
            throw new RuntimeException('Host capacity evidence is below this pass\'s required staging headroom.');
        }

        $this->info('Operation-bound host capacity evidence satisfies the required staging headroom.');
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

    /**
     * Manifest item keys named by --only, or false when the option is malformed.
     *
     * @return list<string>|false
     */
    private function parseOnly(mixed $option): array|false
    {
        if (! is_string($option) || trim($option) === '') {
            return [];
        }

        $keys = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $option)),
            fn (string $key): bool => $key !== '',
        )));

        if ($keys === []) {
            $this->error('--only was given but names no manifest item keys.');

            return false;
        }

        return $keys;
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
