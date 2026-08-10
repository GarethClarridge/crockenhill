<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricSourceAcquisitionVerifier;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Delete after the historic source custody artifact has passed production
 * acceptance and its source-retention window has expired.
 */
class VerifyHistoricSourceAcquisitionCommand extends Command
{
    protected $signature = 'historic-import:verify-source-acquisition
        {custody : Signed custody JSON captured before corpus access}
        {evidence-copy : Protected metadata-faithful evidence copy}
        {working-copy : Protected materialised processing copy}
        {--report= : Immutable report path below storage/app/private}';

    protected $description = 'Verify complete historic source custody, independent copies and whole-tree inventory';

    public function handle(HistoricSourceAcquisitionVerifier $verifier): int
    {
        try {
            $custody = $this->readCustody((string) $this->argument('custody'));
            $key = config('media-processing.historic_import.evidence_signing_key');

            if (! is_string($key)) {
                throw new RuntimeException('Historic source evidence signing key is not configured.');
            }

            $report = $verifier->verify(
                $custody,
                (string) $this->argument('evidence-copy'),
                (string) $this->argument('working-copy'),
                $key,
            );
            $path = $this->reportPath();

            $this->writeOnce($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
            $pathCount = $report['copies']['working']['path_count'] ?? null;

            if (! is_int($pathCount)) {
                throw new RuntimeException('Historic source acquisition report lost its path count.');
            }

            $this->info("Historic source acquisition verified for {$pathCount} paths.");
            $this->line("Report: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readCustody(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Historic source custody artifact is missing.');
        }

        $custody = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($custody)) {
            throw new RuntimeException('Historic source custody artifact must be a JSON object.');
        }

        return $custody;
    }

    private function reportPath(): string
    {
        $option = $this->option('report');

        if (! is_string($option) || trim($option) === '') {
            throw new RuntimeException('Historic source verification requires --report.');
        }

        $root = realpath(storage_path('app/private'));
        $path = str_starts_with($option, '/') ? $option : storage_path('app/private/'.trim($option));
        $parent = realpath(dirname($path));

        if (! is_string($root) || ! is_string($parent) || ! str_starts_with($parent.'/', $root.'/')) {
            throw new RuntimeException('Historic source acquisition report must stay below storage/app/private.');
        }

        return $path;
    }

    private function writeOnce(string $path, string $contents): void
    {
        $handle = fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException('Historic source acquisition report must be created once at a new private path.');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Historic source acquisition report could not be written durably.');
            }

            $remaining = $contents;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Historic source acquisition report could not be written durably.');
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Historic source acquisition report could not be written durably.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);
    }
}
