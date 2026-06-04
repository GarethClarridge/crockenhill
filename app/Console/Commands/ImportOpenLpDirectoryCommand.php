<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OpenLpImportResult;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class ImportOpenLpDirectoryCommand extends Command
{
    protected $signature = 'service-tracking:import-openlp-services
                            {--path= : Path to a directory containing OpenLP .osz archives}
                            {--dry-run : Preview the bulk import without writing any database changes}';

    protected $description = 'Bulk import historic OpenLP church service archives from a directory';

    public function handle(ImportChurchServiceFromOpenLp $importer): int
    {
        $pathOption = $this->option('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_string($pathOption) || trim($pathOption) === '') {
            $this->error('Please provide a directory path with --path=');

            return self::FAILURE;
        }

        $path = realpath($pathOption) ?: $pathOption;

        if (! is_dir($path)) {
            $this->error("Directory does not exist: {$path}");

            return self::FAILURE;
        }

        $archives = $this->discoverArchives($path);

        if ($archives === []) {
            $this->error("No .osz archives were found in {$path}");

            return self::FAILURE;
        }

        $metrics = [
            'archives_found' => count($archives),
            'archives_processed' => 0,
            'services_created' => 0,
            'services_updated' => 0,
            'needs_review' => 0,
            'failures' => 0,
        ];

        foreach ($archives as $archivePath) {
            try {
                $result = $this->importArchive(
                    archivePath: $archivePath,
                    importer: $importer,
                    dryRun: $dryRun,
                );

                $metrics['archives_processed']++;
                $metrics[$result->wasCreated ? 'services_created' : 'services_updated']++;

                if ($result->churchService->needs_review) {
                    $metrics['needs_review']++;
                }
            } catch (Throwable $exception) {
                $metrics['failures']++;
                $this->error(sprintf('%s: %s', basename($archivePath), $exception->getMessage()));
            }
        }

        $this->info('OpenLP archive import completed.');
        $this->line('Path: '.$path);

        if ($dryRun) {
            $this->warn('Dry run enabled. No database changes were written.');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Archives found', (string) $metrics['archives_found']],
                ['Archives processed', (string) $metrics['archives_processed']],
                ['Services created', (string) $metrics['services_created']],
                ['Services updated', (string) $metrics['services_updated']],
                ['Imports flagged for review', (string) $metrics['needs_review']],
                ['Failures', (string) $metrics['failures']],
            ]
        );

        return $metrics['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function discoverArchives(string $path): array
    {
        return array_values(collect(File::allFiles($path))
            ->map(static fn (\SplFileInfo $file): string => $file->getPathname())
            ->filter(static fn (string $filePath): bool => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'osz')
            ->sort()
            ->values()
            ->all());
    }

    private function uploadedFileForArchive(string $archivePath): UploadedFile
    {
        return new UploadedFile(
            path: $archivePath,
            originalName: basename($archivePath),
            mimeType: 'application/zip',
            test: true,
        );
    }

    private function importArchive(
        string $archivePath,
        ImportChurchServiceFromOpenLp $importer,
        bool $dryRun,
    ): OpenLpImportResult {
        $uploadedFile = $this->uploadedFileForArchive($archivePath);

        if (! $dryRun) {
            return $importer->import($uploadedFile);
        }

        $result = new class
        {
            public ?OpenLpImportResult $value = null;
        };

        try {
            DB::transaction(function () use ($importer, $uploadedFile, $result): void {
                $result->value = $importer->import($uploadedFile);

                throw new DryRunRollbackException;
            });
        } catch (DryRunRollbackException) {
            // Roll back the previewed import while keeping the computed result.
        }

        if (! $result->value instanceof OpenLpImportResult) {
            throw new RuntimeException('Dry run did not produce an import result.');
        }

        return $result->value;
    }
}

class DryRunRollbackException extends RuntimeException {}
