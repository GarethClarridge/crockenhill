<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Services\Song\SongTitleResolver;

class OosArchiveEvaluator
{
    /**
     * @param  list<string>  $gateReasons
     * @param  list<string>  $eligiblePlanKeys  plan keys the archive gate would actually import
     * @return array<string, mixed>
     */
    public function evaluate(
        OosArchiveEntry $entry,
        ?OosEmailParseResult $parseResult,
        string $disposition = 'evaluated',
        array $gateReasons = [],
        ?SongTitleResolver $songTitleResolver = null,
        ?string $error = null,
        array $eligiblePlanKeys = [],
    ): array {
        $plans = $parseResult === null ? [] : $this->plansForEvaluation($parseResult);
        $detectedDate = $parseResult?->date;
        $confidence = $parseResult?->confidenceScore;
        $dateMethod = $parseResult === null
            ? null
            : ($parseResult->importMetadata['date_extraction']['method'] ?? null);
        $dateMatches = $entry->groundTruthDate !== null && $detectedDate === $entry->groundTruthDate;

        $detectedServices = [];
        $detectedItemCounts = [];
        $allItems = [];
        $planRecords = [];

        foreach ($plans as $plan) {
            $service = $plan->service?->value;

            if ($service !== null) {
                if (! in_array($service, $detectedServices, true)) {
                    $detectedServices[] = $service;
                }

                $detectedItemCounts[$service] = ($detectedItemCounts[$service] ?? 0) + count($plan->items);
            }

            $allItems = array_merge($allItems, $plan->items);
            $planRecords[] = $this->planRecord($entry, $plan, $gateReasons, $eligiblePlanKeys);
        }

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
                'detected' => $detectedItemCounts,
            ],
            'plans' => $planRecords,
            'confidence' => $confidence,
            'disposition' => $disposition,
            'gate_eligible' => $parseResult !== null && $gateReasons === [],
            'gate_reasons' => $gateReasons,
            'song_link' => $this->songLinkMetrics($allItems, $songTitleResolver),
        ];
    }

    /**
     * Every plan the parser produced; a legacy parse result without explicit plans still
     * evaluates as a single synthesised plan so old stored parses keep reporting.
     *
     * @return list<OosEmailServicePlan>
     */
    private function plansForEvaluation(OosEmailParseResult $parseResult): array
    {
        if ($parseResult->servicePlans !== []) {
            return $parseResult->servicePlans;
        }

        if ($parseResult->service === null && $parseResult->date === null && $parseResult->items === []) {
            return [];
        }

        return [new OosEmailServicePlan(
            service: $parseResult->service,
            date: $parseResult->date,
            items: $parseResult->items,
            confidence: $parseResult->confidenceScore,
            needsReview: $parseResult->needsReview,
            shouldImport: $parseResult->shouldImport,
        )];
    }

    /**
     * @param  list<string>  $gateReasons
     * @param  list<string>  $eligiblePlanKeys
     * @return array<string, mixed>
     */
    private function planRecord(
        OosArchiveEntry $entry,
        OosEmailServicePlan $plan,
        array $gateReasons,
        array $eligiblePlanKeys,
    ): array {
        $service = $plan->service?->value;
        $dateMatches = $entry->groundTruthDate !== null && $plan->date === $entry->groundTruthDate;
        $serviceMatches = $service !== null && in_array($service, $entry->servicesPresent, true);
        $expectedLines = $service === null ? [] : ($entry->itemLines[$service] ?? []);

        return [
            'plan_key' => $plan->key(),
            'service' => $service,
            'date' => $plan->date,
            'item_count' => count($plan->items),
            'confidence' => $plan->confidence,
            'exact_correct' => $entry->labelQuality === 'full' && $dateMatches && $serviceMatches && $plan->items !== [],
            'ordered_item_quality' => $entry->labelQuality === 'full'
                ? $this->sequenceQuality($expectedLines, array_column($plan->items, 'title'))
                : null,
            'gate_eligible' => $gateReasons === [] && in_array($plan->key(), $eligiblePlanKeys, true),
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
        $songMatchTypes = [];
        $unmatchedSongTitles = [];
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

            foreach ($entry['song_link']['by_type'] ?? [] as $matchType => $count) {
                $songMatchTypes[$matchType] = ($songMatchTypes[$matchType] ?? 0) + (int) $count;
            }

            foreach ($entry['song_link']['unmatched_titles'] ?? [] as $title) {
                $unmatchedSongTitles[$title] = ($unmatchedSongTitles[$title] ?? 0) + 1;
            }

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
        ksort($songMatchTypes);
        arsort($unmatchedSongTitles);

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
                'by_type' => $songMatchTypes,
                'top_unmatched_titles' => array_slice($unmatchedSongTitles, 0, 25, preserve_keys: true),
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
            // Accuracy per band is only measurable against verified ground truth.
            if (($entry['label_quality'] ?? null) !== 'full') {
                continue;
            }

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
     * Runs each extracted song title through the same resolver the live linker uses, so the
     * eval measures the real cascade. A null resolver (dry-run) reports totals but no rate.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{hits:int,total:int,rate:?float,by_type:array<string,int>,unmatched_titles:list<string>}
     */
    private function songLinkMetrics(array $items, ?SongTitleResolver $songTitleResolver): array
    {
        $songs = array_values(array_filter($items, fn (array $item): bool => ($item['type'] ?? null) === 'songs'));

        $hits = 0;
        $byType = [];
        $unmatchedTitles = [];

        if ($songTitleResolver !== null) {
            foreach ($songs as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $match = $title === '' ? null : $songTitleResolver->resolve($title);

                if ($match === null) {
                    $unmatchedTitles[] = $title === '' ? '(blank title)' : $title;

                    continue;
                }

                $hits++;
                $byType[$match->matchType] = ($byType[$match->matchType] ?? 0) + 1;
            }
        }

        ksort($byType);

        return [
            'hits' => $hits,
            'total' => count($songs),
            'rate' => $songTitleResolver === null ? null : $this->rate($hits, count($songs)),
            'by_type' => $byType,
            'unmatched_titles' => array_slice($unmatchedTitles, 0, 20),
        ];
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
