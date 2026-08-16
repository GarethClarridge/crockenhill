<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\SongTitleHygieneVerdict;
use App\Services\Song\SongTitleHygiene;
use App\Services\Song\SongTitleResolver;

class OosArchiveEvaluator
{
    /** Entry dispositions that leave the source outstanding; the hold census counts only these. */
    private const HeldDispositions = ['held_for_review', 'import_failed', 'failed'];

    public function __construct(
        private readonly SongTitleHygiene $titleHygiene = new SongTitleHygiene,
    ) {}

    /**
     * @param  list<string>  $gateReasons
     * @param  list<string>  $eligiblePlanKeys  plan keys the archive gate would actually import
     * @param  list<string>|null  $importedPlanKeys  plan keys the importer reported as imported, or null when no import ran
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
        ?array $importedPlanKeys = null,
    ): array {
        $plans = $parseResult === null ? [] : $this->plansForEvaluation($parseResult);
        $detectedDate = $parseResult?->date;
        $confidence = $parseResult?->confidenceScore;
        $consensus = $parseResult === null ? false : $parseResult->consensus;
        $attemptCount = $parseResult === null ? 0 : count($parseResult->extractionAttempts);
        $dateMethod = $parseResult === null
            ? null
            : ($parseResult->importMetadata['date_extraction']['method'] ?? null);
        $dateMatches = $detectedDate === $entry->groundTruthDate;

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
            $planRecords[] = $this->planRecord($entry, $plan, $gateReasons, $eligiblePlanKeys, $consensus);
        }

        $corroboratedPlanKeys = array_values(array_unique($eligiblePlanKeys));
        $entryHeld = in_array($disposition, self::HeldDispositions, true);
        /**
         * Null means no import was attempted (a dry run, or an evaluate-only mode), which is not
         * the same fact as "an import ran and imported nothing". Inferring the imported set from
         * plan dispositions instead of taking it from the import result mislabelled every plan of
         * an `import_failed` entry as held, including plans that had already been created.
         */
        $heldPlanKeys = $importedPlanKeys === null
            ? null
            : array_values(array_map(
                static fn (array $plan): string => $plan['plan_key'],
                array_filter($planRecords, static fn (array $plan): bool => ! in_array($plan['plan_key'], $importedPlanKeys, true)),
            ));

        return [
            'index' => $entry->index,
            'item_key' => $entry->itemKey,
            'subject' => $entry->subject,
            'message_id' => $entry->syntheticMessageId,
            'input_hash' => $entry->inputHash,
            'content_scope' => $entry->contentScope,
            // The curation decisions that authorised this entry, so a reader of the report can
            // see on whose authority it was processed without opening the manifest.
            'curation' => $entry->curation,
            // Run-time observations; filled in by the caller, which owns the thresholds. Present
            // and empty on entries that were never parsed, so the report shape stays uniform.
            'flags' => [],
            'parse_flags' => [],
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
            'attempt_count' => $attemptCount,
            'attempt_disagreement_categories' => $this->attemptDisagreementCategories($parseResult),
            /**
             * Kept apart deliberately. `consensus` is two independent attempts agreeing and clears
             * the import gate above the review threshold; `adjudicated` is a third call choosing
             * between two disagreeing candidates and never clears it (HIR-D6). Collapsing them
             * would make an adjudicated plan indistinguishable from a corroborated one in the very
             * report meant to measure whether adjudication is trustworthy.
             */
            'adjudicated' => $parseResult !== null && $parseResult->adjudicated,
            'corroborated_plan_keys' => $corroboratedPlanKeys,
            'imported_plan_keys' => $importedPlanKeys,
            'held_plan_keys' => $heldPlanKeys,
            'held' => $entryHeld,
            'hold_reason_categories' => $this->holdReasonCategories($planRecords, $gateReasons, $entryHeld),
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
        bool $consensus,
    ): array {
        $service = $plan->service?->value;
        $dateMatches = $plan->date === $entry->groundTruthDate;
        $serviceMatches = $service !== null && in_array($service, $entry->servicesPresent, true);
        $expectedItems = $service === null ? null : ($entry->itemLineCounts[$service] ?? null);

        return [
            'plan_key' => $plan->key(),
            'service' => $service,
            'date' => $plan->date,
            'item_count' => count($plan->items),
            /**
             * Null unless a person read the source and asserted a count. §7.5 keeps heuristic
             * counts out of the manifest, so most entries have nothing to reconcile against and
             * say so, rather than scoring against a number nobody stands behind.
             */
            'expected_item_count' => $expectedItems,
            'item_count_matches' => $expectedItems === null ? null : $expectedItems === count($plan->items),
            'confidence' => $plan->confidence,
            'content_scope' => $plan->contentScope->value,
            'disposition' => $plan->disposition->value,
            'consensus' => $consensus,
            'validation_reasons' => $plan->validationReasons,
            'content_validation_reasons' => $plan->contentValidationReasons,
            'hold_reasons' => $plan->holdReasonValues(),
            'exact_correct' => $entry->assertsFullOrder() && $dateMatches && $serviceMatches && $plan->items !== [],
            'gate_eligible' => $gateReasons === [] && in_array($plan->key(), $eligiblePlanKeys, true),
        ];
    }

