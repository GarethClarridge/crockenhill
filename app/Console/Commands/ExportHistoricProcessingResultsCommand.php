<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricProcessingResultBundleExporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary historic-media one-shot. Delete after the complete archive has been
 * promoted, both bundle reruns are no-op and the rollback window has expired.
 */
class ExportHistoricProcessingResultsCommand extends Command
{
    protected $signature = 'historic:export-processing-results
                            {--processing-ids= : Comma-separated completed processing UUIDs}
                            {--batch-hash= : Approved source batch SHA-256}
                            {--fingerprint= : JSON object pinning code, pipeline, models and configuration}
                            {--output= : Private output under storage/scratch or storage/app/private}';

    protected $description = 'Export verified historic processing results as a private portable Bundle A';

    public function handle(HistoricProcessingResultBundleExporter $exporter): int
    {
        try {
            $result = $exporter->export(
                $this->processingIds(),
                $this->requiredOption('batch-hash'),
                $this->fingerprint(),
                $this->requiredOption('output'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Historic processing result bundle exported.');
        $this->line("Services: {$result['service_count']}");
        $this->line("Bundle hash: {$result['bundle_hash']}");
        $this->line("Private path: {$result['path']}");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function processingIds(): array
    {
        $ids = preg_split('/\s*,\s*/', $this->requiredOption('processing-ids'));
        $ids = is_array($ids) ? array_values(array_unique(array_filter($ids))) : [];

        if ($ids === []) {
            throw new RuntimeException('--processing-ids must contain at least one processing UUID.');
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function fingerprint(): array
    {
        $fingerprint = json_decode($this->requiredOption('fingerprint'), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fingerprint) || array_is_list($fingerprint)) {
            throw new RuntimeException('--fingerprint must be a non-empty JSON object.');
        }

        return $fingerprint;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return trim($value);
    }
}
