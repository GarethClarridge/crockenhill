<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DetectorEvaluation\DetectorCaseBookSource;
use App\Services\DetectorEvaluation\FreezeDetectorCaseBook;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Freeze §4.3a H3's detector case book to a private, hash-bound artifact.
 *
 * Read-only against the database. Refuses to overwrite, as the OoS freezer does:
 * a frozen book that could be regenerated in place after seeing a candidate's
 * results would not be frozen.
 */
class FreezeDetectorCaseBookCommand extends Command
{
    protected $signature = 'detectors:freeze-case-book
        {--source= : Authored case book (defaults to resources/detector-case-book.json)}
        {--output= : Absolute private JSON output path}';

    protected $description = 'Freeze and hash-bind the detector evaluation case book (read-only; refuses to overwrite)';

    public function handle(FreezeDetectorCaseBook $freezer): int
    {
        try {
            $output = (string) $this->option('output');

            if (! str_starts_with($output, '/')) {
                throw new RuntimeException('--output must be an absolute path.');
            }

            if (file_exists($output)) {
                throw new RuntimeException("Refusing to overwrite existing case book {$output}.");
            }

            $source = $this->option('source');
            $artifact = $freezer->build(DetectorCaseBookSource::load(is_string($source) && $source !== '' ? $source : null));

            if (file_put_contents($output, CanonicalJson::encodeReadable($artifact).PHP_EOL) === false || ! chmod($output, 0600)) {
                throw new RuntimeException("Could not write private case book {$output}.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $completeness = $artifact['completeness'];

        $this->components->info("Frozen {$completeness['cases']} cases over {$completeness['service_groups']} service groups at {$output}.");
        $this->table(['Measure', 'Value'], [
            ['Adjudicated truth (source reviewed, stored record, ruling)', (string) $completeness['adjudicated_cases']],
            ['Provisional truth (re-decode, transcript, derived)', (string) $completeness['provisional_cases']],
            ['In the eligible historic population', (string) $completeness['eligible_cases']],
            ['Aggregates (counts, not cases)', (string) count($artifact['aggregates'])],
        ]);
        $this->line("Case book hash: {$artifact['case_book_hash']}");

        return self::SUCCESS;
    }
}
