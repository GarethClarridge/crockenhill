<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\HistoricStagingContext;
use App\Data\ProcessingResult;
use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Exceptions\HistoricSourceIntegrityException;
use App\Exceptions\HistoricSourceMountException;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ChurchService\SourceAdapters\LivestreamSourceAdapter;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\TempDiskSpace;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @phpstan-type ImportMetrics array{
 *     dispatched: int,
 *     concatenated: int,
 *     concatenated_reencoded: int,
 *     enriched: int,
 *     skipped_exists: int,
 *     resumed_completed: int,
 *     resumed_inflight: int,
 *     retried_failed: int,
 *     skipped_inflight: int,
 *     skipped_pending_review: int,
 *     skipped_small: int,
 *     skipped_audio_dup: int,
 *     skipped_no_date: int,
 *     skipped_unclassified: int,
 *     skipped_low_disk: int,
 *     errors: int,
 *     aborted_stale_mount: bool,
 *     bytes_processed: int,
 *     bytes_skipped: int
 * }
 * @phpstan-type StagedFile array{temp_path:string,full_path:string,original_filename:string,file_size:int,mime_type:string|null}
 */
class HistoricVideoImporter
{
    private const TEMP_DIR = 'temp/historic-import';

    private const MORNING_START_HOUR = 10;

    private const MORNING_END_HOUR = 12;

    private const EVENING_START_HOUR = 17;

    private const EVENING_END_HOUR = 21;

    private const SUPPORTED_EXTENSIONS = ['mkv', 'mp4', 'mov'];

    public function __construct(
        private readonly UnifiedMediaProcessor $processor,
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
        private readonly HistoricProcessingFingerprint $fingerprints,
        private readonly HistoricVideoReencodeConcatenator $reencodeConcatenator,
        private readonly VideoStorageService $videoStorageService,
        private readonly ?HistoricProcessingThroughput $throughput = null,
    ) {}

