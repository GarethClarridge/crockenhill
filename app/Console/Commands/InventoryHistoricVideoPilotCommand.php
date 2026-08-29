<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricVideoPilotLedger;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only evidence capture for the historic-video pilot freeze.
 *
 * Delete at IC8 closeout with the remaining historic-import one-shot reporting surface.
 */
class InventoryHistoricVideoPilotCommand extends Command
{
    protected $signature = 'historic:inventory-video-pilot
                            {selection : Exact pilot-selection JSON file}
                            {--operation= : Immutable operation id that owned the pilot}
                            {--manifest= : Approved curation manifest naming the date and service of every identity}
                            {--output= : New permission-restricted ledger JSON path}';

    protected $description = 'Capture the exact operation, graph and staged-byte ledger for a historic-video pilot';

    public function handle(HistoricVideoPilotLedger $ledger): int
    {
        try {
            $output = $this->newOutputPath();
            $manifest = $this->option('manifest');
            $report = $ledger->build(
                $this->argument('selection'),
                $this->requiredOption('operation'),
                is_string($manifest) && trim($manifest) !== '' ? trim($manifest) : null,
            );
            $this->createOnce($output, CanonicalJson::encodeReadable($report).PHP_EOL);

            $this->info('Historic-video pilot ledger captured.');
            $this->line("Ledger hash: {$report['ledger_hash']}");
            $this->line('Exit gate: '.($report['exit_gate_passed'] ? 'PASS' : 'FAIL'));

            return $report['exit_gate_passed'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return trim($value);
    }

    private function newOutputPath(): string
    {
        $path = $this->requiredOption('output');

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('Pilot ledger requires an absolute --output path.');
        }

        if (file_exists($path)) {
            throw new RuntimeException("Refusing to overwrite existing pilot ledger: {$path}.");
        }

        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new RuntimeException('Pilot ledger output directory is not writable.');
        }

        return $path;
    }

    private function createOnce(string $path, string $contents): void
    {
        $handle = fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Unable to create pilot ledger: {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write pilot ledger: {$path}.");
            }
        } finally {
            fclose($handle);
        }

        chmod($path, 0600);
    }
}
