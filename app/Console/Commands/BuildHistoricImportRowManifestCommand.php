<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricImportRowManifest;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read one disposable restore's table/row membership into a manifest artifact.
 *
 * HIR5 item 6 requires both restores to be recomputed "through one read-only
 * implementation" rather than compared as caller-supplied equal strings. This is
 * the operator's way in: point it at each restored database in turn, and hand
 * the two manifests to `historic-import:verify-recovery` as declared artifacts.
 *
 * Running it against the production connection is pointless rather than
 * dangerous — every query is a read — but the recovery gate refuses a manifest
 * whose connection anchor is the production one, so a restore verified against
 * production fails there rather than passing quietly.
 *
 * Delete alongside the recovery verifier once the historic import acceptance and
 * rollback-retention windows have expired (G9/WP10).
 */
class BuildHistoricImportRowManifestCommand extends Command
{
    protected $signature = 'historic-import:row-manifest
        {--connection= : Named database connection holding the disposable restore}
        {--output= : Absolute path the manifest artifact is created at}';

    protected $description = 'Read a disposable restore\'s exact table/row membership into a recovery manifest artifact';

    public function handle(HistoricImportRowManifest $manifests): int
    {
        try {
            $connection = $this->option('connection');
            $manifest = $manifests->forConnection(
                is_string($connection) && trim($connection) !== '' ? trim($connection) : null,
            );
            $path = $this->outputPath();

            $this->createOnce($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

            $this->info("Row manifest covers {$manifest['table_count']} tables.");
            $this->line("Connection anchor: {$manifest['connection_anchor']}");
            $this->line("Manifest: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function outputPath(): string
    {
        $option = $this->option('output');

        if (! is_string($option) || ! str_starts_with($option, '/')) {
            throw new RuntimeException('A row manifest requires an absolute --output path.');
        }

        return $option;
    }

    /**
     * Created once at a new path.
     *
     * The recovery gate reads this artifact back and refuses one whose bytes
     * moved, so overwriting an existing manifest in place would be a way to
     * invalidate evidence that has already been signed.
     */
    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("A row manifest must be created at a new path: {$path}");
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('The row manifest could not be written durably.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);
    }
}
