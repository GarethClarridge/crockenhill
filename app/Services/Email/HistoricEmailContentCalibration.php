<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\ChurchService\HistoricItemGroundTruth;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * Fits and scores a confidence threshold against item-level corroboration, never identity.
 *
 * This is deliberately report-only. It prepares IC3 item 1's evidence without changing the
 * live auto-import gate; any gate change still needs its own recorded decision.
 */
class HistoricEmailContentCalibration
{
    private const TrainingPercent = 80;

    private const MinimumPrecision = 0.98;

    /**
     * @param  array<string, mixed>  $archiveReport
     * @param  array<string, mixed>  $groundTruth
     * @return array<string, mixed>
     */
    public function calibrate(array $archiveReport, array $groundTruth): array
    {
        $labels = $this->labels($groundTruth);
        $observations = $this->observations($archiveReport, $labels);
        $training = array_values(array_filter($observations, fn (array $observation): bool => $observation['split'] === 'training'));
        $holdout = array_values(array_filter($observations, fn (array $observation): bool => $observation['split'] === 'holdout'));
        $threshold = $this->fitThreshold($training);

        return [
            'format' => 'historic-email-content-calibration',
            'version' => 1,
            'policy' => [
                'candidate' => 'model_confidence_baseline',
                'label' => 'Every applicable ground-truth content verdict is match. Identity correctness is not read.',
                'excluded_verdicts' => [
                    HistoricItemGroundTruth::VerdictNotCorroborated,
                    HistoricItemGroundTruth::VerdictIndeterminate,
                    HistoricItemGroundTruth::VerdictCircular,
                ],
                'split' => 'sha256(identity) modulo 100; 0-79 training, 80-99 holdout.',
                'minimum_training_precision' => self::MinimumPrecision,
                'live_gate_changed' => false,
            ],
            'input_hashes' => [
                'archive_report' => CanonicalJson::hash($archiveReport),
                'ground_truth' => CanonicalJson::hash($groundTruth),
            ],
            'observations' => [
                'labelled' => count($observations),
                'training' => count($training),
                'holdout' => count($holdout),
            ],
            'fit' => $threshold === null
                ? ['threshold' => null, 'reason' => 'No training threshold meets the minimum precision.']
                : ['threshold' => $threshold, 'reason' => null],
            'training' => $this->score($training, $threshold),
            'holdout' => $this->score($holdout, $threshold),
        ];
    }

    /**
     * @param  array<string, mixed>  $groundTruth
     * @return array<string, bool>
     */
    private function labels(array $groundTruth): array
    {
        if (($groundTruth['format'] ?? null) !== HistoricItemGroundTruth::Format || ! is_array($groundTruth['identities'] ?? null)) {
            throw new RuntimeException('The ground-truth artifact is not a supported historic item-level ground truth artifact.');
        }

        $labels = [];

        foreach ($groundTruth['identities'] as $identity) {
            if (! is_array($identity) || ! is_string($identity['key'] ?? null) || ! is_array($identity['verdicts'] ?? null)) {
                continue;
            }

            $applicable = array_values(array_filter(
                $identity['verdicts'],
                static fn (mixed $verdict): bool => in_array($verdict, [HistoricItemGroundTruth::VerdictMatch, HistoricItemGroundTruth::VerdictMismatch], true),
            ));

            if ($applicable === []) {
                continue;
            }

            $labels[$identity['key']] = ! in_array(HistoricItemGroundTruth::VerdictMismatch, $applicable, true);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $archiveReport
     * @param  array<string, bool>  $labels
     * @return list<array{identity:string,confidence:float,correct:bool,split:string}>
     */
    private function observations(array $archiveReport, array $labels): array
    {
        if (! is_array($archiveReport['entries'] ?? null)) {
            throw new RuntimeException('The archive report carries no entry records.');
        }

        $observations = [];

        foreach ($archiveReport['entries'] as $entry) {
            if (! is_array($entry) || ($entry['content_scope'] ?? null) !== 'full' || ! is_array($entry['plans'] ?? null)) {
                continue;
            }

            foreach ($entry['plans'] as $plan) {
                if (! is_array($plan) || ! is_string($plan['date'] ?? null) || ! is_string($plan['service'] ?? null) || ! is_numeric($plan['confidence'] ?? null)) {
                    continue;
                }

                $identity = $plan['date'].' '.$plan['service'];

                if (! array_key_exists($identity, $labels)) {
                    continue;
                }

                $confidence = (float) $plan['confidence'];

                if ($confidence < 0.0 || $confidence > 1.0) {
                    continue;
                }

                $observations[] = [
                    'identity' => $identity,
                    'confidence' => $confidence,
                    'correct' => $labels[$identity],
                    'split' => $this->split($identity),
                ];
            }
        }

        return $observations;
    }

    /** @param list<array{identity:string,confidence:float,correct:bool,split:string}> $observations */
    private function fitThreshold(array $observations): ?float
    {
        $thresholds = array_values(array_unique(array_column($observations, 'confidence')));
        sort($thresholds, SORT_NUMERIC);

        foreach ($thresholds as $threshold) {
            $score = $this->score($observations, (float) $threshold);

            if ($score['selected'] > 0 && $score['precision'] !== null && $score['precision'] >= self::MinimumPrecision) {
                return (float) $threshold;
            }
        }

        return null;
    }

    /**
     * @param  list<array{identity:string,confidence:float,correct:bool,split:string}>  $observations
     * @return array{correct:int,total:int,selected:int,true_positive:int,false_positive:int,false_negative:int,precision:?float,recall:?float}
     */
    private function score(array $observations, ?float $threshold): array
    {
        $truePositive = 0;
        $falsePositive = 0;
        $falseNegative = 0;

        foreach ($observations as $observation) {
            $selected = $threshold !== null && $observation['confidence'] >= $threshold;

            if ($selected && $observation['correct']) {
                $truePositive++;
            }

            if ($selected && ! $observation['correct']) {
                $falsePositive++;
            }

            if (! $selected && $observation['correct']) {
                $falseNegative++;
            }
        }

        $selected = $truePositive + $falsePositive;
        $total = count($observations);

        return [
            'correct' => $truePositive + $falseNegative,
            'total' => $total,
            'selected' => $selected,
            'true_positive' => $truePositive,
            'false_positive' => $falsePositive,
            'false_negative' => $falseNegative,
            'precision' => $selected === 0 ? null : round($truePositive / $selected, 4),
            'recall' => $truePositive + $falseNegative === 0
                ? null
                : round($truePositive / ($truePositive + $falseNegative), 4),
        ];
    }

    private function split(string $identity): string
    {
        return hexdec(substr(hash('sha256', $identity), 0, 8)) % 100 < self::TrainingPercent
            ? 'training'
            : 'holdout';
    }
}
