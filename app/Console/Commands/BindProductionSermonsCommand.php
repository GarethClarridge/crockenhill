<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleFiles;
use App\Services\HistoricMedia\ProductionSermonBinding;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Delete after historic closeout (IC8) and expiry of the replacement rollback window. */
class BindProductionSermonsCommand extends Command
{
    protected $signature = 'historic-import:bind-production-sermons
        {--bundle= : Approved Bundle A JSON file}
        {--census= : Complete hashed production-census JSONL file}
        {--mapping= : JSON list of processing_key and target_sermon_id (null explicitly creates)}
        {--output= : New private bound Bundle A file}';

    protected $description = 'Bind exported runs to enumerated census sermons without database or media writes';

    public function handle(ProductionSermonBinding $bindings, HistoricProcessingResultBundle $bundles, HistoricProcessingResultBundleFiles $files): int
    {
        try {
            $bundle = $bundles->decode($this->readOption('bundle'));
            $mapping = json_decode($this->readOption('mapping'), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($mapping) || ! array_is_list($mapping)) {
                throw new RuntimeException('--mapping must contain a JSON list.');
            }
            $bound = $bindings->bind($bundle, $this->requiredOption('census'), $mapping);
            $path = $files->write($this->requiredOption('output'), $bound, overwrite: false);
            $this->info('Production binding written: '.$path);
            $this->line('Bundle hash: '.$bound['bundle_hash']);
            $this->line('Binding hash: '.$bound['production_binding']['binding_hash']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readOption(string $name): string
    {
        $contents = @file_get_contents($this->requiredOption($name));
        if ($contents === false) {
            throw new RuntimeException('Cannot read --'.$name.'.');
        }

        return $contents;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }
}
