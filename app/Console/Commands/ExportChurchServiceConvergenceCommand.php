<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
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
        {--media-bundle-hash= : Exact Bundle A SHA-256}
        {--fingerprint= : JSON object pinning projector and processing configuration}
        {--output= : Private Bundle B path below storage/scratch or storage/app/private}';

    protected $description = 'Export final reviewed church-service convergence as private Bundle B';

    public function handle(ChurchServiceConvergenceBundleExporter $exporter): int
    {
        try {
            $bundle = $exporter->export(
                $this->serviceIds(),
                $this->requiredOption('batch-hash'),
                $this->requiredOption('media-bundle-hash'),
                $this->fingerprint(),
            );
            $path = $this->privateOutputPath();
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

    /** @return array<string, mixed> */
    private function fingerprint(): array
    {
        $fingerprint = json_decode($this->requiredOption('fingerprint'), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fingerprint) || array_is_list($fingerprint)) {
            throw new RuntimeException('--fingerprint must be a non-empty JSON object.');
        }

        return $fingerprint;
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