    /**
     * @param  \Closure(string $tag, string $label, string|null $detail): void|null  $onProgress
     * @param  list<array{
     *     manifest_item_key:string,
     *     tag:string,
     *     label:string,
     *     files:list<string>,
     *     source_files:list<array{relative_path:string,sha256:string,byte_size:int}>,
     *     date:Carbon,
     *     service:SermonService,
     *     client_file_date:string,
     *     bytes:int,
     *     manifest_concatenation:string
     * }>|null  $approvedWorkItems
     * @return array{
     *     dispatched: int,
     *     concatenated: int,
     *     concatenated_reencoded: int,
     *     enriched: int,
     *     skipped_exists: int,
     *     resumed_completed: int,
     *     resumed_inflight: int,
     *     retried_failed: int,
     *     skipped_inflight: int,
     *     skipped_pending_review: int,
     *     skipped_small: int,
     *     skipped_audio_dup: int,
     *     skipped_no_date: int,
     *     skipped_unclassified: int,
     *     skipped_low_disk: int,
     *     errors: int,
     *     aborted_stale_mount: bool,
     *     bytes_processed: int,
     *     bytes_skipped: int
     * }
     */
    public function import(
        string $directory,
        bool $dryRun,
        int $delay,
        bool $force,
        int $minSizeMb,
        bool $includeUnclassified,
        ?int $defaultYear,
        bool $noConcat,
        bool $reEncodeMismatched,
        int $tempDiskMinFreeGb,
        int $pollIntervalSeconds,
        int $perFileTimeoutSeconds,
        int $limit,
        ?\Closure $onProgress = null,
        ?Carbon $from = null,
        ?Carbon $until = null,
        ?string $reportPath = null,
        ?array $approvedWorkItems = null,
        ?HistoricStagingContext $stagingContext = null,
        ?HistoricImportOperation $operation = null,
    ): array {
        if (! $dryRun) {
            if (! $stagingContext instanceof HistoricStagingContext) {
                throw new \RuntimeException('Historic video dispatch requires the approved staging and manifest context.');
            }
        }

        /**
         * These values are invariant for one importer pass. Compute them once
         * before the first item is dispatched and carry the immutable arrays
         * into every item's metadata; a new importer instance must recompute
         * them after a binary or configuration change.
         */
        /** @var array<string, mixed>|null $processingFingerprint */
        $processingFingerprint = null;
        /** @var array<string, array{routing_fingerprint:string,worker_width:int}>|null $executionProfile */
        $executionProfile = null;

        if (! $dryRun && $stagingContext instanceof HistoricStagingContext) {
            $processingFingerprint = $this->fingerprints->forStagingContext($stagingContext);
            $executionProfile = ($this->throughput ?? app(HistoricProcessingThroughput::class))->executionProfile();
        }

        $metrics = [
            'dispatched' => 0,
            'concatenated' => 0,
            'concatenated_reencoded' => 0,
            'enriched' => 0,
            'skipped_exists' => 0,
            'resumed_completed' => 0,
            'resumed_inflight' => 0,
            'retried_failed' => 0,
            'skipped_inflight' => 0,
            'skipped_pending_review' => 0,
            'skipped_small' => 0,
            'skipped_audio_dup' => 0,
            'skipped_no_date' => 0,
            'skipped_unclassified' => 0,
            'skipped_low_disk' => 0,
            'errors' => 0,
            'aborted_stale_mount' => false,
            'bytes_processed' => 0,
            'bytes_skipped' => 0,
        ];

        $dispatched = 0;
        $decisions = [];

        $workItems = $approvedWorkItems === null
            ? $this->prioritiseWorkItems(
                iterator_to_array($this->buildWorkItems($directory, $minSizeMb, $includeUnclassified, $defaultYear), false),
            )
            : $this->prioritiseWorkItems($approvedWorkItems);

        foreach ($workItems as $item) {
            if ($limit > 0 && $dispatched >= $limit) {
                break;
            }

            $tag = $item['tag'];
            $label = $item['label'];
            $bytes = $item['bytes'];
            // One report entry per work item. The dispatched branch rewrites this
            // entry rather than appending a second one, so the manifest's item
            // count always equals the number of things the importer looked at.
            $decisionIndex = count($decisions);
            $decisions[] = ['decision' => $tag, 'label' => $label];

            if ($tag === 'skip-small') {
                $metrics['skipped_small']++;
                $metrics['bytes_skipped'] += $bytes;
                $onProgress?->__invoke('[skip-small]', $label, null);

                continue;
            }

            if ($tag === 'skip-dup') {
                $metrics['skipped_audio_dup']++;
                $metrics['bytes_skipped'] += $bytes;
                $onProgress?->__invoke('[skip-dup]', $label, null);

                continue;
            }

            if ($tag === 'skip-no-date') {
                $metrics['skipped_no_date']++;
                $metrics['bytes_skipped'] += $bytes;
                $onProgress?->__invoke('[skip-no-date]', $label, 'no year in filename (use --default-year)');

                continue;
            }

            if ($tag === 'skip-unclassified') {
                $metrics['skipped_unclassified']++;
                $metrics['bytes_skipped'] += $bytes;
                $onProgress?->__invoke('[skip-unclassified]', $label, null);

                continue;
            }

            $date = $item['date'];
            $service = $item['service'];

            if (($from !== null && $date->lt($from)) || ($until !== null && $date->gt($until))) {
                $metrics['bytes_skipped'] += $bytes;
                $onProgress?->__invoke('[skip-date-range]', $label, null);

                continue;
            }

            /**
             * Resume before the generic existence check, never after it.
             *
             * A stopped full-manifest pass restarts against a database already holding its own
             * completed runs. {@see self::checkExistence()} only asks whether *some* completed
             * livestream exists for the date and slot, so it reported this run's own finished work
             * as `skip-exists` — the same tag it uses for a service some other source produced. The
             * two mean opposite things to an operator: one is resumed progress, the other is a
             * corpus collision worth investigating, and a resumed pass reported the whole corpus as
             * the second.
             *
             * The manifest job key is the exact identity, and it is already the durable lock —
             * `dedup_key` is uniquely indexed and {@see UnifiedMediaProcessor::process()} reuses a
             * run that carries it. Checking it here rather than relying on that reuse avoids
             * re-concatenating a finished multi-segment item purely to be told it is a duplicate.
             */
            $plannedKeys = $stagingContext instanceof HistoricStagingContext
                ? $this->plannedJobKeys($item, $stagingContext, $noConcat)
                : [];
            $ownRuns = $plannedKeys === []
                ? collect()
                : $this->preferredOwnRunsForJobKeys($plannedKeys, $this->ownRunsForJobKeys($plannedKeys));
            $resumedKeys = $ownRuns->isEmpty()
                ? []
                : $this->completedOwnJobKeys($plannedKeys, $ownRuns);

            if ($resumedKeys !== [] && $resumedKeys === $plannedKeys) {
                $metrics['resumed_completed']++;
                $metrics['bytes_skipped'] += $bytes;
                $decisions[$decisionIndex] = ['decision' => 'resume-completed', 'label' => $label];
                $onProgress?->__invoke('[resume-completed]', $label, 'already completed by this exact manifest item');

                continue;
            }

            $coveredKeys = $resumedKeys;
            $retryBlocked = false;
            $limitReached = false;
            $hasOwnInflight = false;

            foreach ($ownRuns as $ownRun) {
                $jobKey = $this->jobKeyForRun($ownRun);

                if ($jobKey === null || in_array($jobKey, $resumedKeys, true)) {
                    continue;
                }

                if (in_array($ownRun->status, [ProcessingStatus::Pending, ProcessingStatus::Started, ProcessingStatus::Processing], true)) {
                    $hasOwnInflight = true;
                    $metrics['resumed_inflight']++;
                    $coveredKeys[] = $jobKey;
                    $decisions[$decisionIndex] = [
                        'decision' => 'resume-inflight',
                        'label' => $label,
                        'processing_id' => $ownRun->processing_id,
                    ];
                    $onProgress?->__invoke('[resume-inflight]', $label, "waiting for processing_id={$ownRun->processing_id}");

                    continue;
                }

                if ($ownRun->status !== ProcessingStatus::Failed
                    || ($ownRun->requiresManualSermonReview() && ! $this->isStructureValidationFailure($ownRun))) {
                    continue;
                }

                if ($limit > 0 && $dispatched >= $limit) {
                    $limitReached = true;

                    break;
                }

                $retry = $this->processor->retry($ownRun->processing_id);
                $coveredKeys[] = $jobKey;

                if (! $retry->success) {
                    $metrics['errors']++;
                    $retryBlocked = true;
                    $onProgress?->__invoke('[retry-error]', $label, $retry->message);

                    continue;
                }

                $hasOwnInflight = true;
                $dispatched++;
                $metrics['retried_failed']++;
                $decisions[$decisionIndex] = [
                    'decision' => 'retry-failed',
                    'label' => $label,
                    'processing_id' => $ownRun->processing_id,
                ];
                $onProgress?->__invoke('[retry-failed]', $label, "resumed processing_id={$ownRun->processing_id}");
            }

            $coveredKeys = array_values(array_unique($coveredKeys));
            if ($limitReached) {
                break;
            }

            if ($retryBlocked) {
                continue;
            }

            if ($plannedKeys !== [] && count($coveredKeys) === count($plannedKeys)) {
                continue;
            }

            if (! $force) {
                $existenceTag = $this->checkExistence($date, $service);

                /**
                 * A partially resumed item has itself put a completed log at this date and slot, so
                 * `skip-exists` here is this run's own work reflected back and must not stop the
                 * remainder from dispatching. Only that one verdict is discounted: an in-flight or
                 * review-pending run at the same identity is still someone else's claim on it, and
                 * the item's finished keys are settled by the processor's `dedup_key` reuse rather
                 * than re-dispatched.
                 */
                if ($existenceTag === 'skip-exists' && $resumedKeys !== []) {
                    $existenceTag = null;
                }

                if ($existenceTag === 'skip-inflight' && $hasOwnInflight) {
                    $existenceTag = null;
                }

                if ($existenceTag !== null) {
                    $detail = $this->existenceDetail($date, $service, $existenceTag);

                    match ($existenceTag) {
                        'skip-exists' => $metrics['skipped_exists']++,
                        'skip-inflight' => $metrics['skipped_inflight']++,
                        'skip-pending-review' => $metrics['skipped_pending_review']++,
                        default => null,
                    };

                    $metrics['bytes_skipped'] += $bytes;
                    $onProgress?->__invoke("[{$existenceTag}]", $label, $detail);

                    continue;
                }
            }

            if (! $dryRun) {
                try {
                    $this->assertApprovedSourceMetadata($item);
                } catch (HistoricSourceMountException $exception) {
                    $metrics['aborted_stale_mount'] = true;
                    $decisions[$decisionIndex] = [
                        'decision' => 'aborted_stale_mount',
                        'work_item_tag' => $tag,
                        'label' => $label,
                        'detail' => $exception->getMessage(),
                    ];
                    $onProgress?->__invoke('[abort-stale-mount]', $label, $exception->getMessage());

                    break;
                } catch (HistoricSourceIntegrityException $exception) {
                    $metrics['errors']++;
                    $decisions[$decisionIndex] = [
                        'decision' => 'source_integrity_failed',
                        'work_item_tag' => $tag,
                        'label' => $label,
                        'detail' => $exception->getMessage(),
                    ];
                    $onProgress?->__invoke('[error]', $label, $exception->getMessage());

                    continue;
                }
            }

            if (! $dryRun && ! $this->hasTempDiskSpace($item['files'], $tempDiskMinFreeGb)) {
                $metrics['skipped_low_disk']++;
                $metrics['bytes_skipped'] += $bytes;
                $freeGb = round(disk_free_space($this->tempDiskPath()) / (1024 ** 3), 1);
                $onProgress?->__invoke('[skip-low-disk]', $label, "temp disk free space below threshold ({$freeGb} GB free, {$tempDiskMinFreeGb} GB required)");

                continue;
            }

            if ($dryRun) {
                $onProgress?->__invoke("[{$tag}]", $label, 'dry-run');
                $metrics['dispatched']++;
                $dispatched++;

                continue;
            }

            try {
                $results = $this->dispatchItem(
                    $item,
                    $noConcat,
                    $reEncodeMismatched,
                    $stagingContext,
                    $operation,
                    $processingFingerprint,
                    $executionProfile,
                );

                foreach ($results as $result) {
                    if ($result['tag'] === 'error') {
                        $metrics['errors']++;
                        $onProgress?->__invoke('[error]', $label, $result['detail']);

                        continue;
                    }

                    $processingId = $result['processing_id'];
                    $decisions[$decisionIndex] = [
                        'decision' => 'dispatched',
                        'work_item_tag' => $tag,
                        'label' => $label,
                        'processing_id' => $processingId,
                    ];

                    match ($result['tag']) {
                        'concat' => $metrics['concatenated']++,
                        'concat-reencoded' => $metrics['concatenated_reencoded']++,
                        default => null,
                    };

                    $metrics['dispatched']++;
                    $metrics['bytes_processed'] += $bytes;
                    $dispatched++;
                    $onProgress?->__invoke("[{$result['tag']}]", $label, "dispatched → processing_id={$processingId}");

                    if ($delay > 0) {
                        sleep($delay);
                    }

                }
            } catch (HistoricSourceMountException $exception) {
                $metrics['aborted_stale_mount'] = true;
                $decisions[$decisionIndex] = [
                    'decision' => 'aborted_stale_mount',
                    'work_item_tag' => $tag,
                    'label' => $label,
                    'detail' => $exception->getMessage(),
                ];
                $onProgress?->__invoke('[abort-stale-mount]', $label, $exception->getMessage());

                break;
            } catch (\Throwable $e) {
                $metrics['errors']++;
                $decisions[$decisionIndex] = [
                    'decision' => $e instanceof HistoricSourceIntegrityException
                        ? 'source_integrity_failed'
                        : 'dispatch_failed',
                    'work_item_tag' => $tag,
                    'label' => $label,
                    'detail' => $e->getMessage(),
                ];
                Log::error('Historic video import dispatch failed', [
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
                $onProgress?->__invoke('[error]', $label, $e->getMessage());
            }
        }

        if ($reportPath !== null) {
            $this->writeReport($reportPath, $metrics, $decisions);
        }

        return $metrics;
    }

    /**
     * Process services that do not yet have a sermon first, newest recordings first within each group.
     *
     * @param  list<array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}>  $items
     * @return list<array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}>
     */
    private function prioritiseWorkItems(array $items): array
    {
        if (count($items) < 2) {
            return $items;
        }

        $existingSermonKeys = $this->existingSermonKeys($items);
        $indexedItems = array_map(
            fn (array $item, int $index): array => ['item' => $item, 'index' => $index],
            $items,
            array_keys($items),
        );

        usort($indexedItems, function (array $left, array $right) use ($existingSermonKeys): int {
            $leftItem = $left['item'];
            $rightItem = $right['item'];

            $processableComparison = $this->processableRank($leftItem) <=> $this->processableRank($rightItem);
            if ($processableComparison !== 0) {
                return $processableComparison;
            }

            $sermonComparison = $this->existingSermonRank($leftItem, $existingSermonKeys) <=> $this->existingSermonRank($rightItem, $existingSermonKeys);
            if ($sermonComparison !== 0) {
                return $sermonComparison;
            }

            $dateComparison = $rightItem['date']->getTimestamp() <=> $leftItem['date']->getTimestamp();
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        return array_map(fn (array $indexed): array => $indexed['item'], $indexedItems);
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int, manifest_concatenation?:string}  $item
     */
    private function processableRank(array $item): int
    {
        return str_starts_with($item['tag'], 'skip-') ? 1 : 0;
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  array<string, true>  $existingSermonKeys
     */
    private function existingSermonRank(array $item, array $existingSermonKeys): int
    {
        if ($this->processableRank($item) !== 0) {
            return 0;
        }

        return isset($existingSermonKeys[$this->sermonKey($item['date'], $item['service'])]) ? 1 : 0;
    }

    /**
     * @param  list<array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}>  $items
     * @return array<string, true>
     */
    private function existingSermonKeys(array $items): array
    {
        $dates = [];
        $services = [];

        foreach ($items as $item) {
            if ($this->processableRank($item) !== 0) {
                continue;
            }

            $dates[$item['date']->toDateString()] = true;
            $services[$item['service']->value] = true;
        }

        if ($dates === [] || $services === []) {
            return [];
        }

        $keys = [];

        Sermon::query()
            ->whereSermon()
            ->whereIn('date', array_keys($dates))
            ->whereIn('service', array_keys($services))
            ->get(['date', 'service'])
            ->each(function (Sermon $sermon) use (&$keys): void {
                if (! $sermon->service instanceof SermonService) {
                    return;
                }

                $keys[$this->sermonKey($sermon->date, $sermon->service)] = true;
            });

        return $keys;
    }

    private function sermonKey(Carbon $date, SermonService $service): string
    {
        return $date->toDateString().'|'.$service->value;
    }

    /**
     * Yield work items from the directory. Each item is a tagged array describing what to do.
     *
     * @return \Generator<array{
     *     tag: string,
     *     label: string,
     *     files: list<string>,
     *     date: Carbon,
     *     service: SermonService,
     *     client_file_date: string,
     *     bytes: int
     * }>
     */
    private function buildWorkItems(
        string $directory,
        int $minSizeMb,
        bool $includeUnclassified,
        ?int $defaultYear,
    ): \Generator {
        $minBytes = $minSizeMb * 1024 * 1024;

        // Root-level files
        foreach ($this->listVideoFiles($directory, recursive: false) as $path) {
            $item = $this->classifyRootFile($path, $minBytes, $defaultYear);

            if ($item !== null) {
                yield $item;
            }
        }

        // Dated subdirectories
        foreach ($this->listSubdirectories($directory) as $subdir) {
            $basename = basename($subdir);

            // Skip special dirs
            if (in_array($basename, ['YouTubeDownloads', 'FromDocuments'], true)) {
                continue;
            }

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename)) {
                continue;
            }

            $dirDate = Carbon::createFromFormat('Y-m-d', $basename);

            if (! $dirDate instanceof Carbon) {
                continue;
            }

            foreach ($this->groupByService($subdir, $dirDate, $minBytes, $includeUnclassified) as $item) {
                yield $item;
            }
        }

        // FromDocuments/
        $fromDocumentsDir = $directory.'/FromDocuments';
        if (is_dir($fromDocumentsDir)) {
            foreach ($this->listVideoFiles($fromDocumentsDir, recursive: false) as $path) {
                $item = $this->classifyRootFile($path, $minBytes, $defaultYear);

                if ($item !== null) {
                    yield $item;
                }
            }
        }

        // YouTubeDownloads/
        $youtubeDir = $directory.'/YouTubeDownloads';
        if (is_dir($youtubeDir)) {
            foreach ($this->listVideoFiles($youtubeDir, recursive: false) as $path) {
                $item = $this->classifyYouTubeFile($path, $minBytes, $defaultYear);

                if ($item !== null) {
                    yield $item;
                }
            }
        }
    }

    /**
     * @return array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}|null
     */
    private function classifyRootFile(string $path, int $minBytes, ?int $defaultYear): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = basename($path);

        // Skip audio duplicates
        if ($ext === 'mp3') {
            return ['tag' => 'skip-dup', 'label' => $path, 'files' => [$path], 'date' => now(), 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => (int) (filesize($path) ?: 0)];
        }

        if (! in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
            return null;
        }

        $bytes = (int) (filesize($path) ?: 0);

        if ($bytes < $minBytes) {
            return ['tag' => 'skip-small', 'label' => "{$filename} → too small (".round($bytes / (1024 * 1024)).' MB)', 'files' => [$path], 'date' => now(), 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => $bytes];
        }

        $parsed = $this->parseDateTimeFromFilename($filename, $defaultYear);

        if ($parsed === null) {
            return ['tag' => 'skip-no-date', 'label' => $filename, 'files' => [$path], 'date' => now(), 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => $bytes];
        }

        [$date, $service, $clientFileDate] = $parsed;

        return [
            'tag' => 'livestream',
            'label' => $filename,
            'files' => [$path],
            'date' => $date,
            'service' => $service,
            'client_file_date' => $clientFileDate,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}|null
     */
    private function classifyYouTubeFile(string $path, int $minBytes, ?int $defaultYear): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
            return null;
        }

        $filename = basename($path);
        $bytes = (int) (filesize($path) ?: 0);

        if ($bytes < $minBytes) {
            return ['tag' => 'skip-small', 'label' => "{$filename} → too small (".round($bytes / (1024 * 1024)).' MB)', 'files' => [$path], 'date' => now(), 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => $bytes];
        }

        $parsed = $this->parseDateTimeFromYouTubeFilename($filename, $defaultYear);

        if ($parsed === null) {
            return ['tag' => 'skip-no-date', 'label' => "YouTubeDownloads/{$filename}", 'files' => [$path], 'date' => now(), 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => $bytes];
        }

        [$date, $service, $clientFileDate] = $parsed;

        return [
            'tag' => 'livestream',
            'label' => "YouTubeDownloads/{$filename}",
            'files' => [$path],
            'date' => $date,
            'service' => $service,
            'client_file_date' => $clientFileDate,
            'bytes' => $bytes,
        ];
    }

    /**
     * Group files in a dated subdir by morning/evening service window, yielding one item per group.
     *
     * @return \Generator<array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}>
     */
    private function groupByService(
        string $subdir,
        Carbon $dirDate,
        int $minBytes,
        bool $includeUnclassified,
    ): \Generator {
        $morningFiles = [];
        $eveningFiles = [];
        $unclassifiedFiles = [];

        foreach ($this->listVideoFiles($subdir, recursive: false) as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Skip audio duplicates silently — they'll be yielded as skip-dup from classifyRootFile if needed
            if ($ext === 'mp3') {
                continue;
            }

            if (! in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $filename = basename($path);
            $hour = $this->extractHourFromFilename($filename);

            if ($hour === null) {
                $unclassifiedFiles[] = $path;

                continue;
            }

            if ($hour >= self::MORNING_START_HOUR && $hour <= self::MORNING_END_HOUR) {
                $morningFiles[] = $path;
            } elseif ($hour >= self::EVENING_START_HOUR && $hour <= self::EVENING_END_HOUR) {
                $eveningFiles[] = $path;
            } else {
                $unclassifiedFiles[] = $path;
            }
        }

        sort($morningFiles, SORT_NATURAL);
        sort($eveningFiles, SORT_NATURAL);

        if ($morningFiles !== []) {
            $bytes = array_sum(array_map(fn (string $f) => (int) (filesize($f) ?: 0), $morningFiles));

            if ($bytes < $minBytes && count($morningFiles) === 1) {
                yield ['tag' => 'skip-small', 'label' => basename($morningFiles[0]).' → too small ('.round($bytes / (1024 * 1024)).' MB)', 'files' => $morningFiles, 'date' => $dirDate, 'service' => SermonService::Morning, 'client_file_date' => '', 'bytes' => $bytes];
            } else {
                $firstHour = $this->extractHourFromFilename(basename($morningFiles[0]));
                $firstMin = $this->extractMinuteFromFilename(basename($morningFiles[0]));
                $clientFileDate = $dirDate->format('Y-m-d').' '.str_pad((string) ($firstHour ?? 10), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($firstMin ?? 0), 2, '0', STR_PAD_LEFT).':00';
                $segmentLabel = count($morningFiles) > 1 ? count($morningFiles).' segments, '.round($bytes / (1024 ** 3), 1).' GB' : basename($morningFiles[0]);
                $tag = count($morningFiles) > 1 ? 'concat' : 'livestream';

                yield [
                    'tag' => $tag,
                    'label' => $dirDate->toDateString()." → morning ({$segmentLabel})",
                    'files' => $morningFiles,
                    'date' => $dirDate,
                    'service' => SermonService::Morning,
                    'client_file_date' => $clientFileDate,
                    'bytes' => $bytes,
                ];
            }
        }

        if ($eveningFiles !== []) {
            $bytes = array_sum(array_map(fn (string $f) => (int) (filesize($f) ?: 0), $eveningFiles));

            if ($bytes < $minBytes && count($eveningFiles) === 1) {
                yield ['tag' => 'skip-small', 'label' => basename($eveningFiles[0]).' → too small ('.round($bytes / (1024 * 1024)).' MB)', 'files' => $eveningFiles, 'date' => $dirDate, 'service' => SermonService::Evening, 'client_file_date' => '', 'bytes' => $bytes];
            } else {
                $firstHour = $this->extractHourFromFilename(basename($eveningFiles[0]));
                $firstMin = $this->extractMinuteFromFilename(basename($eveningFiles[0]));
                $clientFileDate = $dirDate->format('Y-m-d').' '.str_pad((string) ($firstHour ?? 18), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($firstMin ?? 0), 2, '0', STR_PAD_LEFT).':00';
                $segmentLabel = count($eveningFiles) > 1 ? count($eveningFiles).' segments, '.round($bytes / (1024 ** 3), 1).' GB' : basename($eveningFiles[0]);
                $tag = count($eveningFiles) > 1 ? 'concat' : 'livestream';

                yield [
                    'tag' => $tag,
                    'label' => $dirDate->toDateString()." → evening ({$segmentLabel})",
                    'files' => $eveningFiles,
                    'date' => $dirDate,
                    'service' => SermonService::Evening,
                    'client_file_date' => $clientFileDate,
                    'bytes' => $bytes,
                ];
            }
        }

        if ($includeUnclassified) {
            foreach ($unclassifiedFiles as $path) {
                $bytes = (int) (filesize($path) ?: 0);

                yield [
                    'tag' => 'livestream',
                    'label' => $dirDate->toDateString().'/'.basename($path).' (unclassified)',
                    'files' => [$path],
                    'date' => $dirDate,
                    'service' => SermonService::Other,
                    'client_file_date' => $dirDate->format('Y-m-d').' 12:00:00',
                    'bytes' => $bytes,
                ];
            }
        } elseif ($unclassifiedFiles !== []) {
            foreach ($unclassifiedFiles as $path) {
                $bytes = (int) (filesize($path) ?: 0);
                yield ['tag' => 'skip-unclassified', 'label' => $dirDate->toDateString().'/'.basename($path), 'files' => [$path], 'date' => $dirDate, 'service' => SermonService::Other, 'client_file_date' => '', 'bytes' => $bytes];
            }
        }
    }

    /**
     * Dispatch a single work item through the livestream pipeline.
     *
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int, manifest_concatenation?:string}  $item
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return list<array{tag: string, processing_id: string, detail: string|null}>
     */
    private function dispatchItem(
        array $item,
        bool $noConcat,
        bool $reEncodeMismatched,
        ?HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        if (! $stagingContext instanceof HistoricStagingContext) {
            throw new \RuntimeException('Historic video dispatch requires the approved staging and manifest context.');
        }

        return $this->stagingContextRegistry->within(
            $stagingContext,
            fn (): array => $this->dispatchItemWithinStagingContext(
                $item,
                $noConcat,
                $reEncodeMismatched,
                $stagingContext,
                $operation,
                $processingFingerprint,
                $executionProfile,
            ),
        );
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int, manifest_concatenation?:string}  $item
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return list<array{tag: string, processing_id: string, detail: string|null}>
     */
    private function dispatchItemWithinStagingContext(
        array $item,
        bool $noConcat,
        bool $reEncodeMismatched,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        $files = $item['files'];

        if (isset($item['manifest_concatenation'])) {
            if (count($files) === 1) {
                return $this->dispatchFiles($item, $files, $stagingContext, $operation, $processingFingerprint, $executionProfile);
            }

            if (! in_array($item['manifest_concatenation'], ['lossless', 'reencoded'], true)) {
                return [['tag' => 'error', 'processing_id' => '', 'detail' => 'invalid manifest concatenation decision']];
            }

            $stagedFiles = $this->stageSourceFiles($item);

            try {
                return [$item['manifest_concatenation'] === 'lossless'
                    ? $this->concatLossless($item, $stagedFiles, $stagingContext, $operation, $processingFingerprint, $executionProfile)
                    : $this->concatWithReencode($item, $stagedFiles, $stagingContext, $operation, $processingFingerprint, $executionProfile)];
            } finally {
                $this->cleanupStagedFiles($stagedFiles);
            }
        }

        if (count($files) > 1 && ! $noConcat) {
            $stagedFiles = $this->stageSourceFiles($item);

            try {
                return [$this->dispatchMultiSegment(
                    $item,
                    $stagedFiles,
                    $reEncodeMismatched,
                    $stagingContext,
                    $operation,
                    $processingFingerprint,
                    $executionProfile,
                )];
            } finally {
                $this->cleanupStagedFiles($stagedFiles);
            }
        }

        // With --no-concat, dispatch every segment individually so none are silently dropped.
        return $this->dispatchFiles($item, $files, $stagingContext, $operation, $processingFingerprint, $executionProfile);
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  list<string>  $files
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return list<array{tag: string, processing_id: string, detail: string|null}>
     */
    private function dispatchFiles(
        array $item,
        array $files,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        $results = [];
        foreach ($files as $path) {
            $file = new UploadedFile($path, basename($path), null, null, true);

            /**
             * One run per dispatched file, not per item. These segments become
             * separate runs, and a key shared across them would hand every file
             * after the first back the first run's id as a "successful"
             * dispatch — silently collapsing the item.
             */
            $jobKey = $this->manifestItemDedupKey($item, $stagingContext, $path);

            $result = $this->processor->process(
                type: 'livestream',
                file: $file,
                clientFileDate: $item['client_file_date'] !== '' ? $item['client_file_date'] : null,
                options: [
                    'dedup_key' => $jobKey,
                    'skip_file_hash' => true,
                    'processing_metadata' => $this->historicImportMetadata(
                        $item,
                        $path,
                        'none',
                        $stagingContext,
                        $jobKey,
                        $operation,
                        $processingFingerprint,
                        $executionProfile,
                    ),
                ],
                serviceOverride: $item['service'],
                serviceDateOverride: $item['date']->toDateString(),
            );

            if (! $result->success) {
                $results[] = ['tag' => 'error', 'processing_id' => '', 'detail' => $result->message];
            } else {
                $results[] = ['tag' => 'livestream', 'processing_id' => $result->processingId, 'detail' => null];
            }
        }

        return $results;
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  list<StagedFile>  $stagedFiles
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function dispatchMultiSegment(
        array $item,
        array $stagedFiles,
        bool $reEncodeMismatched,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        $files = $this->stagedFullPaths($stagedFiles);

        if (! $this->codecsMatch($files)) {
            if (! $reEncodeMismatched) {
                return ['tag' => 'error', 'processing_id' => '', 'detail' => 'codec mismatch in segment group (use --reencode-mismatched to override)'];
            }

            return $this->concatWithReencode(
                $item,
                $stagedFiles,
                $stagingContext,
                $operation,
                $processingFingerprint,
                $executionProfile,
            );
        }

        return $this->concatLossless(
            $item,
            $stagedFiles,
            $stagingContext,
            $operation,
            $processingFingerprint,
            $executionProfile,
        );
    }

    /**
     * Copy concat inputs once into the operation-owned staging root. The processor never receives
     * an archive path, and a failed copy removes only the unique working copies created here.
     *
     * @param  array{files:list<string>,source_files?:list<array{relative_path:string,sha256:string,byte_size:int}>}  $item
     * @return list<array{temp_path:string,full_path:string,original_filename:string,file_size:int,mime_type:string|null}>
     */
    private function stageSourceFiles(array $item): array
    {
        $stagedFiles = [];

        try {
            foreach ($item['files'] as $offset => $path) {
                $file = new UploadedFile($path, basename($path), null, null, true);
                $expectedSize = $this->approvedSourceSize($item, $offset);

                $stagedFiles[] = $expectedSize === null
                    ? $this->videoStorageService->storeUploadedVideo($file)
                    : $this->videoStorageService->storeUploadedVideo($file, $expectedSize);
            }
        } catch (\Throwable $exception) {
            $this->cleanupStagedFiles($stagedFiles);

            throw $exception;
        }

        return $stagedFiles;
    }

    /** @param  array<string, mixed>  $item */
    private function approvedSourceSize(array $item, int $offset): ?int
    {
        $source = $item['source_files'][$offset] ?? null;
        $size = is_array($source) ? ($source['byte_size'] ?? null) : null;

        return is_int($size) && $size >= 0 ? $size : null;
    }

    /** @param list<StagedFile> $stagedFiles */
    private function cleanupStagedFiles(array $stagedFiles): void
    {
        $this->videoStorageService->cleanupTemporaryFiles(array_values(array_filter(
            array_map(static fn (array $staged): string => $staged['temp_path'], $stagedFiles),
            static fn (string $path): bool => $path !== '',
        )));
    }

    /**
     * @param  list<StagedFile>  $stagedFiles
     * @return list<string>
     */
    private function stagedFullPaths(array $stagedFiles): array
    {
        return array_map(
            static fn (array $staged): string => $staged['full_path'],
            $stagedFiles,
        );
    }

    /**
     * @param  list<string>  $files
     */
    private function codecsMatch(array $files): bool
    {
        if (count($files) < 2) {
            return true;
        }

        $reference = $this->probeCodecInfo($files[0]);

        if ($reference === null) {
            return false;
        }

        foreach (array_slice($files, 1) as $f) {
            $info = $this->probeCodecInfo($f);

            if ($info === null || $info !== $reference) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string|null A normalized fingerprint of video_codec:audio_codec:resolution:fps:sample_rate
     */
    private function probeCodecInfo(string $path): ?string
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        $cmd = escapeshellarg($ffprobePath)
            .' -v quiet -print_format json -show_streams '
            .escapeshellarg($path);

        $output = shell_exec($cmd);

        if (! is_string($output)) {
            return null;
        }

        /** @var array{streams?: list<array{codec_type?: string, codec_name?: string, width?: int, height?: int, r_frame_rate?: string, sample_rate?: string}>}|null $data */
        $data = json_decode($output, true);

        if (! is_array($data)) {
            return null;
        }

        $videoCodec = null;
        $audioCodec = null;
        $resolution = null;
        $fps = null;
        $sampleRate = null;

        foreach ($data['streams'] ?? [] as $stream) {
            $codecType = $stream['codec_type'] ?? null;

            if ($codecType === 'video' && $videoCodec === null) {
                $videoCodec = $stream['codec_name'] ?? null;
                $resolution = isset($stream['width'], $stream['height'])
                    ? "{$stream['width']}x{$stream['height']}"
                    : null;
                $fps = $stream['r_frame_rate'] ?? null;
            }

            if ($codecType === 'audio' && $audioCodec === null) {
                $audioCodec = $stream['codec_name'] ?? null;
                $sampleRate = $stream['sample_rate'] ?? null;
            }
        }

        return "{$videoCodec}:{$audioCodec}:{$resolution}:{$fps}:{$sampleRate}";
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  list<StagedFile>  $stagedFiles
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function concatLossless(
        array $item,
        array $stagedFiles,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        $concatPath = $this->buildConcatFile($this->stagedFullPaths($stagedFiles));

        if ($concatPath === null) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => 'failed to build FFmpeg concat list'];
        }

        $outputRelativePath = self::TEMP_DIR.'/'.Str::uuid().'.mkv';
        $outputPath = Storage::disk($this->historicTempDisk())->path($outputRelativePath);
        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

        $cmd = escapeshellarg($ffmpegPath)
            .' -f concat -safe 0 -i '.escapeshellarg($concatPath['absolute_path'])
            .' -c copy '.escapeshellarg($outputPath)
            .' 2>&1';

        exec($cmd, $cmdOutput, $returnCode);

        Storage::disk($this->historicTempDisk())->delete($concatPath['relative_path']);

        if ($returnCode !== 0) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            return ['tag' => 'error', 'processing_id' => '', 'detail' => 'FFmpeg concat failed: '.implode(' ', array_slice($cmdOutput, -3))];
        }

        try {
            $result = $this->dispatchConcatFile(
                $outputPath,
                $outputRelativePath,
                $item,
                'lossless',
                $stagingContext,
                $operation,
                $processingFingerprint,
                $executionProfile,
            );
        } catch (\Throwable $exception) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            throw $exception;
        }

        if (! $result->success) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            return ['tag' => 'error', 'processing_id' => '', 'detail' => $result->message];
        }

        return ['tag' => 'concat', 'processing_id' => $result->processingId, 'detail' => null];
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  list<StagedFile>  $stagedFiles
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function concatWithReencode(
        array $item,
        array $stagedFiles,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): array {
        $this->ensureTempDir();

        $outputRelativePath = self::TEMP_DIR.'/'.Str::uuid().'.mp4';
        $outputPath = Storage::disk($this->historicTempDisk())->path($outputRelativePath);
        try {
            $this->reencodeConcatenator->concatenate(
                $this->stagedFullPaths($stagedFiles),
                $outputPath,
            );
        } catch (\RuntimeException $exception) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            return ['tag' => 'error', 'processing_id' => '', 'detail' => $exception->getMessage()];
        }

        try {
            $result = $this->dispatchConcatFile(
                $outputPath,
                $outputRelativePath,
                $item,
                're-encoded',
                $stagingContext,
                $operation,
                $processingFingerprint,
                $executionProfile,
            );
        } catch (\Throwable $exception) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            throw $exception;
        }

        if (! $result->success) {
            Storage::disk($this->historicTempDisk())->delete($outputRelativePath);

            return ['tag' => 'error', 'processing_id' => '', 'detail' => $result->message];
        }

        return ['tag' => 'concat-reencoded', 'processing_id' => $result->processingId, 'detail' => null];
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  'lossless'|'re-encoded'  $concatenation
     * @param  array<string, mixed>|null  $processingFingerprint
     * @param  array<string, array{routing_fingerprint:string,worker_width:int}>|null  $executionProfile
     */
    private function dispatchConcatFile(
        string $outputPath,
        string $outputRelativePath,
        array $item,
        string $concatenation,
        HistoricStagingContext $stagingContext,
        ?HistoricImportOperation $operation,
        ?array $processingFingerprint,
        ?array $executionProfile,
    ): ProcessingResult {
        $filename = $item['date']->format('Y-m-d').' '.$item['service']->value.'.mkv';
        $file = new UploadedFile($outputPath, $filename, null, null, true);
        $outputSize = $this->exactFileSize($outputPath);

        if ($outputSize === null) {
            throw new \RuntimeException('Historic concat derivative is missing or unreadable.');
        }

        /**
         * A concatenated item produces exactly one run, so its key is the item's
         * alone. The output path is a fresh temporary name on every attempt and
         * must not reach the key, or a retry would fail to match its own run.
         */
        $jobKey = $this->manifestItemDedupKey($item, $stagingContext);

        return $this->processor->process(
            type: 'livestream',
            file: $file,
            clientFileDate: $item['client_file_date'] !== '' ? $item['client_file_date'] : null,
            options: [
                'dedup_key' => $jobKey,
                'skip_file_hash' => true,
                'processing_metadata' => $this->historicImportMetadata(
                    $item,
                    $outputPath,
                    $concatenation,
                    $stagingContext,
                    $jobKey,
                    $operation,
                    $processingFingerprint,
                    $executionProfile,
                    stagedDerivative: [
                        'path' => $outputRelativePath,
                        'size' => $outputSize,
                        'manifest_item_key' => $this->manifestItemKey($item),
                        'dedup_key' => $jobKey,
                        'operation_id' => $operation?->operation_id,
                    ],
                ),
            ],
            serviceOverride: $item['service'],
            serviceDateOverride: $item['date']->toDateString(),
        );
    }

    /**
     * @param  list<string>  $files
     * @return array{absolute_path:string,relative_path:string}|null
     */
    private function buildConcatFile(array $files): ?array
    {
        $this->ensureTempDir();
        $relativePath = self::TEMP_DIR.'/concat-'.Str::uuid().'.txt';
        $listPath = Storage::disk($this->historicTempDisk())->path($relativePath);

        $lines = array_map(fn (string $f) => "file '".addslashes($f)."'", $files);
        $written = file_put_contents($listPath, implode("\n", $lines)."\n");

        return $written !== false
            ? ['absolute_path' => $listPath, 'relative_path' => $relativePath]
            : null;
    }

    private function ensureTempDir(): void
    {
        Storage::disk($this->historicTempDisk())->makeDirectory(self::TEMP_DIR);
    }

    private function exactFileSize(string $path): ?int
    {
        clearstatcache(true, $path);

        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        return is_int($size) ? $size : null;
    }

    /**
     * A manifest plan deliberately contains portable relative paths, sizes and hashes. Reconstruct
     * the approved root from the dispatched path and check the selected source's metadata before a
     * processor or FFmpeg can consume it. The frozen hash is provenance evidence, not a dispatch
     * read; the staging copy is the one content traversal for this pass.
     *
     * @param  array<string, mixed>  $item
     */
    private function assertApprovedSourceMetadata(array $item): void
    {
        $sourceFiles = $item['source_files'] ?? null;

        if (! is_array($sourceFiles) || $sourceFiles === []) {
            return;
        }

        $files = $item['files'] ?? null;

        if (
            ! is_array($files)
            || ! array_is_list($sourceFiles)
            || ! array_is_list($files)
            || count($sourceFiles) !== count($files)
        ) {
            throw new HistoricSourceIntegrityException('Historic video work item does not bind every dispatched file to approved source evidence.');
        }

        foreach ($sourceFiles as $offset => $source) {
            if (
                ! is_array($source)
                || ! is_string($source['relative_path'] ?? null)
                || ! is_string($source['sha256'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $source['sha256']) !== 1
                || ! is_int($source['byte_size'] ?? null)
                || $source['byte_size'] < 0
            ) {
                throw new HistoricSourceIntegrityException('Historic video source evidence is incomplete.');
            }

            $relativePath = $source['relative_path'];
            $path = $files[$offset] ?? null;

            if (
                $relativePath === ''
                || str_starts_with($relativePath, '/')
                || str_contains($relativePath, '\\')
                || in_array('..', explode('/', $relativePath), true)
                || ! is_string($path)
            ) {
                throw new HistoricSourceIntegrityException('Historic video source path is not the approved relative path.');
            }

            $suffix = DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (! str_ends_with($path, $suffix)) {
                throw new HistoricSourceIntegrityException("Historic video source path is not the approved relative path: {$relativePath}");
            }

            $root = substr($path, 0, -strlen($suffix));

            if (
                $root === ''
                || $this->containsSourceSymlink($root, $relativePath)
            ) {
                throw new HistoricSourceIntegrityException("Historic video source integrity failure: {$relativePath}");
            }

            if (! is_dir($root)) {
                throw new HistoricSourceMountException("Historic video source is no longer available: {$relativePath}");
            }

            $resolvedRoot = realpath($root);

            if (! is_string($resolvedRoot)) {
                throw new HistoricSourceMountException("Historic video source root could not be read: {$relativePath}");
            }

            if ($resolvedRoot !== $root) {
                throw new HistoricSourceIntegrityException("Historic video source integrity failure: {$relativePath}");
            }

            if (! is_file($path) || ! is_readable($path)) {
                throw new HistoricSourceMountException("Historic video source is no longer available: {$relativePath}");
            }

            $resolvedPath = realpath($path);

            if (! is_string($resolvedPath) || ! $this->isWithinRoot($resolvedPath, $resolvedRoot)) {
                throw new HistoricSourceIntegrityException("Historic video source escapes its approved root: {$relativePath}");
            }

            clearstatcache(true, $path);
            $size = filesize($path);

            if ($size === false) {
                throw new HistoricSourceMountException("Historic video source could not be read: {$relativePath}");
            }

            if ($size !== $source['byte_size']) {
                throw new HistoricSourceIntegrityException("Historic video source changed after approval: {$relativePath}");
            }
        }
    }

    private function containsSourceSymlink(string $root, string $relativePath): bool
    {
        if (is_link($root)) {
            return true;
        }

        $path = rtrim($root, DIRECTORY_SEPARATOR);

        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * The exact `dedup_key` set this item would dispatch, mirroring
     * {@see self::dispatchItemWithinStagingContext()} branch for branch.
     *
     * It must keep mirroring it: a key computed here that the dispatch would not use makes a
     * resumable pass either re-dispatch finished work or skip unfinished work, and both failures
     * are silent. The branches are deliberately duplicated rather than shared, because the dispatch
     * side decides per item *while concatenating* and this side must decide before spending that
     * time.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function plannedJobKeys(array $item, HistoricStagingContext $stagingContext, bool $noConcat): array
    {
        $files = $item['files'] ?? [];

        if (! is_array($files) || $files === []) {
            return [];
        }

        if (isset($item['manifest_concatenation'])) {
            return count($files) === 1
                ? [$this->manifestItemDedupKey($item, $stagingContext, $files[0])]
                : [$this->manifestItemDedupKey($item, $stagingContext)];
        }

        if (count($files) > 1 && ! $noConcat) {
            return [$this->manifestItemDedupKey($item, $stagingContext)];
        }

        $keys = [];

        foreach ($files as $path) {
            $keys[] = $this->manifestItemDedupKey($item, $stagingContext, $path);
        }

        return $keys;
    }

    /**
     * Which of this item's own planned job keys already hold a completed run.
     *
     * Returned in planned order so the caller can compare it against the full planned set by
     * equality: a partial match means the item is genuinely half-done and must proceed to dispatch,
     * where the processor's `dedup_key` reuse settles the finished half without re-running it.
     *
     * @param  list<string>  $planned
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function completedOwnJobKeys(array $planned, Collection $runs): array
    {
        $completed = $runs
            ->filter(static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed)
            ->map(fn (MediaProcessingLog $run): ?string => $this->jobKeyForRun($run))
            ->filter(static fn (?string $jobKey): bool => is_string($jobKey))
            ->values()
            ->all();

        return array_values(array_filter(
            $planned,
            static fn (string $key): bool => in_array($key, $completed, true),
        ));
    }

    /**
     * Match both the live unique lock and the immutable metadata identity. Historic runs created
     * before resumability was fixed cleared `dedup_key` at terminal transitions, but their
     * hash-bound metadata still carries the exact job key and must remain resumable.
     *
     * @param  list<string>  $jobKeys
     * @return Collection<int, MediaProcessingLog>
     */
    private function ownRunsForJobKeys(array $jobKeys): Collection
    {
        return MediaProcessingLog::query()
            ->where(function (Builder $query) use ($jobKeys): void {
                $query
                    ->whereIn('dedup_key', $jobKeys)
                    ->orWhereIn('processing_metadata->historic_import->job_key', $jobKeys);
            })
            ->get();
    }

    /**
     * Older terminal transitions released the live key, so more than one row can carry the same
     * immutable historic job key. Act on exactly one: completed work wins, then active work, then
     * the newest failed attempt. This prevents retrying a stale failure beside a successful or
     * currently running replacement.
     *
     * @param  list<string>  $plannedJobKeys
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return Collection<int, MediaProcessingLog>
     */
    private function preferredOwnRunsForJobKeys(array $plannedJobKeys, Collection $runs): Collection
    {
        return collect($plannedJobKeys)
            ->map(function (string $jobKey) use ($runs): ?MediaProcessingLog {
                return $runs
                    ->filter(fn (MediaProcessingLog $run): bool => $this->jobKeyForRun($run) === $jobKey)
                    ->sortByDesc(fn (MediaProcessingLog $run): array => [
                        $this->historicRunPriority($run),
                        $run->id,
                    ])
                    ->first();
            })
            ->filter(static fn (?MediaProcessingLog $run): bool => $run instanceof MediaProcessingLog)
            ->values();
    }

    private function historicRunPriority(MediaProcessingLog $run): int
    {
        if ($run->status === ProcessingStatus::Completed) {
            return 3;
        }

        if (in_array($run->status, [ProcessingStatus::Pending, ProcessingStatus::Started, ProcessingStatus::Processing], true)) {
            return 2;
        }

        return $run->status === ProcessingStatus::Failed ? 1 : 0;
    }

    private function jobKeyForRun(MediaProcessingLog $run): ?string
    {
        $jobKey = $run->historicImportJobKey() ?? $run->dedup_key;

        return is_string($jobKey) && $jobKey !== '' ? $jobKey : null;
    }

    private function isStructureValidationFailure(MediaProcessingLog $run): bool
    {
        return ($run->manualReviewMetadata()['reason_code'] ?? null) === 'llm_structure_validation_failed';
    }

    private function checkExistence(Carbon $date, SermonService $service): ?string
    {
        $base = MediaProcessingLog::query()
            ->where('extracted_date', $date->toDateString())
            ->where('extracted_service', $service->value)
            ->where('processing_type', MediaType::Livestream->value);

        if ($base->clone()->where('status', ProcessingStatus::Completed->value)->exists()) {
            return 'skip-exists';
        }

        if ($base->clone()->whereIn('status', [ProcessingStatus::Pending->value, ProcessingStatus::Processing->value])->exists()) {
            return 'skip-inflight';
        }

        if (MediaProcessingLog::query()
            ->where('extracted_date', $date->toDateString())
            ->where('extracted_service', $service->value)
            ->awaitingManualSermonReview()
            ->exists()) {
            return 'skip-pending-review';
        }

        return null;
    }

    private function existenceDetail(Carbon $date, SermonService $service, string $tag): ?string
    {
        $log = MediaProcessingLog::query()
            ->where('extracted_date', $date->toDateString())
            ->where('extracted_service', $service->value)
            ->where('processing_type', MediaType::Livestream->value)
            ->orderByDesc('created_at')
            ->first();

        if ($log === null) {
            return null;
        }

        return match ($tag) {
            'skip-exists' => "livestream already processed (log #{$log->processing_id})",
            'skip-inflight' => "livestream already in pipeline (processing_id={$log->processing_id})",
            'skip-pending-review' => "awaiting manual sermon review (log #{$log->processing_id})",
            default => null,
        };
    }

    /**
     * @param  list<string>  $files
     */
    private function hasTempDiskSpace(array $files, int $minFreeGb): bool
    {
        // An unmeasurable temp volume must short-circuit before the requirement below, because
        // `max($minFreeBytes, $totalFileBytes * 2)` still demands twice the source size however low
        // the floor is set. Without this, a volume whose free space cannot be read silently skips
        // every item — the failure mode the error branch below exists to make loud.
        if (TempDiskSpace::checksDisabled()) {
            return true;
        }

        $freeBytes = disk_free_space($this->tempDiskPath());

        if ($freeBytes === false) {
            // Fail closed: an unmeasurable disk is not evidence of headroom, and
            // running out mid-import corrupts a run. But say so loudly — silently
            // skipping every item is a batch that looks like progress and is not.
            Log::error('Historic import cannot measure temp disk free space; skipping work', [
                'temp_disk_path' => $this->tempDiskPath(),
            ]);

            return false;
        }

        $minFreeBytes = $minFreeGb * 1024 ** 3;
        $totalFileBytes = array_sum(array_map(fn (string $f) => (int) (filesize($f) ?: 0), $files));
        $required = max($minFreeBytes, $totalFileBytes * 2);

        return $freeBytes >= $required;
    }

    private function tempDiskPath(): string
    {
        return TempDiskSpace::path();
    }

    /**
     * WP-A4: enough provenance to identify a service's source files on the drive
     * by hash, long after the drive is unmounted.
     *
     * @param  array{tag: string, label: string, files: list<string>, editorial_facts?: array<string, string|null>|null, manifest_corroboration?: HistoricVideoCorroborationGrade|null}  $item
     * @param  'none'|'lossless'|'re-encoded'  $concatenation  How the dispatched file was produced
     * @param  string|null  $jobKey  The dedup key this dispatch actually used; recorded so the
     *                               orchestrator derives chain identity from the same value
     * @param  array<string, mixed>|null  $processingFingerprint  Pass-scoped durable identity
     * @param  array<string, mixed>|null  $executionProfile  Pass-scoped queue/worker evidence
     * @param  array<string, mixed>|null  $stagedDerivative  Operation-owned concat output to adopt
     * @return array<string, mixed>
     */
    private function historicImportMetadata(
        array $item,
        string $path,
        string $concatenation = 'none',
        ?HistoricStagingContext $stagingContext = null,
        ?string $jobKey = null,
        ?HistoricImportOperation $operation = null,
        ?array $processingFingerprint = null,
        ?array $executionProfile = null,
        ?array $stagedDerivative = null,
    ): array {
        $sources = $this->historicSourceMetadata($item);

        $historicImport = [
            'tag' => $item['tag'],
            'label' => $item['label'],
            'sources' => $sources,
            'concatenation' => $concatenation,
            'codec_fingerprint' => $this->probeCodecInfo($path),
            'drive_volume' => $this->driveVolume($item['files'][0]),
            'imported_at' => now()->toISOString(),
            'editorial_facts' => is_array($item['editorial_facts'] ?? null)
                ? $item['editorial_facts']
                : null,
        ];

        if ($this->hasApprovedSourceMetadata($item)) {
            $historicImport['sha256_basis'] = 'approved_manifest_not_reverified_at_dispatch';
        }

        if (! $stagingContext instanceof HistoricStagingContext || $jobKey === null) {
            return ['historic_import' => $historicImport];
        }

        $historicImport['manifest_item_key'] = $this->manifestItemKey($item);
        $historicImport['manifest_hash'] = $stagingContext->manifestHash;
        $historicImport['plan_hash'] = $stagingContext->planHash;
        $historicImport['staging_context'] = $stagingContext->toArray();
        $historicImport['job_key'] = $jobKey;
        $historicImport['operation_id'] = $operation?->operation_id;
        $historicImport['execution_profile'] = $executionProfile
            ?? ($this->throughput ?? app(HistoricProcessingThroughput::class))->executionProfile();

        if ($stagedDerivative !== null) {
            $historicImport['staged_derivative'] = $stagedDerivative;
        }

        /**
         * The grade travels with the recording so the projector can decide what it is entitled to
         * corroborate (plan §2.5 safety qualification, §0.1 slice 3). Recorded here rather than
         * re-derived downstream because it is a *curated* fact: the operator approved it in the
         * manifest, `manifest_hash` above covers it, and re-measuring a duration later could
         * disagree with what was approved.
         *
         * Null when a work item carries no grade — an unmanifested or legacy dispatch. That stays
         * null deliberately: {@see LivestreamSourceAdapter}
         * treats an ungraded historic recording as neutral, so an unknown grade can never be read
         * as a full one.
         */
        $grade = $item['manifest_corroboration'] ?? null;
        $historicImport['corroboration_grade'] = $grade instanceof HistoricVideoCorroborationGrade
            ? $grade->value
            : null;

        return [
            'historic_import' => $historicImport,
            'processing_fingerprint' => $processingFingerprint
                ?? $this->fingerprints->forStagingContext($stagingContext),
        ];
    }

    /**
     * @param  array{files:list<string>,source_files?:list<array{relative_path:string,sha256:string,byte_size:int}>}  $item
     * @return list<array{path:string,size:int,mtime:int|null,sha256:string|null}>
     */
    private function historicSourceMetadata(array $item): array
    {
        $sourceFiles = $item['source_files'] ?? [];
        $hasApprovedMetadata = $this->hasApprovedSourceMetadata($item);

        return array_map(
            function (string $sourcePath, int $offset) use ($sourceFiles, $hasApprovedMetadata): array {
                clearstatcache(true, $sourcePath);
                $mtime = filemtime($sourcePath);

                if ($hasApprovedMetadata) {
                    $source = $sourceFiles[$offset];

                    return [
                        'path' => $source['relative_path'],
                        'size' => $source['byte_size'],
                        'mtime' => is_int($mtime) ? $mtime : null,
                        'sha256' => $source['sha256'],
                    ];
                }

                $size = filesize($sourcePath);

                return [
                    'path' => $sourcePath,
                    'size' => is_int($size) ? $size : 0,
                    'mtime' => is_int($mtime) ? $mtime : null,
                    'sha256' => null,
                ];
            },
            $item['files'],
            array_keys($item['files']),
        );
    }

    /** @param array<string, mixed> $item */
    private function hasApprovedSourceMetadata(array $item): bool
    {
        $sourceFiles = $item['source_files'] ?? null;
        $files = $item['files'] ?? null;

        if (! is_array($sourceFiles) || ! is_array($files) || count($sourceFiles) !== count($files) || $sourceFiles === []) {
            return false;
        }

        foreach ($sourceFiles as $source) {
            if (
                ! is_array($source)
                || ! is_string($source['relative_path'] ?? null)
                || ! is_string($source['sha256'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $source['sha256']) !== 1
                || ! is_int($source['byte_size'] ?? null)
                || $source['byte_size'] < 0
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * MediaProcessingLog has a unique dedup_key index. Keeping this key stable
     * across retries makes that durable database constraint the manifest item's
     * lock, including after a worker crash or restart. `$sourcePath` separates
     * the runs of an item dispatched one file at a time; it must stay out of the
     * key for anything whose input is regenerated per attempt.
     *
     * @param  array<string, mixed>  $item
     */
    private function manifestItemDedupKey(
        array $item,
        HistoricStagingContext $stagingContext,
        ?string $sourcePath = null,
    ): string {
        $identity = "historic-video\0{$stagingContext->manifestHash}\0{$this->manifestItemKey($item)}";

        if ($sourcePath !== null) {
            $identity .= "\0{$sourcePath}";
        }

        return hash('sha256', $identity);
    }

    /** @param  array<string, mixed>  $item */
    private function manifestItemKey(array $item): string
    {
        $itemKey = $item['manifest_item_key'] ?? null;

        if (is_string($itemKey) && $itemKey !== '') {
            return $itemKey;
        }

        $sources = $item['files'] ?? [];

        if (! is_array($sources) || $sources === []) {
            throw new \RuntimeException('Historic video dispatch requires every item to identify its source files.');
        }

        return 'legacy-'.hash('sha256', implode("\0", $sources));
    }

    private function historicTempDisk(): string
    {
        return (string) config('media-processing.storage.temp_disk', 'local');
    }

    private function driveVolume(string $path): ?string
    {
        if (preg_match('#^/Volumes/([^/]+)#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  ImportMetrics  $metrics
     * @param  list<array{decision: string, label: string, work_item_tag?: string, processing_id?: string}>  $decisions
     */
    private function writeReport(string $path, array $metrics, array $decisions): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create historic import report directory: {$directory}");
        }

        $json = json_encode([
            'format' => 'crockenhill.historic-import-report',
            'generated_at' => now()->toISOString(),
            'metrics' => $metrics,
            'items' => $decisions,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json.PHP_EOL) === false || ! chmod($path, 0600)) {
            throw new \RuntimeException("Unable to write secure historic import report: {$path}");
        }
    }

    /**
     * @return list<string> Absolute paths of video files (non-recursive by default)
     */
    private function listVideoFiles(string $directory, bool $recursive = false): array
    {
        $files = [];

        $flags = \FilesystemIterator::SKIP_DOTS;
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, $flags))
            : new \FilesystemIterator($directory, $flags);

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());

            if ($ext === 'mp3' || in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * @return list<string> Absolute paths of immediate subdirectories
     */
    private function listSubdirectories(string $directory): array
    {
        $dirs = [];

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir()) {
                $dirs[] = $entry->getPathname();
            }
        }

        sort($dirs, SORT_NATURAL);

        return $dirs;
    }

    /**
     * Parse a timestamp filename like "2022-01-16 18-38-15.mkv" or "2023-11-05_10-42.mkv".
     *
     * @return array{Carbon, SermonService, string}|null
     */
    private function parseDateTimeFromFilename(string $filename, ?int $defaultYear): ?array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        // Pattern: YYYY-MM-DD HH-MM or YYYY-MM-DD HH-MM-SS or YYYY-MM-DD_HH-MM
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[\s_](\d{2})-(\d{2})/', $stem, $m)) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $m[1]);

                if (! $date instanceof Carbon) {
                    return null;
                }

                $hour = (int) $m[2];
                $minute = (int) $m[3];
                $clientFileDate = "{$m[1]} {$m[2]}:{$m[3]}:00";

                return [$date, $this->serviceFromHour($hour), $clientFileDate];
            } catch (\Throwable) {
                return null;
            }
        }

        // Pattern from subdir: HH-MM.mkv or HH-MM-SS.mkv (no date in filename; caller passes parent date)
        if (preg_match('/^(\d{2})-(\d{2})/', $stem, $m)) {
            $hour = (int) $m[1];

            return [now(), $this->serviceFromHour($hour), ''];
        }

        return null;
    }

    /**
     * Try several YouTube filename date formats.
     * e.g. "Carols By Candlelight - 20 December 2020.mp4", "Sermon 12 April 2021.mp4"
     *
     * @return array{Carbon, SermonService, string}|null
     */
    private function parseDateTimeFromYouTubeFilename(string $filename, ?int $defaultYear): ?array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        // Full date with year: "20 December 2020"
        if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $stem, $m)) {
            try {
                $date = Carbon::createFromFormat('j F Y', "{$m[1]} {$m[2]} {$m[3]}");

                if ($date instanceof Carbon) {
                    return [$date, SermonService::Morning, $date->format('Y-m-d').' 10:30:00'];
                }
            } catch (\Throwable) {
            }
        }

