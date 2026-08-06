<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary R8 one-shot. Delete after Bundle B has been applied, its rerun is a
 * no-op and the production rollback window has expired.
 */
class ExportChurchServiceConvergenceCommand extends Command
{
    protected $signature = 'service-tracking:export-convergence
        {--service-ids= : Comma-separated reviewed church-service IDs}
        {--batch-hash= : Approved source batch SHA-256}
        {--media-bundle= : Path to the exact Bundle A this convergence is paired with}
        {--output= : Private Bundle B path below storage/scratch or storage/app/private}';

    protected $description = 'Export final reviewed church-service convergence as private Bundle B';

    public function handle(
        ChurchServiceConvergenceBundleExporter $exporter,
        HistoricProcessingResultBundle $mediaBundles,
    ): int {
        try {
            $serviceIds = $this->serviceIds();
            $batchHash = $this->requiredOption('batch-hash');
            $path = $this->privateOutputPath();
            $mediaBundle = $mediaBundles->decode($this->mediaBundleJson());
            $bundle = $exporter->export(
                $serviceIds,
                $batchHash,
                $mediaBundle['bundle_hash'],
                $mediaBundle['processing_fingerprint'],
            );
            $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (file_put_contents($path, $json.PHP_EOL) === false) {
                throw new RuntimeException('Bundle B could not be written.');
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Reviewed convergence bundle exported.');
        $this->line('Services: '.count($bundle['services']));
        $this->line("Bundle hash: {$bundle['bundle_hash']}");
        $this->line("Private path: {$path}");

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function serviceIds(): array
    {
        $ids = preg_split('/\s*,\s*/', $this->requiredOption('service-ids'));
        $ids = is_array($ids)
            ? array_values(array_unique(array_map('intval', array_filter($ids))))
            : [];

        if ($ids === [] || in_array(0, $ids, true)) {
            throw new RuntimeException('--service-ids must contain positive integer IDs.');
        }

        return $ids;
    }

    /**
     * Bundle B's media-bundle hash and processing fingerprint are read out of
     * Bundle A rather than retyped. Convergence and the auditor both refuse a
     * pair whose fingerprints do not hash-match, so transcribing them by hand
     * would only ever be a way to get that comparison wrong.
     */
    private function mediaBundleJson(): string
    {
        $path = $this->requiredOption('media-bundle');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('--media-bundle must be a readable Bundle A path.');
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException('--media-bundle could not be read.');
        }

        return $json;
    }

    private function privateOutputPath(): string
    {
        $path = $this->requiredOption('output');
        $allowedRoots = [
            realpath(storage_path('scratch')),
            realpath(storage_path('app/private')),
        ];
        $parent = realpath(dirname($path));

        foreach ($allowedRoots as $root) {
            if (is_string($root) && is_string($parent) && str_starts_with($parent.'/', $root.'/')) {
                return $path;
            }
        }

        throw new RuntimeException('--output must have an existing parent below storage/scratch or storage/app/private.');
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
