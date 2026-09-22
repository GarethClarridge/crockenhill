<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DetectorEvaluation\DetectorEvaluation;
use App\Support\CanonicalJson;
use App\Support\DetectorAcceptanceThresholds;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Score a frozen case book against the predeclared thresholds (§4.3a H7).
 *
 * Read-only. Exits non-zero when any detector fails, so the result cannot be
 * mistaken for a pass in a script. Recall is not measured until H10's sample is
 * drawn, so the best verdict available today is `not_established`.
 */
class EvaluateDetectorsCommand extends Command
{
    protected $signature = 'detectors:evaluate
        {--case-book= : Frozen case book artifact (from detectors:freeze-case-book)}
        {--split=* : Splits to score (default: regression and development)}
        {--report= : Write the full JSON report to this path}';

    protected $description = 'Score a frozen detector case book against the predeclared thresholds (read-only)';

    public function handle(DetectorEvaluation $evaluation): int
    {
        try {
            $path = (string) $this->option('case-book');
            $contents = $path !== '' && is_readable($path) ? file_get_contents($path) : false;

            if (! is_string($contents)) {
                throw new RuntimeException('--case-book must name a readable frozen case book.');
            }

            $caseBook = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            /** @var list<string> $splits */
            $splits = (array) $this->option('split');
            $report = $evaluation->evaluate(
                is_array($caseBook) ? $caseBook : [],
                DetectorAcceptanceThresholds::load(),
                $splits !== [] ? $splits : ['regression', 'development'],
            );

            $reportPath = $this->option('report');

            if (is_string($reportPath) && $reportPath !== '' && file_put_contents($reportPath, CanonicalJson::encodeReadable($report).PHP_EOL) === false) {
                throw new RuntimeException("Could not write the report to {$reportPath}.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($report['detectors'] as $id => $detector) {
            $rows[] = [
                $id,
                (string) $detector['severity'],
                $detector['regression']['verdict'],
                $detector['false_positive']['verdict'],
                $detector['acceptance'],
            ];
        }

        $this->table(['Detector', 'Sev', 'Regression', 'False positives', 'Acceptance'], $rows);
        $this->components->warn('Recall and review burden are not measured until H10\'s detector-negative sample is drawn; no detector can be accepted yet.');

        return ($report['summary']['acceptance']['fail'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