        // Date first: "2021-05-09" style embedded
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $stem, $m)) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $m[1]);

                if ($date instanceof Carbon) {
                    return [$date, SermonService::Morning, "{$m[1]} 10:30:00"];
                }
            } catch (\Throwable) {
            }
        }

        // Day-month only with defaultYear fallback: "12 April" or "12 April_"
        if ($defaultYear !== null && preg_match('/(\d{1,2})\s+([A-Za-z]+)/', $stem, $m)) {
            try {
                $date = Carbon::createFromFormat('j F Y', "{$m[1]} {$m[2]} {$defaultYear}");

                if ($date instanceof Carbon) {
                    return [$date, SermonService::Morning, $date->format('Y-m-d').' 10:30:00'];
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function serviceFromHour(int $hour): SermonService
    {
        if ($hour >= self::MORNING_START_HOUR && $hour <= self::MORNING_END_HOUR) {
            return SermonService::Morning;
        }

        if ($hour >= self::EVENING_START_HOUR && $hour <= self::EVENING_END_HOUR) {
            return SermonService::Evening;
        }

        return SermonService::Other;
    }

    private function extractHourFromFilename(string $filename): ?int
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        if (preg_match('/^(\d{2})-(\d{2})/', $stem, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractMinuteFromFilename(string $filename): ?int
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        if (preg_match('/^\d{2}-(\d{2})/', $stem, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
