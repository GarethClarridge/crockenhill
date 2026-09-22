<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\Audio\TranscriptRedecodeReport;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * H10b's cheap half: score re-decode artifacts into one private, create-once report.
 *
 * Read-only against the database, the runs and the media. Re-scoring never
 * re-decodes; point --against-dir at a second decode of the same runs for C1.
 */
class CompareTranscriptRedecodeCommand extends Command
{
    protected $signature = 'service:compare-transcript-redecode
        {--input-dir= : Directory of service:redecode-transcripts artifacts}
        {--against-dir= : A second decode of the same runs (control C1) instead of the stored transcripts}
        {--output= : Absolute private JSON output path}';

    protected $description = 'Score re-decoded transcripts against stored ones per 30-second window (read-only; refuses to overwrite)';

    public function handle(TranscriptRedecodeReport $reporter): int
    {
        try {
            $output = (string) $this->option('output');

            if (! str_starts_with($output, '/')) {
                throw new RuntimeException('--output must be an absolute path.');
            }

            if (file_exists($output)) {
                throw new RuntimeException("Refusing to overwrite existing report {$output}.");
            }

            $against = $this->option('against-dir');
            $report = $reporter->build((string) $this->option('input-dir'), is_string($against) && $against !== '' ? $against : null);

            if (file_put_contents($output, CanonicalJson::encodeReadable($report).PHP_EOL) === false || ! chmod($output, 0600)) {
                throw new RuntimeException("Could not write private report {$output}.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($report['runs'] as $run) {
            $summary = $run['summary'];
            $rows[] = [$run['run_id'], $summary['windows'], $summary['differing_windows'], $summary['mean_distance'], $summary['max_distance'], $summary['stored_blocks'] ?? '—', $summary['new_blocks']];
        }

        foreach ($report['unassessable'] as $run) {
            $rows[] = [$run['run_id'], 'unassessable: '.$run['reason'], '', '', '', '', ''];
        }

        $this->components->info(sprintf('Compared %d run(s) against %s decode(s) at %s.', count($report['runs']), $report['mode'], $output));
        $this->table(['Run', 'Windows', 'Differing', 'Mean distance', 'Max', 'Left blocks', 'New blocks'], $rows);
        $this->line('Report hash: '.CanonicalJson::hash($report));

        return self::SUCCESS;
    }
}
