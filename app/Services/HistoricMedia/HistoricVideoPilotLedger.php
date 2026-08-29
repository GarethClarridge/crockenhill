<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
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

    /** @return array<string, mixed> */
    public function build(string $selectionPath, string $operationId): array
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

            $identities[] = [
                'item_key' => $itemKey,
                'disposition' => $runsByKey->has($itemKey) ? 'observed' : 'missing_processing_run',
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
            ],
            'identities' => $identities,
            'staging_files' => $files,
            'errors' => $errors,
        ];
        $ledger['exit_gate_passed'] = $missingKeys === []
            && $unexpectedKeys === []
            && $errors === []
            && collect($files)->every(
                fn (array $file): bool => is_string($file['sha256'])
                    && $file['sha256'] !== ''
                    && is_string($file['owner'])
                    && $file['owner'] !== '',
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
            $paths = $disk->allFiles($batchRoot);
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
            $errors[] = "Unable to inventory {$relativePath}: {$exception->getMessage()}";
        }

        return [
            'path' => $relativePath,
            'byte_size' => $size,
            'sha256' => $sha256,
            'owner' => $owner,
            'disposition' => $this->fileDisposition($relativePath, $owner),
        ];
    }

    /** @param iterable<int, MediaProcessingLog> $runs */
    private function fileOwner(string $path, iterable $runs): string
    {
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

    private function fileDisposition(string $path, string $owner): string
    {
        if ($owner === 'batch_residue') {
            return 'unexplained_residue';
        }

        if (str_starts_with($path, 'temp/concat/')) {
            return 'concatenation';
        }

        if (str_starts_with($path, 'temp/') || str_starts_with($path, 'livestream/temp/')) {
            return 'temporary_or_retryable_input';
        }

        return 'durable_output';
    }
}