    /**
     * The hold census for one *source*, and only for a source that was actually held. A held plan
     * inside an imported entry is still reported at plan level, but counting it here would mix the
     * source and plan units the follow-up report warns must be kept apart.
     *
     * Reasons are read from what the parser recorded, never rebuilt from reason text.
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  list<string>  $gateReasons
     * @return list<string>
     */
    private function holdReasonCategories(array $plans, array $gateReasons, bool $entryHeld): array
    {
        if (! $entryHeld) {
            return [];
        }

        $categories = $gateReasons === [] ? [] : ['source_gate'];

        foreach ($plans as $plan) {
            foreach ($plan['hold_reasons'] ?? [] as $reason) {
                $categories[] = $reason;
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * What the two attempts actually disagreed about, as the parser recorded it at the moment it
     * compared them. This was previously reconstructed here by diffing the stored attempt arrays,
     * which reported a corrective call that threw — an attempt with an `error` and no plans — as a
     * disagreement across every compared field.
     *
     * @return list<string>
     */
    private function attemptDisagreementCategories(?OosEmailParseResult $parseResult): array
    {
        $recorded = $parseResult?->extractionAttempts[1]['disagreement_categories'] ?? null;

        if (! is_array($recorded)) {
            return [];
        }

        return array_values(array_filter($recorded, is_string(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function aggregate(array $entries): array
    {
        $dateAll = $this->dateAccuracy($entries);
        $dateFull = $this->dateAccuracy(array_values(array_filter($entries, fn (array $entry): bool => $entry['content_scope'] === 'full')));
        $datePartial = $this->dateAccuracy(array_values(array_filter($entries, fn (array $entry): bool => $entry['content_scope'] === 'partial')));
        $methods = [];
        $falseDates = [];
        $dispositions = [];
        $songHits = 0;
        $songTotal = 0;
        $songMatchTypes = [];
        $unmatchedSongTitles = [];
        $hygieneVerdicts = [];
        $hygieneDefects = [];
        $hygieneRecovered = 0;
        $itemCountsChecked = 0;
        $itemCountsMatched = 0;
        $parseFlags = [];
        $holdReasonCategories = [];
        $planHoldReasons = [];
        $planDispositions = [];
        $adjudicatedSources = 0;

        foreach ($entries as $entry) {
            $method = $entry['date']['method'] ?? null;
            if (is_string($method) && $method !== '') {
                $methods[$method] = ($methods[$method] ?? 0) + 1;
            }

            if (($entry['date']['detected'] ?? null) !== null && ! ($entry['date']['matches'] ?? false)) {
                $falseDates[] = [
                    'index' => $entry['index'],
                    'item_key' => $entry['item_key'],
                    'expected' => $entry['date']['expected'],
                    'detected' => $entry['date']['detected'],
                ];
            }

            foreach ($entry['plans'] ?? [] as $plan) {
                if (($plan['item_count_matches'] ?? null) === null) {
                    continue;
                }

                $itemCountsChecked++;
                $itemCountsMatched += $plan['item_count_matches'] === true ? 1 : 0;
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

            foreach ($entry['song_link']['hygiene'] ?? [] as $verdict => $count) {
                $hygieneVerdicts[$verdict] = ($hygieneVerdicts[$verdict] ?? 0) + (int) $count;
            }

            foreach ($entry['song_link']['hygiene_defects'] ?? [] as $defect => $count) {
                $hygieneDefects[$defect] = ($hygieneDefects[$defect] ?? 0) + (int) $count;
            }

            $hygieneRecovered += (int) ($entry['song_link']['hygiene_recovered'] ?? 0);

            foreach ($entry['parse_flags'] ?? [] as $flag) {
                $parseFlags[$flag] = ($parseFlags[$flag] ?? 0) + 1;
            }

            foreach ($entry['hold_reason_categories'] ?? [] as $category) {
                $holdReasonCategories[$category] = ($holdReasonCategories[$category] ?? 0) + 1;
            }

            if (($entry['adjudicated'] ?? false) === true) {
                $adjudicatedSources++;
            }

            foreach ($entry['plans'] ?? [] as $plan) {
                $planDisposition = $plan['disposition'] ?? null;

                if (is_string($planDisposition)) {
                    $planDispositions[$planDisposition] = ($planDispositions[$planDisposition] ?? 0) + 1;
                }

                foreach ($plan['hold_reasons'] ?? [] as $reason) {
                    $planHoldReasons[$reason] = ($planHoldReasons[$reason] ?? 0) + 1;
                }
            }
        }

        ksort($methods);
        ksort($dispositions);
        ksort($parseFlags);
        ksort($holdReasonCategories);
        ksort($planHoldReasons);
        ksort($planDispositions);
        ksort($songMatchTypes);
        arsort($unmatchedSongTitles);
        $hygieneVerdicts += array_fill_keys(SongTitleHygieneVerdict::values(), 0);
        ksort($hygieneVerdicts);
        arsort($hygieneDefects);

        return [
            'date_accuracy' => [
                'all' => $dateAll,
                'full' => $dateFull,
                'partial' => $datePartial,
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
            /**
             * How many entries carried each parse-quality signal. None of these gates the import,
             * so this is where a population like `service_beyond_manifest` — an email carrying an
             * order for a service its entry does not name — becomes a number rather than a shrug.
             */
            'parse_flag_counts' => $parseFlags,
            /**
             * Two units, never added together: `hold_reason_category_counts` counts *sources* that
             * were held and is the figure comparable to the recorded 373/352 baseline, while
             * `held_plan_reason_counts` counts *plans*, including a held plan inside an entry that
             * imported another one.
             */
            'hold_reason_category_counts' => $holdReasonCategories,
            'held_plan_reason_counts' => $planHoldReasons,
            'plan_disposition_counts' => $planDispositions,
            /** Sources whose disagreement a third call resolved. These stay held; see HIR-D6. */
            'adjudicated_sources' => $adjudicatedSources,
            'song_link_hit_rate' => [
                'hits' => $songHits,
                'total' => $songTotal,
                'rate' => $this->rate($songHits, $songTotal),
                'by_type' => $songMatchTypes,
                'top_unmatched_titles' => array_slice($unmatchedSongTitles, 0, 25, preserve_keys: true),
            ],
            /**
             * Item 0(4). The unmatched population split by who can act on it, so the figure above
             * is never read as an extraction error rate. `defective` is the only bucket extraction
             * work reduces; `decorated` is a resolver-coverage gap on titles that are already
             * correct; `not_a_title` has no title to extract; `clean` is a catalogue gap.
             *
             * `recovered_by_normalisation` is the acceptance figure for the resolver fix rather
             * than a result in itself: these titles resolve today once decoration the resolver
             * does not strip is removed, and the number should fall to approximately zero once
             * `SongTitleResolver` strips it. Counted over unmatched titles only — this is a
             * diagnosis of the misses, not a second hit rate.
             */
            'title_hygiene' => [
                'by_verdict' => $hygieneVerdicts,
                'by_defect' => $hygieneDefects,
                'recovered_by_normalisation' => $hygieneRecovered,
                'unmatched_titles' => array_sum($hygieneVerdicts),
            ],
            /**
             * Measured over the plans whose entry asserts a human-verified item count, which is
             * the only Email-side item ground truth that exists. `checked` is expected to stay
             * small; a rate over one plan is reported honestly rather than extrapolated.
             */
            'item_count_reconciliation' => [
                'matched' => $itemCountsMatched,
                'checked' => $itemCountsChecked,
                'rate' => $this->rate($itemCountsMatched, $itemCountsChecked),
            ],
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
            if (($entry['content_scope'] ?? null) !== 'full') {
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
            if (($entry['content_scope'] ?? null) !== 'full') {
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
            // Accuracy per band is only measurable where the manifest asserts a complete order;
            // a partial's silence about an item is not the parser getting it wrong (§8.4).
            if (($entry['content_scope'] ?? null) !== 'full') {
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
     * Runs each extracted song title through the same resolver the live linker uses, so the
     * eval measures the real cascade. A null resolver (dry-run) reports totals but no rate.
     *
     * Every title that fails to resolve is then classified by {@see SongTitleHygiene} (item 0(4)).
     * Without that split, `unmatched_titles` is a single bucket holding four populations with four
     * different owners, and the count reads as an extraction error rate when most of it is not one.
     * `hygiene_recovered` re-probes the resolver with the normalised title and is the figure that
     * sizes a resolver fix: it counts titles that are already correct and would resolve today if
     * the resolver's own cleaning reached as far as the hygiene normaliser's.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{hits:int,total:int,rate:?float,by_type:array<string,int>,unmatched_titles:list<string>,hygiene:array<string,int>,hygiene_defects:array<string,int>,hygiene_recovered:int}
     */
    private function songLinkMetrics(array $items, ?SongTitleResolver $songTitleResolver): array
    {
        $songs = array_values(array_filter($items, fn (array $item): bool => ($item['type'] ?? null) === 'songs'));

        $hits = 0;
        $byType = [];
        $unmatchedTitles = [];
        $hygiene = [];
        $hygieneDefects = [];
        $recovered = 0;

        if ($songTitleResolver !== null) {
            foreach ($songs as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $match = $title === '' ? null : $songTitleResolver->resolve($title);

                if ($match !== null) {
                    $hits++;
                    $byType[$match->matchType] = ($byType[$match->matchType] ?? 0) + 1;

                    continue;
                }

                $unmatchedTitles[] = $title === '' ? '(blank title)' : $title;

                $report = $this->titleHygiene->inspect($title);
                $hygiene[$report->verdict->value] = ($hygiene[$report->verdict->value] ?? 0) + 1;

                foreach ($report->defectValues() as $defect) {
                    $hygieneDefects[$defect] = ($hygieneDefects[$defect] ?? 0) + 1;
                }

                if ($report->isNormalised() && $songTitleResolver->resolve($report->normalised) !== null) {
                    $recovered++;
                }
            }
        }

        ksort($byType);
        ksort($hygiene);
        ksort($hygieneDefects);

        return [
            'hits' => $hits,
            'total' => count($songs),
            'rate' => $songTitleResolver === null ? null : $this->rate($hits, count($songs)),
            'by_type' => $byType,
            'unmatched_titles' => array_slice($unmatchedTitles, 0, 20),
            /** Unmatched titles by {@see SongTitleHygieneVerdict}: who can act on each one. */
            'hygiene' => $hygiene,
            /** Overlapping defect families across the same unmatched titles. */
            'hygiene_defects' => $hygieneDefects,
            'hygiene_recovered' => $recovered,
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator / $denominator, 4);
    }
}
