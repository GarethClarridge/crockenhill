<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleScorer;
use Illuminate\Console\Command;
use RuntimeException;

/** Reusable read-only review instrument for banked ensemble draws. */
class ReplayServiceStructureEnsembleCommand extends Command
{
    protected $signature = 'structure:ensemble-replay {processingId} {--attempt= : Replay this attempt instead of the latest} {--truth= : Existing labelled truth JSON} {--truth-run= : Run key within the truth file} {--report= : Write the JSON comparison to this path}';

    protected $description = 'Recompose one saved four-draw bundle without provider calls or authoritative writes';

    public function handle(ServiceStructureEnsembleReplay $replay, ServiceStructureEnsembleScorer $scorer): int
    {
        $log = MediaProcessingLog::query()->where('processing_id', (string) $this->argument('processingId'))->first();

        if (! $log instanceof MediaProcessingLog) {
            throw new RuntimeException('Processing run not found for ensemble replay.');
        }

        $bank = $log->processing_metadata?->raw['service_structure_ensemble'] ?? null;

        if (! is_array($bank) || $bank === []) {
            throw new RuntimeException('Processing run has no banked ensemble attempts.');
        }

        $requested = $this->option('attempt');
        $attempt = $requested === null || $requested === ''
            ? end($bank)
            : collect($bank)->firstWhere('attempt_id', $requested);

        if (! is_array($attempt)) {
            throw new RuntimeException('Requested ensemble attempt is not banked on this run.');
        }

        $savedRulings = $log->processing_metadata?->raw['service_structure_ensemble_rulings'] ?? [];

        if (! is_array($savedRulings) || ! array_is_list($savedRulings)
            || count(array_filter($savedRulings, 'is_array')) !== count($savedRulings)) {
            throw new RuntimeException('Ensemble ruling history is malformed.');
        }

        $report = $replay->replay($attempt, $savedRulings, $log);
        $report['processing_id'] = $log->processing_id;
        $report['changed_from_banked'] = ($report['before']['structure'] ?? null) !== $report['structure']
            || ($report['before']['disputes'] ?? null) !== $report['disputes']
            || ($report['before']['validation_passed'] ?? null) !== $report['validation_passed'];

        $truthPath = $this->option('truth');
        $truthRun = $this->option('truth-run');

        if (is_string($truthPath) && $truthPath !== '') {
            if (! is_string($truthRun) || $truthRun === '' || ! is_file($truthPath)) {
                throw new RuntimeException('A readable truth file and --truth-run key are required to score replay.');
            }

            $truthFile = json_decode((string) file_get_contents($truthPath), true, flags: JSON_THROW_ON_ERROR);
            $truth = $truthFile['runs'][$truthRun] ?? null;

            if (! is_array($truth) || ! array_is_list($truth)
                || count(array_filter($truth, 'is_array')) !== count($truth)) {
                throw new RuntimeException('Truth file has no entry for the requested run key.');
            }

            $report['score'] = $scorer->score($report, $truth);
            $report['truth_run'] = $truthRun;
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException('Could not create ensemble replay report directory.');
            }

            if (file_put_contents($path, $json) === false) {
                throw new RuntimeException('Could not write ensemble replay report.');
            }

            $this->info("Read-only ensemble replay written to {$path}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
