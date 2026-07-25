<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Support\SermonAssetReferences;
use Illuminate\Console\Command;

/**
 * Checks an import manifest for objects that no production row references.
 *
 * Deletion trigger: remove after the historic archive promotion and its rollback
 * window have closed and the import manifests have been retired.
 */
class AuditHistoricImportAssetsCommand extends Command
{
    protected $signature = 'audit:historic-import-assets {report : JSON report produced by sermons:import-historic-videos}';

    protected $description = 'Fail when a historic import report names an asset prefix no database row references';

    public function handle(): int
    {
        $path = (string) $this->argument('report');
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($payload) || ($payload['format'] ?? null) !== 'crockenhill.historic-import-report') {
            $this->error('The historic import report is missing or invalid.');

            return self::FAILURE;
        }

        $references = $this->references();
        $orphans = [];

        foreach ($payload['items'] ?? [] as $item) {
            foreach (is_array($item['assets'] ?? null) ? $item['assets'] : [] as $prefix) {
                if (is_string($prefix) && ! collect($references)->contains(fn (string $path): bool => str_starts_with($path, $prefix))) {
                    $orphans[] = $prefix;
                }
            }
        }

        if ($orphans !== []) {
            $this->error('Unreferenced historic import asset prefixes: '.implode(', ', array_unique($orphans)));

            return self::FAILURE;
        }

        $this->info('Historic import manifest has no unreferenced asset prefixes.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function references(): array
    {
        $paths = Sermon::query()->get()->flatMap(
            fn (Sermon $sermon) => collect(SermonAssetReferences::for($sermon))->pluck('path'),
        )->all();

        MediaProcessingLog::query()->each(function (MediaProcessingLog $log) use (&$paths): void {
            foreach ($log->processing_metadata?->toArray() ?? [] as $value) {
                if (is_string($value) && str_contains($value, '/')) {
                    $paths[] = $value;
                }
            }
        });

        return array_values(array_filter($paths, 'is_string'));
    }
}
