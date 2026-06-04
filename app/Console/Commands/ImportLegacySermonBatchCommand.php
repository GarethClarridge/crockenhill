<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\LegacySermonImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacySermonBatchCommand extends Command
{
    protected $signature = 'sermons:import-legacy
                            {--dir= : Directory containing MP3 files to import}
                            {--csv= : Path to Tape Index CSV (defaults to storage/app/Tape Index.csv)}
                            {--dry-run : Preview matches without creating logs}
                            {--delay=0 : Seconds to pause between imports}
                            {--force : Re-import files already seen by file hash or filename}';

    protected $description = 'Import a batch of legacy sermon MP3 files into the processing pipeline';

    public function handle(LegacySermonImporter $importer): int
    {
        $dirOption = $this->option('dir');

        if (! is_string($dirOption) || trim($dirOption) === '') {
            $this->error('Please provide a directory with --dir=');

            return self::FAILURE;
        }

        $directory = realpath($dirOption) ?: $dirOption;

        if (! is_dir($directory)) {
            $this->error("Directory does not exist: {$directory}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $delay = max(0, (int) $this->option('delay'));

        $csvPath = $this->resolveCsvPath();
        $csvIndex = $this->loadCsvIndex($csvPath);

        if ($dryRun) {
            $this->warn('Dry run enabled. No files will be stored and no logs will be created.');
        }

        try {
            $metrics = $importer->import(
                directory: $directory,
                csvIndex: $csvIndex,
                dryRun: $dryRun,
                delay: $delay,
                force: $force,
                onProgress: function (string $filename, string $result, array $conflicts, ?string $message): void {
                    $this->line("[{$result}] {$filename}");

                    if ($message !== null) {
                        $this->line("  {$message}");
                    }

                    foreach ($conflicts as $conflict) {
                        $this->line("  [conflict] {$conflict['field']}: CSV \"{$conflict['csv']}\" differs from MP3 \"{$conflict['embedded']}\"");
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Legacy sermon import completed.');
        $this->line('Directory: '.$directory);
        $this->line('CSV: '.($csvPath ?? 'none'));

        $this->table(
            ['Metric', 'Value'],
            [
                ['Imported', (string) $metrics['imported']],
                ['Skipped (duplicate)', (string) $metrics['skipped']],
                ['Errors', (string) $metrics['errors']],
            ]
        );

        return $metrics['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCsvPath(): ?string
    {
        $csvOption = $this->option('csv');

        if (is_string($csvOption) && trim($csvOption) !== '') {
            return $csvOption;
        }

        $default = storage_path('app/Tape Index.csv');

        return file_exists($default) ? $default : null;
    }

    /**
     * Parse the CSV into an index keyed by tape ID (filename without extension, annotations stripped).
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadCsvIndex(?string $csvPath): array
    {
        if ($csvPath === null || ! file_exists($csvPath)) {
            if ($csvPath !== null) {
                $this->warn("CSV not found at {$csvPath} — import will proceed without metadata.");
            }

            return [];
        }

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            $this->warn("Could not open CSV at {$csvPath} — import will proceed without metadata.");

            return [];
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);
            $this->warn('CSV appears to be empty — import will proceed without metadata.');

            return [];
        }

        $headers = array_map(static fn (mixed $h): string => trim((string) $h), $headers);
        $index = [];

        while (($row = fgetcsv($handle)) !== false) {
            /** @var list<string|null> $row */
            $padded = array_pad($row, count($headers), '');
            $combined = array_combine($headers, $padded);

            $tapeId = trim($combined['Tape ID'] ?? $combined['TapeID'] ?? '');

            if ($tapeId === '') {
                continue;
            }

            // Strip #...# annotations from the tape ID before indexing
            $normalisedTapeId = trim((string) preg_replace('/#[^#]*#/', '', $tapeId));

            $index[strtolower($normalisedTapeId)] = $combined;
        }

        fclose($handle);

        return $index;
    }
}
