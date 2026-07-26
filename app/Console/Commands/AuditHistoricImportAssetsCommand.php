<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\SermonAssetReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Verifies that everything a historic import batch was supposed to retain is
 * actually retained, while the drive is still mounted and a re-run is cheap.
 *
 * The batch report names the processing runs; this resolves each one and checks
 * that the durable artifacts recorded against it, and the sermon assets derived
 * from it, resolve to objects that exist on their configured disks.
 *
 * Deletion trigger: remove once the historic archive is fully promoted and the
 * import manifests have been retired.
 */
class AuditHistoricImportAssetsCommand extends Command
{
    protected $signature = 'audit:historic-import-assets {report : JSON report produced by sermons:import-historic-videos}';

    protected $description = 'Fail when a historic import batch is missing an artifact it was supposed to retain';

    public function handle(): int
    {
        $path = (string) $this->argument('report');
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($payload) || ($payload['format'] ?? null) !== 'crockenhill.historic-import-report') {
            $this->error('The historic import report is missing or invalid.');

            return self::FAILURE;
        }

        $processingIds = $this->dispatchedProcessingIds($payload);

        if ($processingIds === []) {
            $this->info('The report dispatched no processing runs; nothing to audit.');

            return self::SUCCESS;
        }

        $missing = [];
        $unresolved = [];
        $incomplete = [];
        $checked = 0;

        foreach ($processingIds as $processingId) {
            $log = MediaProcessingLog::query()
                ->with('sermon')
                ->where('processing_id', $processingId)
                ->first();

            if ($log === null) {
                $unresolved[] = $processingId;

                continue;
            }

            if ($log->status !== ProcessingStatus::Completed) {
                $incomplete[] = "{$processingId} ({$log->status->value})";

                continue;
            }

            foreach ($this->expectedAssets($log) as $asset) {
                $checked++;

                if (! Storage::disk($asset['disk'])->exists($asset['path'])) {
                    $missing[] = "{$processingId} {$asset['kind']} → {$asset['disk']}:{$asset['path']}";
                }
            }
        }

        foreach ($incomplete as $entry) {
            $this->warn("Run did not complete, so its artifacts were not audited: {$entry}");
        }

        if ($unresolved !== []) {
            $this->error('Report names processing runs with no database row: '.implode(', ', $unresolved));
        }

        if ($missing !== []) {
            $this->error('Retained artifacts missing from storage:');

            foreach ($missing as $entry) {
                $this->line("  - {$entry}");
            }
        }

        if ($unresolved !== [] || $missing !== []) {
            return self::FAILURE;
        }

        $this->info("Historic import batch is fully retained ({$checked} assets verified).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function dispatchedProcessingIds(array $payload): array
    {
        $ids = [];

        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $processingId = $item['processing_id'] ?? null;

            if (is_string($processingId) && $processingId !== '') {
                $ids[] = $processingId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The durable service artifacts plus every asset the derived sermon references.
     *
     * @return list<array{kind: string, disk: string, path: string}>
     */
    private function expectedAssets(MediaProcessingLog $log): array
    {
        $assets = ServiceArtifactStorage::recordedFor($log);

        if ($log->sermon !== null) {
            foreach (SermonAssetReferences::for($log->sermon) as $reference) {
                $assets[] = [
                    'kind' => 'sermon:'.$reference['kind'],
                    'disk' => $reference['disk'],
                    'path' => $reference['path'],
                ];
            }
        }

        return $assets;
    }
}
