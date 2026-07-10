<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Models\Song;

class OosArchiveEvaluator
{
    /**
     * @param  list<string>  $gateReasons
     * @param  list<string>  $songCanonicalKeys
     * @return array<string, mixed>
     */
    public function evaluate(
        OosArchiveEntry $entry,
        ?OosEmailParseResult $parseResult,
        string $disposition = 'evaluated',
        array $gateReasons = [],
        array $songCanonicalKeys = [],
        ?string $error = null,
    ): array {
        $detectedService = $parseResult?->service?->value;
        $detectedDate = $parseResult?->date;
        $confidence = $parseResult?->confidenceScore;
        $items = $parseResult === null ? [] : $parseResult->items;
        $dateMethod = $parseResult === null
            ? null
            : ($parseResult->importMetadata['date_extraction']['method'] ?? null);
        $detectedServices = $detectedService === null ? [] : [$detectedService];
        $dateMatches = $entry->groundTruthDate !== null && $detectedDate === $entry->groundTruthDate;
        $expectedLines = $detectedService === null ? [] : ($entry->itemLines[$detectedService] ?? []);
        $orderedItemQuality = $entry->labelQuality === 'full'
            ? $this->sequenceQuality($expectedLines, array_column($items, 'title'))
            : null;
        $serviceMatches = $detectedService !== null && in_array($detectedService, $entry->servicesPresent, true);
        $exactCorrect = $entry->labelQuality === 'full' && $dateMatches && $serviceMatches && $items !== [];
        $songLink = $this->songLinkMetrics($items, $songCanonicalKeys);

        return [
            'index' => $entry->index,
            'heading' => $entry->heading,
            'subject' => $entry->subject,
            'message_id' => $entry->syntheticMessageId,
            'input_hash' => $entry->inputHash,
            'label_quality' => $entry->labelQuality,
            'flags' => $entry->flags,
            'error' => $error,
            'date' => [
                'expected' => $entry->groundTruthDate,
                'detected' => $detectedDate,
                'matches' => $dateMatches,
                'method' => $dateMethod,
            ],
            'services' => [
                'expected' => $entry->servicesPresent,
                'detected' => $detectedServices,
            ],
            'item_counts' => [
                'expected' => $entry->itemLineCounts,
                'detected' => $detectedService === null ? [] : [$detectedService => count($items)],
            ],
            'plans' => $detectedService === null ? [] : [[
                'service' => $detectedService,
                'date' => $detectedDate,
                'item_count' => count($items),
                'confidence' => $confidence,
                'exact_correct' => $exactCorrect,
                'ordered_item_quality' => $orderedItemQuality,
                'gate_eligible' => $gateReasons === [],
                'gate_reasons' => $gateReasons,
            ]],
            'confidence' => $confidence,
            'disposition' => $disposition,
            'gate_eligible' => $parseResult !== null && $gateReasons === [],
            'gate_reasons' => $gateReasons,
            'song_link' => $songLink,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function aggregate(array $entries): array
    {
        $dateAll = $this->dateAccuracy($entries);
        $dateFull = $this->dateAccuracy(array_values(array_filter($entries, fn (array $entry): bool => $entry['label_quality'] === 'full')));
        $dateUnverified = $this->dateAccuracy(array_values(array_filter($entries, fn (array $entry): bool => $entry['label_quality'] === 'unverified')));
        $methods = [];
        $falseDates = [];
        $dispositions = [];
        $songHits = 0;
        $songTotal = 0;
        $blocked = [];

        foreach ($entries as $entry) {
            $method = $entry['date']['method'] ?? null;
            if (is_string($method) && $method !== '') {
                $methods[$method] = ($methods[$method] ?? 0) + 1;
            }

            if (($entry['date']['detected'] ?? null) !== null && ! ($entry['date']['matches'] ?? false)) {
                $falseDates[] = [
                    'index' => $entry['index'],
                    'heading' => $entry['heading'],
                    'expected' => $entry['date']['expected'],
                    'detected' => $entry['date']['detected'],
                ];
            }

            $disposition = (string) $entry['disposition'];
            $dispositions[$disposition] = ($dispositions[$disposition] ?? 0) + 1;
            $songHits += (int) ($entry['song_link']['hits'] ?? 0);
            $songTotal += (int) ($entry['song_link']['total'] ?? 0);

            if (($entry['date']['expected'] ?? null) === null || ($entry['flags'] ?? []) !== []) {
                $blocked[] = [
                    'index' => $entry['index'],
                    'heading' => $entry['heading'],
                    'reasons' => ($entry['flags'] ?? []) !== [] ? $entry['flags'] : ['unresolved_date'],
                ];
            }
        }

        ksort($methods);
        ksort($dispositions);

        return [
            'date_accuracy' => [
                'all' => $dateAll,
                'full' => $dateFull,
                'unverified' => $dateUnverified,
            ],
            'date_extraction_methods' => $methods,
            'false_date_cases' => $falseDates,
            'service_metrics' => [
                'morning' => $this->serviceMetrics($entries, 'morning'),
                'evening' => $this->serviceMetrics($entries, 'evening'),
            ],
            'auto_import_precision' => $this->autoImportPrecision($entries),
            'confidence_calibration' => $this->confidenceCalibration($entries),
            'dispositions' => $dispositions,
            'song_link_hit_rate' => [
                'hits' => $songHits,
                'total' => $songTotal,
                'rate' => $this->rate($songHits, $songTotal),
            ],
            'unresolved_or_blocked' => $blocked,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{correct:int,total:int,rate:?float}
     */
    private function dateAccuracy(array $entries): array
    {
        $total = count($entries);
        $correct = count(array_filter($entries, fn (array $entry): bool => (bool) ($entry['date']['matches'] ?? false)));

        return ['correct' => $correct, 'total' => $total, 'rate' => $this->rate($correct, $total)];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{true_positive:int,false_positive:int,false_negative:int,precision:?float,recall:?float}
     */
    private function serviceMetrics(array $entries, string $service): array
    {
        $truePositive = 0;
        $falsePositive = 0;
        $falseNegative = 0;

        foreach ($entries as $entry) {
            if (($entry['label_quality'] ?? null) !== 'full') {
                continue;
            }

            $expected = in_array($service, $entry['services']['expected'] ?? [], true);
            $detected = in_array($service, $entry['services']['detected'] ?? [], true);

            if ($expected && $detected) {
                $truePositive++;
            }

            if (! $expected && $detected) {
                $falsePositive++;
            }

            if ($expected && ! $detected) {
                $falseNegative++;
            }
        }

        return [
            'true_positive' => $truePositive,
            'false_positive' => $falsePositive,
            'false_negative' => $falseNegative,
            'precision' => $this->rate($truePositive, $truePositive + $falsePositive),
            'recall' => $this->rate($truePositive, $truePositive + $falseNegative),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{correct:int,total:int,rate:?float}
     */
    private function autoImportPrecision(array $entries): array
    {
        $plans = [];

        foreach ($entries as $entry) {
            if (($entry['label_quality'] ?? null) !== 'full') {
                continue;
            }

            foreach ($entry['plans'] ?? [] as $plan) {
                if (($plan['gate_eligible'] ?? false) === true) {
                    $plans[] = $plan;
                }
            }
        }

        $correct = count(array_filter($plans, fn (array $plan): bool => (bool) ($plan['exact_correct'] ?? false)));

        return ['correct' => $correct, 'total' => count($plans), 'rate' => $this->rate($correct, count($plans))];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array{correct:int,total:int,accuracy:?float}>
     */
    private function confidenceCalibration(array $entries): array
    {
        $bands = [
            '0.00-0.49' => ['min' => 0.0, 'max' => 0.49, 'correct' => 0, 'total' => 0],
            '0.50-0.74' => ['min' => 0.50, 'max' => 0.74, 'correct' => 0, 'total' => 0],
            '0.75-0.89' => ['min' => 0.75, 'max' => 0.89, 'correct' => 0, 'total' => 0],
            '0.90-1.00' => ['min' => 0.90, 'max' => 1.0, 'correct' => 0, 'total' => 0],
        ];

        foreach ($entries as $entry) {
            foreach ($entry['plans'] ?? [] as $plan) {
                $confidence = $plan['confidence'] ?? null;
                if (! is_numeric($confidence)) {
                    continue;
                }

                foreach ($bands as &$band) {
                    if ((float) $confidence >= $band['min'] && (float) $confidence <= $band['max']) {
                        $band['total']++;
                        $band['correct'] += ($plan['exact_correct'] ?? false) ? 1 : 0;

                        break;
                    }
                }
                unset($band);
            }
        }

        return array_map(fn (array $band): array => [
            'correct' => $band['correct'],
            'total' => $band['total'],
            'accuracy' => $this->rate($band['correct'], $band['total']),
        ], $bands);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $detected
     */
    private function sequenceQuality(array $expected, array $detected): ?float
    {
        if ($expected === [] && $detected === []) {
            return 1.0;
        }

        $denominator = max(count($expected), count($detected));
        if ($denominator === 0) {
            return null;
        }

        $expected = array_map($this->normaliseTitle(...), $expected);
        $detected = array_map($this->normaliseTitle(...), $detected);
        $matrix = array_fill(0, count($expected) + 1, array_fill(0, count($detected) + 1, 0));

        for ($left = 1; $left <= count($expected); $left++) {
            for ($right = 1; $right <= count($detected); $right++) {
                $matrix[$left][$right] = $expected[$left - 1] === $detected[$right - 1]
                    ? $matrix[$left - 1][$right - 1] + 1
                    : max($matrix[$left - 1][$right], $matrix[$left][$right - 1]);
            }
        }

        return round($matrix[count($expected)][count($detected)] / $denominator, 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  list<string>  $songCanonicalKeys
     * @return array{hits:int,total:int,rate:?float}
     */
    private function songLinkMetrics(array $items, array $songCanonicalKeys): array
    {
        $songs = array_values(array_filter($items, fn (array $item): bool => ($item['type'] ?? null) === 'songs'));
        $hits = count(array_filter($songs, fn (array $item): bool => in_array(
            Song::canonicalizeKey((string) ($item['title'] ?? '')),
            $songCanonicalKeys,
            true,
        )));

        return ['hits' => $hits, 'total' => count($songs), 'rate' => $this->rate($hits, count($songs))];
    }

    private function normaliseTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = (string) preg_replace('/^\s*(?:nip|praise)?\s*\d+[a-z]?\s*[‘\'“"]?/iu', '', $title);
        $title = (string) preg_replace('/[^\pL\pN]+/u', ' ', $title);

        return trim($title);
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator / $denominator, 4);
    }
}
