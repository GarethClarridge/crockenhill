<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class HistoricVideoPilotLedger
{
    public function __construct(
        private readonly HistoricProcessingResultInventory $processingInventory,
    ) {}

    /**
     * Identity dispositions the freeze accepts as named.
     *
     * A failed run is a truthful terminal disposition, so it freezes cleanly;
     * an identity still mid-flight, one nobody can explain, and one holding two
     * completed runs are not, and each fails the gate.
     *
     * @var list<string>
     */
    private const NAMED_DISPOSITIONS = [
        'completed',
        'completed_after_failed_attempts',
        'failed',
        'skipped_pre_existing_sermon',
    ];

    /** @return array<string, mixed> */
    public function build(string $selectionPath, string $operationId, ?string $manifestPath = null): array
    {
        $selection = $this->selection($selectionPath);
        $operation = HistoricImportOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("Historic import operation {$operationId} does not exist.");
        }

        $manifestHash = $selection['derived_from']['manifest_hash'];

        if (($operation->manifest_hashes['historic_video'] ?? null) !== $manifestHash) {
            throw new RuntimeException('The operation is not bound to the pilot selection manifest.');
        }

        $runs = MediaProcessingLog::query()
            ->whereBelongsTo($operation, 'historicImportOperation')
            ->with(['processingSteps', 'segments', 'sermon', 'serviceSections.publishedSermon', 'serviceSections.songVideos'])
            ->orderBy('id')
            ->get();
        $selectedKeys = $selection['item_keys'];
        $runsByKey = $runs->groupBy(fn (MediaProcessingLog $run): string => $this->itemKey($run));
        $missingKeys = array_values(array_diff($selectedKeys, $runsByKey->keys()->all()));
        $unexpectedKeys = array_values(array_diff($runsByKey->keys()->all(), $selectedKeys));
        $duplicateKeys = $runsByKey
            ->filter(fn ($itemRuns): bool => $itemRuns->count() > 1)
            ->keys()
            ->values()
            ->all();
        $contexts = $runs
            ->map(fn (MediaProcessingLog $run): mixed => data_get(
                $run->processing_metadata?->toArray(),
                'historic_import.staging_context',
            ))
            ->filter(fn (mixed $context): bool => is_array($context))
            ->unique(fn (array $context): string => CanonicalJson::hash($context))
            ->values();
        $errors = [];

        if ($contexts->count() !== 1) {
            $errors[] = 'Pilot runs do not carry one exact staging context.';
        }

        $manifest = $this->manifestIdentities($manifestPath, $operation, $selectedKeys, $errors);
        $identities = [];

        foreach ($selectedKeys as $itemKey) {
            $itemRuns = $runsByKey->get($itemKey, collect())->map(
                fn (MediaProcessingLog $run): array => $this->run($run),
            )->values();

            foreach ($itemRuns as $itemRun) {
                if (is_string($itemRun['inventory_error'])) {
                    $errors[] = "Processing run {$itemRun['processing_id']} graph inventory failed: {$itemRun['inventory_error']}";
                }
            }

            $absence = $itemRuns->isEmpty()
                ? $this->absenceEvidence($manifest[$itemKey] ?? null, $operation)
                : null;
            $disposition = $this->disposition(array_values($itemRuns->all()), $absence);

            if (! in_array($disposition, self::NAMED_DISPOSITIONS, true)) {
                $errors[] = "Selected identity {$itemKey} has no named disposition: {$disposition}.";
            }

            $identities[] = [
                'item_key' => $itemKey,
                'manifest_identity' => $manifest[$itemKey] ?? null,
                'disposition' => $disposition,
                'absence_evidence' => $absence,
                'runs' => $itemRuns->all(),
            ];
        }

        $files = [];

        if ($contexts->count() === 1) {
            /** @var array<string, mixed> $context */
            $context = $contexts->first();
            $files = $this->files($context, $runs, $errors);
        }

        $ledger = [
            'format' => 'crockenhill-historic-video-pilot-ledger',
            'version' => 1,
            'captured_at' => now()->toISOString(),
            'selection' => [
                'path' => realpath($selectionPath),
                'sha256' => hash_file('sha256', $selectionPath),
                'manifest_hash' => $manifestHash,
                'item_count' => count($selectedKeys),
            ],
            'operation' => [
                'operation_id' => $operation->operation_id,
                'batch_key' => $operation->batch_key,
                'state' => $operation->state->value,
                'plan_hash' => $operation->plan_hash,
                'runtime_fingerprint' => $operation->runtime_fingerprint,
                'notification_mode' => $operation->notification_mode,
                'max_cost_minor_units' => $operation->max_cost_minor_units,
                'accepted_deadline' => $operation->accepted_deadline?->toISOString(),
                'usage' => [
                    'entries' => $operation->usageEntries()->count(),
                    'cost_minor_units' => (int) $operation->usageEntries()->sum('cost_minor_units'),
                    'currencies' => $operation->usageEntries()->distinct()->orderBy('currency')->pluck('currency')->all(),
                ],
            ],
            'reconciliation' => [
                'selected_items' => count($selectedKeys),
                'processing_runs' => $runs->count(),
                'distinct_observed_items' => $runsByKey->count(),
                'missing_item_keys' => $missingKeys,
                'unexpected_item_keys' => $unexpectedKeys,
                'duplicate_item_keys' => $duplicateKeys,
                'dispositions' => collect($identities)
                    ->countBy(fn (array $identity): string => $identity['disposition'])
                    ->sortKeys()
                    ->all(),
            ],
            'identities' => $identities,
            'staging_files' => $files,
            'byte_census' => $this->byteCensus($files),
            'drive_read_failures' => $this->driveReadFailures($files),
            'errors' => $errors,
        ];
        /**
         * Membership is settled by the per-identity disposition, not by a raw
         * run count. An identity the dispatch skipped because a sermon already
         * existed produces no run, and a failed attempt followed by a completed
         * one produces two; both are named outcomes rather than gaps.
         */
        $ledger['exit_gate_passed'] = $unexpectedKeys === []
            && $errors === []
            && collect($files)->every(fn (array $file): bool => $this->fileIsAccounted($file))
            && collect($files)->doesntContain(
                fn (array $file): bool => $file['disposition'] === 'unexplained_residue',
            );
        $ledger['ledger_hash'] = CanonicalJson::hash($ledger);

        return $ledger;
    }

    /** @return array<string, mixed> */
    private function selection(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Pilot selection must be a readable JSON file.');
        }

        $selection = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($selection)
            || ($selection['format'] ?? null) !== 'crockenhill-historic-video-pilot-selection'
            || ($selection['version'] ?? null) !== 1
            || ! is_array($selection['item_keys'] ?? null)
            || ! is_array($selection['derived_from'] ?? null)) {
            throw new RuntimeException('Pilot selection has an unsupported or incomplete contract.');
        }

        $keys = $selection['item_keys'];
        $manifestHash = $selection['derived_from']['manifest_hash'] ?? null;

        if ($keys === []
            || count($keys) !== count(array_unique($keys))
            || collect($keys)->contains(fn (mixed $key): bool => ! is_string($key) || trim($key) === '')
            || ! is_string($manifestHash)
            || preg_match('/\A[0-9a-f]{64}\z/', $manifestHash) !== 1) {
            throw new RuntimeException('Pilot selection item keys or manifest binding are invalid.');
        }

        return $selection;
    }

    /** @return array<string, mixed> */
    private function run(MediaProcessingLog $run): array
    {
        try {
            $inventory = $this->processingInventory->build($run);
            $inventoryError = null;
        } catch (Throwable $exception) {
            $inventory = null;
            $inventoryError = $exception->getMessage();
        }

        return [
            'processing_id' => $run->processing_id,
            'status' => $run->status->value,
            'current_step' => $run->current_step,
            'sermon_id' => $run->sermon_id,
            'church_service_id' => $run->church_service_id,
            'logical_inventory' => $inventory,
            'inventory_error' => $inventoryError,
        ];
    }

    private function itemKey(MediaProcessingLog $run): string
    {
        $itemKey = data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key');

        return is_string($itemKey) && $itemKey !== '' ? $itemKey : "unbound:{$run->processing_id}";
    }

    /**
     * The curation manifest is the only authority for an identity's date and
     * service. Splitting `2023-09-03-morning` on its last hyphen would work
     * today and lie the first time a key stops looking like a date, so the
     * ledger reads the manifest or names no absence evidence at all.
     *
     * @param  list<string>  $selectedKeys
     * @param  list<string>  $errors
     * @return array<string, array{date: string, service: string}>
     */
    private function manifestIdentities(
        ?string $manifestPath,
        HistoricImportOperation $operation,
        array $selectedKeys,
        array &$errors,
    ): array {
        if ($manifestPath === null) {
            return [];
        }

        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('Pilot curation manifest must be a readable JSON file.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest)
            || ! is_array($manifest['entries'] ?? null)
            || ($manifest['batch_key'] ?? null) !== $operation->batch_key) {
            throw new RuntimeException('The curation manifest is not the one this operation owns.');
        }

        $identities = [];

        foreach ($manifest['entries'] as $entry) {
            $itemKey = is_array($entry) ? ($entry['item_key'] ?? null) : null;

            if (! is_string($itemKey) || ! is_string($entry['date'] ?? null) || ! is_string($entry['service'] ?? null)) {
                continue;
            }

            $identities[$itemKey] = ['date' => $entry['date'], 'service' => $entry['service']];
        }

        $unknown = array_values(array_diff($selectedKeys, array_keys($identities)));

        if ($unknown !== []) {
            $errors[] = 'Selected identities are absent from the curation manifest: '.implode(', ', $unknown).'.';
        }

        return $identities;
    }

    /**
     * An identity with no run is only explained by evidence that the dispatch
     * had a reason to skip it. The one reason the pilot exercised is the
     * importer's `skip-exists` guard, which fires when the date and service
     * already hold a sermon this operation does not own.
     *
     * @param  array{date: string, service: string}|null  $identity
     * @return array<string, mixed>|null
     */
    private function absenceEvidence(?array $identity, HistoricImportOperation $operation): ?array
    {
        if ($identity === null) {
            return null;
        }

        $sermons = Sermon::query()
            ->whereDate('date', $identity['date'])
            ->where('service', $identity['service'])
            ->orderBy('id')
            ->get(['id', 'title', 'slug', 'livestream_processing_id']);

        if ($sermons->isEmpty()) {
            return null;
        }

        $ownedProcessingIds = MediaProcessingLog::query()
            ->whereBelongsTo($operation, 'historicImportOperation')
            ->pluck('processing_id')
            ->all();
        $preExisting = $sermons->reject(
            fn (Sermon $sermon): bool => in_array($sermon->livestream_processing_id, $ownedProcessingIds, true),
        )->values();

        if ($preExisting->isEmpty()) {
            return null;
        }

        return [
            'reason' => 'pre_existing_sermon',
            'date' => $identity['date'],
            'service' => $identity['service'],
            'sermons' => $preExisting->map(fn (Sermon $sermon): array => [
                'sermon_id' => $sermon->id,
                'title' => $sermon->title,
                'slug' => $sermon->slug,
            ])->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>|null  $absence
     */
    private function disposition(array $runs, ?array $absence): string
    {
        if ($runs === []) {
            return $absence === null ? 'unexplained_absence' : 'skipped_pre_existing_sermon';
        }

        $statuses = array_column($runs, 'status');
        $completed = count(array_filter($statuses, fn (string $status): bool => $status === 'completed'));

        if ($completed > 1) {
            return 'ambiguous_multiple_completed_runs';
        }

        $terminal = array_filter(
            $statuses,
            fn (string $status): bool => ! in_array($status, ['completed', 'failed', 'skipped', 'cancelled'], true),
        );

        if ($terminal !== []) {
            return 'in_progress';
        }

        if ($completed === 1) {
            return count($statuses) > 1 ? 'completed_after_failed_attempts' : 'completed';
        }

        return 'failed';
    }

    /**
     * Dispositions whose files nothing ever copies, so an unreadable one is a
     * drive fault to report rather than a hole in the transfer evidence.
     *
     * @var list<string>
     */
    private const DISCARDABLE_DISPOSITIONS = [
        'platform_sidecar',
        'orphaned_rms_working_copy',
        'orphaned_thumbnail_frame',
        'orphaned_extraction_working_copy',
        'temporary_or_retryable_input',
    ];

    /** @param array<string, mixed> $file */
    private function fileIsAccounted(array $file): bool
    {
        if (! is_string($file['owner']) || $file['owner'] === '') {
            return false;
        }

        if (is_string($file['sha256']) && $file['sha256'] !== '' && is_int($file['byte_size'])) {
            return true;
        }

        /**
         * macOS writes an AppleDouble sidecar beside every staged file on the
         * exFAT drive, and Docker refuses to stat those from inside the
         * container. They and the orphaned working copies are named, counted and
         * destined for the reclaim, so the freeze accepts them without a hash —
         * `drive_read_failures` still reports every read that failed.
         */
        return in_array($file['disposition'], self::DISCARDABLE_DISPOSITIONS, true);
    }

    /**
     * Every file the drive refused to read, whether or not it blocked the gate.
     *
     * The sidecars are expected and the storage driver names them the same way
     * every time; an I/O error on a staged recording is a drive-health signal
     * the next pass's preflight has to see, so both are reported here.
     *
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function driveReadFailures(array $files): array
    {
        $failures = [];

        foreach ($files as $file) {
            if (! is_string($file['unreadable_reason'])) {
                continue;
            }

            $failures[] = [
                'path' => $file['path'],
                'disposition' => $file['disposition'],
                'byte_size' => $file['byte_size'],
                'reason' => $file['unreadable_reason'],
            ];
        }

        return $failures;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function byteCensus(array $files): array
    {
        $census = collect($files);

        return [
            'file_count' => $census->count(),
            'hashed_bytes' => (int) $census->sum(
                fn (array $file): int => is_int($file['byte_size']) ? $file['byte_size'] : 0,
            ),
            'unreadable_files' => $census->filter(
                fn (array $file): bool => ! is_int($file['byte_size']),
            )->count(),
            'by_disposition' => $census
                ->countBy(fn (array $file): string => $file['disposition'])
                ->sortKeys()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<int, MediaProcessingLog>  $runs
     * @param  list<string>  $errors
     * @return list<array<string, mixed>>
     */
    private function files(array $context, iterable $runs, array &$errors): array
    {
        $diskName = $context['staging_disk'] ?? null;
        $batchRoot = $context['batch_root'] ?? null;

        if (! is_string($diskName) || ! is_string($batchRoot) || $diskName === '' || $batchRoot === '') {
            $errors[] = 'Pilot staging context has no usable disk and batch root.';

            return [];
        }

        $disk = Storage::disk($diskName);

        try {
            $paths = $this->enumerate($disk, $batchRoot);
        } catch (Throwable $exception) {
            $errors[] = "Unable to enumerate pilot staging root: {$exception->getMessage()}";

            return [];
        }

        sort($paths);
        $files = [];

        foreach ($paths as $path) {
            $files[] = $this->file($disk, $path, $batchRoot, $runs, $errors);
        }

        return $files;
    }

    /**
     * Flysystem's deep listing stats every entry and aborts the whole listing
     * when one fails, so a single unstattable AppleDouble sidecar hid the entire
     * 16 GB batch root from the first capture. Directory entries are readable
     * even when their metadata is not, so the walk reads names itself and lets
     * the per-file inventory record what it could not measure.
     *
     * @return list<string>
     */
    private function enumerate(FilesystemAdapter $disk, string $directory): array
    {
        $absolute = $disk->path($directory);
        $entries = @scandir($absolute);

        if ($entries === false) {
            throw new RuntimeException("Unable to read staging directory: {$directory}.");
        }

        $paths = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = rtrim($directory, '/')."/{$entry}";

            if (is_dir($absolute.DIRECTORY_SEPARATOR.$entry)) {
                $paths = [...$paths, ...$this->enumerate($disk, $path)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param  iterable<int, MediaProcessingLog>  $runs
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function file(
        FilesystemAdapter $disk,
        string $path,
        string $batchRoot,
        iterable $runs,
        array &$errors,
    ): array {
        $relativePath = ltrim(substr($path, strlen(rtrim($batchRoot, '/'))), '/');
        $owner = $this->fileOwner($relativePath, $runs);
        $size = null;
        $sha256 = null;
        $unreadableReason = null;

        try {
            $size = $disk->size($path);
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException('storage returned no readable stream');
            }

            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            $sha256 = hash_final($hash);
        } catch (Throwable $exception) {
            $unreadableReason = $exception->getMessage();
        }

        $disposition = $this->fileDisposition($relativePath, $owner);

        if ($unreadableReason !== null && ! in_array($disposition, self::DISCARDABLE_DISPOSITIONS, true)) {
            $errors[] = "Unable to inventory {$relativePath}: {$unreadableReason}";
        }

        return [
            'path' => $relativePath,
            'byte_size' => $size,
            'sha256' => $sha256,
            'unreadable_reason' => $unreadableReason,
            'owner' => $owner,
            'disposition' => $disposition,
        ];
    }

    /** @param iterable<int, MediaProcessingLog> $runs */
    private function fileOwner(string $path, iterable $runs): string
    {
        /**
         * A sidecar carries the extended attributes of the file beside it, so it
         * belongs to whatever owns that file. Resolving it against the shadowed
         * name keeps 300-odd sidecars out of the unexplained-residue column.
         */
        if ($this->isPlatformSidecar($path)) {
            return $this->fileOwner($this->shadowedPath($path), $runs);
        }

        foreach ($runs as $run) {
            if (str_contains($path, $run->processing_id)
                || ($run->sermon_id !== null && preg_match("#(?:^|[/_-]){$run->sermon_id}(?:[/_.-]|$)#", $path) === 1)
                || $run->serviceSections->contains(
                    fn ($section): bool => preg_match("#(?:^|[/_-]){$section->id}(?:[/_.-]|$)#", $path) === 1,
                )) {
                return 'manifest_item:'.$this->itemKey($run);
            }
        }

        return 'batch_residue';
    }

    private function isPlatformSidecar(string $path): bool
    {
        return str_starts_with(basename($path), '._');
    }

    private function shadowedPath(string $path): string
    {
        return trim(dirname($path), './').'/'.substr(basename($path), 2);
    }

    private function fileDisposition(string $path, string $owner): string
    {
        if ($this->isPlatformSidecar($path)) {
            return 'platform_sidecar';
        }

        if ($owner !== 'batch_residue') {
            if (str_starts_with($path, 'temp/concat/')) {
                return 'concatenation';
            }

            if (str_starts_with($path, 'temp/') || str_starts_with($path, 'livestream/temp/')) {
                return 'temporary_or_retryable_input';
            }

            return 'durable_output';
        }

        /**
         * Working files whose names carry a job UUID rather than the run's
         * processing id, so no run records them and no owner can be resolved.
         * They are still batch-owned and still reclaimable, and naming the shape
         * is what separates a known leak from residue nobody can account for.
         */
        if (preg_match('#^temp/rms_[0-9a-f-]{36}\.log$#', $path) === 1) {
            return 'orphaned_rms_working_copy';
        }

        if (preg_match('#^temp/thumbnails/frame_[0-9a-f-]{36}\.webp$#', $path) === 1) {
            return 'orphaned_thumbnail_frame';
        }

        if (preg_match('#^(?:livestream/)?temp/[0-9a-f-]{36}(?:_sermon)?\.mp4$#', $path) === 1) {
            return 'orphaned_extraction_working_copy';
        }

        return 'unexplained_residue';
    }
}
