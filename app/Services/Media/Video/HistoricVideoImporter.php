<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\ProcessingResult;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\TempDiskSpace;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
 *     skipped_inflight: int,
 *     skipped_pending_review: int,
 *     skipped_small: int,
 *     skipped_audio_dup: int,
 *     skipped_no_date: int,
 *     skipped_unclassified: int,
 *     skipped_low_disk: int,
 *     errors: int,
 *     terminal_failed: int,
 *     terminal_cancelled: int,
 *     terminal_skipped: int,
 *     timed_out: int,
 *     bytes_processed: int,
 *     bytes_skipped: int
 * }
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
        private readonly HistoricStagingGuard $stagingGuard,
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
     *     skipped_inflight: int,
     *     skipped_pending_review: int,
     *     skipped_small: int,
     *     skipped_audio_dup: int,
     *     skipped_no_date: int,
     *     skipped_unclassified: int,
     *     skipped_low_disk: int,
     *     errors: int,
     *     terminal_failed: int,
     *     terminal_cancelled: int,
     *     terminal_skipped: int,
     *     timed_out: int,
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
        int $parallel,
        int $pollIntervalSeconds,
        int $perFileTimeoutSeconds,
        int $limit,
        ?\Closure $onProgress = null,
        ?Carbon $from = null,
        ?Carbon $until = null,
        ?string $reportPath = null,
        ?array $approvedWorkItems = null,
    ): array {
        if (! $dryRun) {
            $this->stagingGuard->assertLocalProcessingIsIsolated();
        }

        $metrics = [
            'dispatched' => 0,
            'concatenated' => 0,
            'concatenated_reencoded' => 0,
            'enriched' => 0,
            'skipped_exists' => 0,
            'skipped_inflight' => 0,
            'skipped_pending_review' => 0,
            'skipped_small' => 0,
            'skipped_audio_dup' => 0,
            'skipped_no_date' => 0,
            'skipped_unclassified' => 0,
            'skipped_low_disk' => 0,
            'errors' => 0,
            'terminal_failed' => 0,
            'terminal_cancelled' => 0,
            'terminal_skipped' => 0,
            'timed_out' => 0,
            'bytes_processed' => 0,
            'bytes_skipped' => 0,
        ];

        $inflight = [];
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

            if (! $force) {
                $existenceTag = $this->checkExistence($date, $service);

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

            if ($parallel === 1 && $inflight !== []) {
                $this->recordTerminalOutcomes($metrics, $this->waitForInflight($inflight, $pollIntervalSeconds, $perFileTimeoutSeconds));
                $inflight = [];
            }

            try {
                $results = $this->dispatchItem($item, $noConcat, $reEncodeMismatched);

                foreach ($results as $result) {
                    if ($result['tag'] === 'error') {
                        $metrics['errors']++;
                        $onProgress?->__invoke('[error]', $label, $result['detail']);

                        continue;
                    }

                    $processingId = $result['processing_id'];
                    $inflight[] = $processingId;
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

                    if (count($inflight) >= $parallel) {
                        $this->recordTerminalOutcomes($metrics, $this->waitForInflight($inflight, $pollIntervalSeconds, $perFileTimeoutSeconds));
                        $inflight = [];
                    }
                }
            } catch (\Throwable $e) {
                $metrics['errors']++;
                Log::error('Historic video import dispatch failed', [
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
                $onProgress?->__invoke('[error]', $label, $e->getMessage());
            }
        }

        if ($inflight !== []) {
            $this->recordTerminalOutcomes($metrics, $this->waitForInflight($inflight, $pollIntervalSeconds, $perFileTimeoutSeconds));
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
     * @return list<array{tag: string, processing_id: string, detail: string|null}>
     */
    private function dispatchItem(array $item, bool $noConcat, bool $reEncodeMismatched): array
    {
        $files = $item['files'];

        if (isset($item['manifest_concatenation'])) {
            if (count($files) === 1) {
                return $this->dispatchFiles($item, $files);
            }

            return [match ($item['manifest_concatenation']) {
                'lossless' => $this->concatLossless($item),
                'reencoded' => $this->concatWithReencode($item),
                default => ['tag' => 'error', 'processing_id' => '', 'detail' => 'invalid manifest concatenation decision'],
            }];
        }

        if (count($files) > 1 && ! $noConcat) {
            return [$this->dispatchMultiSegment($item, $reEncodeMismatched)];
        }

        // With --no-concat, dispatch every segment individually so none are silently dropped.
        return $this->dispatchFiles($item, $files);
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  list<string>  $files
     * @return list<array{tag: string, processing_id: string, detail: string|null}>
     */
    private function dispatchFiles(array $item, array $files): array
    {
        $results = [];
        foreach ($files as $path) {
            $file = new UploadedFile($path, basename($path), null, null, true);

            $result = $this->processor->process(
                type: 'livestream',
                file: $file,
                clientFileDate: $item['client_file_date'] !== '' ? $item['client_file_date'] : null,
                options: ['processing_metadata' => $this->historicImportMetadata($item, $path)],
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
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function dispatchMultiSegment(array $item, bool $reEncodeMismatched): array
    {
        $files = $item['files'];

        if (! $this->codecsMatch($files)) {
            if (! $reEncodeMismatched) {
                return ['tag' => 'error', 'processing_id' => '', 'detail' => 'codec mismatch in segment group (use --reencode-mismatched to override)'];
            }

            return $this->concatWithReencode($item);
        }

        return $this->concatLossless($item);
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
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function concatLossless(array $item): array
    {
        $concatPath = $this->buildConcatFile($item['files']);

        if ($concatPath === null) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => 'failed to build FFmpeg concat list'];
        }

        $outputPath = storage_path('app/'.self::TEMP_DIR.'/'.Str::uuid().'.mkv');
        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

        $cmd = escapeshellarg($ffmpegPath)
            .' -f concat -safe 0 -i '.escapeshellarg($concatPath)
            .' -c copy '.escapeshellarg($outputPath)
            .' 2>&1';

        exec($cmd, $cmdOutput, $returnCode);

        Storage::disk('local')->delete(str_replace(storage_path('app/'), '', $concatPath));

        if ($returnCode !== 0) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => 'FFmpeg concat failed: '.implode(' ', array_slice($cmdOutput, -3))];
        }

        $result = $this->dispatchConcatFile($outputPath, $item, 'lossless');

        Storage::disk('local')->delete(str_replace(storage_path('app/'), '', $outputPath));

        if (! $result->success) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => $result->message];
        }

        return ['tag' => 'concat', 'processing_id' => $result->processingId, 'detail' => null];
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @return array{tag: string, processing_id: string, detail: string|null}
     */
    private function concatWithReencode(array $item): array
    {
        $this->ensureTempDir();

        $inputs = implode(' ', array_map(fn (string $f) => '-i '.escapeshellarg($f), $item['files']));
        $filterInputs = implode('', array_map(fn (int $i) => "[{$i}:v][{$i}:a]", array_keys($item['files'])));
        $segmentCount = count($item['files']);
        $filter = "{$filterInputs}concat=n={$segmentCount}:v=1:a=1[outv][outa]";

        $outputPath = storage_path('app/'.self::TEMP_DIR.'/'.Str::uuid().'.mp4');
        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

        $cmd = escapeshellarg($ffmpegPath)
            ." {$inputs}"
            .' -filter_complex '.escapeshellarg($filter)
            .' -map [outv] -map [outa]'
            .' -c:v libx264 -preset veryfast -c:a aac -b:a 192k'
            .' '.escapeshellarg($outputPath)
            .' 2>&1';

        exec($cmd, $cmdOutput, $returnCode);

        if ($returnCode !== 0) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => 'FFmpeg re-encode concat failed: '.implode(' ', array_slice($cmdOutput, -3))];
        }

        $result = $this->dispatchConcatFile($outputPath, $item, 're-encoded');

        Storage::disk('local')->delete(str_replace(storage_path('app/'), '', $outputPath));

        if (! $result->success) {
            return ['tag' => 'error', 'processing_id' => '', 'detail' => $result->message];
        }

        return ['tag' => 'concat-reencoded', 'processing_id' => $result->processingId, 'detail' => null];
    }

    /**
     * @param  array{tag: string, label: string, files: list<string>, date: Carbon, service: SermonService, client_file_date: string, bytes: int}  $item
     * @param  'lossless'|'re-encoded'  $concatenation
     */
    private function dispatchConcatFile(string $outputPath, array $item, string $concatenation): ProcessingResult
    {
        $filename = $item['date']->format('Y-m-d').' '.$item['service']->value.'.mkv';
        $file = new UploadedFile($outputPath, $filename, null, null, true);

        return $this->processor->process(
            type: 'livestream',
            file: $file,
            clientFileDate: $item['client_file_date'] !== '' ? $item['client_file_date'] : null,
            options: ['processing_metadata' => $this->historicImportMetadata($item, $outputPath, $concatenation)],
            serviceOverride: $item['service'],
            serviceDateOverride: $item['date']->toDateString(),
        );
    }

    /**
     * @param  list<string>  $files
     */
    private function buildConcatFile(array $files): ?string
    {
        $this->ensureTempDir();
        $listPath = storage_path('app/'.self::TEMP_DIR.'/concat-'.Str::uuid().'.txt');

        $lines = array_map(fn (string $f) => "file '".addslashes($f)."'", $files);
        $written = file_put_contents($listPath, implode("\n", $lines)."\n");

        return $written !== false ? $listPath : null;
    }

    private function ensureTempDir(): void
    {
        $path = storage_path('app/'.self::TEMP_DIR);

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
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
     * Poll each processing_id until it leaves Pending/Processing, up to the per-file timeout.
     *
     * @param  list<string>  $processingIds
     * @return array<string, string>
     */
    private function waitForInflight(array $processingIds, int $pollIntervalSeconds, int $perFileTimeoutSeconds): array
    {
        $deadline = time() + $perFileTimeoutSeconds;

        while (time() < $deadline) {
            $stillRunning = MediaProcessingLog::query()
                ->whereIn('processing_id', $processingIds)
                ->whereIn('status', [ProcessingStatus::Pending->value, ProcessingStatus::Started->value, ProcessingStatus::Processing->value])
                ->exists();

            if (! $stillRunning) {
                return MediaProcessingLog::query()
                    ->whereIn('processing_id', $processingIds)
                    ->pluck('status', 'processing_id')
                    ->map(fn (ProcessingStatus|string $status): string => $status instanceof ProcessingStatus ? $status->value : $status)
                    ->all();
            }

            if ($pollIntervalSeconds > 0) {
                sleep($pollIntervalSeconds);
            }
        }

        // Past the deadline only the runs still moving are timed out. Marking the
        // whole batch would inflate `errors` with runs that finished cleanly while
        // one slow neighbour held the poll open.
        $statuses = MediaProcessingLog::query()
            ->whereIn('processing_id', $processingIds)
            ->pluck('status', 'processing_id')
            ->map(fn (ProcessingStatus|string $status): string => $status instanceof ProcessingStatus ? $status->value : $status)
            ->all();

        $outcomes = [];

        foreach ($processingIds as $processingId) {
            $status = $statuses[$processingId] ?? null;
            $outcomes[$processingId] = in_array($status, [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ], true) || $status === null
                ? 'timed_out'
                : $status;
        }

        return $outcomes;
    }

    /**
     * @param  ImportMetrics  $metrics
     * @param  array<string, string>  $outcomes
     */
    private function recordTerminalOutcomes(array &$metrics, array $outcomes): void
    {
        foreach ($outcomes as $status) {
            // Static keys throughout: a computed offset would erase the literal
            // shape import() declares, which is what the callers type against.
            if ($status === ProcessingStatus::Failed->value) {
                $metrics['terminal_failed']++;
            } elseif ($status === ProcessingStatus::Cancelled->value) {
                $metrics['terminal_cancelled']++;
            } elseif ($status === ProcessingStatus::Skipped->value) {
                $metrics['terminal_skipped']++;
            } elseif ($status === 'timed_out') {
                $metrics['timed_out']++;
            } else {
                continue;
            }

            $metrics['errors']++;
        }
    }

    /**
     * WP-A4: enough provenance to identify a service's source files on the drive
     * by hash, long after the drive is unmounted.
     *
     * @param  array{tag: string, label: string, files: list<string>}  $item
     * @param  'none'|'lossless'|'re-encoded'  $concatenation  How the dispatched file was produced
     * @return array{historic_import: array<string, mixed>}
     */
    private function historicImportMetadata(array $item, string $path, string $concatenation = 'none'): array
    {
        $sources = array_map(
            static fn (string $sourcePath): array => [
                'path' => $sourcePath,
                'size' => (int) (filesize($sourcePath) ?: 0),
                'mtime' => (int) (filemtime($sourcePath) ?: 0),
                'sha256' => hash_file('sha256', $sourcePath) ?: null,
            ],
            $item['files'],
        );

        return ['historic_import' => [
            'tag' => $item['tag'],
            'label' => $item['label'],
            'sources' => $sources,
            'concatenation' => $concatenation,
            'codec_fingerprint' => $this->probeCodecInfo($path),
            'drive_volume' => $this->driveVolume($item['files'][0]),
            'imported_at' => now()->toISOString(),
        ]];
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
