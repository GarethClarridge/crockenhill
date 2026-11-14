<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\ProductionCensusExporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Delete when the historic programme's operational closeout (IC8) completes.
 */
class ProductionCensusCommand extends Command
{
    protected $signature = 'historic-import:production-census
        {--output= : New private UTF-8 JSONL file; its parent must already exist}
        {--skip-hashes : Read media sizes without opening media streams}';

    protected $description = 'Export the complete local identity census in a read-only database snapshot';

    public function handle(ProductionCensusExporter $exporter): int
    {
        $path = $this->option('output');
        if (! is_string($path) || $path === '') {
            $this->error('--output is required.');

            return self::FAILURE;
        }

        try {
            $counts = $exporter->export($path, (bool) $this->option('skip-hashes'));
            $this->info('Census complete: '.array_sum($counts).' rows. Copy the single JSONL file.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
