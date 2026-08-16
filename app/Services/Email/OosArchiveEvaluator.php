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
            $planRecords[] = $this->planRecord($entry, $plan, $gateReasons, $eligiblePlanKeys, $consensus, $songTitleResolver);
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
             * Which item labels the two attempts swapped. `item_type_or_order` was the only
             * category on 48 of the 102 disagreements in the 2026-08-16 rehearsal — identical
             * titles and source lines, a different label — and the category alone cannot say
             * whether the argument was consequential.
             */
            'attempt_disagreement_item_type_changes' => $this->attemptItemTypeChanges($parseResult),
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
        ?SongTitleResolver $songTitleResolver = null,
    ): array {
        $service = $plan->service?->value;
        $dateMatches = $plan->date === $entry->groundTruthDate;
        $serviceMatches = $service !== null && in_array($service, $entry->servicesPresent, true);
        $expectedItems = $service === null ? null : ($entry->itemLineCounts[$service] ?? null);
        $songItems = count(array_filter($plan->items, static fn (array $item): bool => $item['type'] === 'songs'));
        $songItemsResolved = $songTitleResolver === null ? null : $this->resolvedSongItems($plan->items, $songTitleResolver);

        return [
            'plan_key' => $plan->key(),
            'service' => $service,
            'date' => $plan->date,
            'item_count' => count($plan->items),
            /**
             * IC3 item 2's measurement comes before its policy change. Storage types (`songs`,
             * `bibles`, `custom`) cannot answer whether uncertainty affects a downstream
             * service role, so report the parser's semantic section type instead. Unknown is
             * retained as unknown rather than silently being treated as filler.
             */
            'semantic_item_type_counts' => $this->semanticItemTypeCounts($plan->items),
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
            /**
             * The line each bookkeeping hold concerns, and the items either side of it. Without
             * this the census can only attribute a hold to every item of the plan, which reports
             * the corpus item mix rather than anything about the hold.
             */
            'structural_findings' => $this->arrayRows($plan->sourceProvenance['structural_findings'] ?? null),
            /**
             * Was `exact_correct`, renamed in item 0(3). It is the conjunction of the entry
             * asserting a full order, the date agreeing, the service slot agreeing and the plan
             * being non-empty — it never opens an item. It is a sound measure of *identity*
             * resolution and an unsound one for extraction quality, and the old name invited
             * every accuracy claim in the follow-up report to be read as the latter. Content
             * lives in the two fields below and, properly, in the item-level ground truth.
             */
            'identity_correct' => $entry->assertsFullOrder() && $dateMatches && $serviceMatches && $plan->items !== [],
            /**
             * The item-level signal available while a run is in flight: how many of this plan's
             * song items name a song the catalogue holds. A resolution is evidence the title was
             * extracted well enough to match a real song, which is content evidence that
             * `identity_correct` cannot give. `resolved` is null on a dry run with no resolver.
             *
             * A miss is *not* automatically an extraction failure — `song_link.hygiene` on the
             * entry says which of the four populations it belongs to, and on the measured corpus
             * only about a quarter of misses were damaged extractions.
             */
            'song_items' => $songItems,
            'song_items_resolved' => $songItemsResolved,
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
     * @return array<string, int>
     */
    private function attemptItemTypeChanges(?OosEmailParseResult $parseResult): array
    {
        $recorded = $parseResult?->extractionAttempts[1]['disagreement_item_type_changes'] ?? null;

        if (! is_array($recorded)) {
            return [];
        }

        return array_map(intval(...), array_filter($recorded, is_numeric(...)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
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
        $songItemsTotal = 0;
        $songItemsResolved = 0;
        $plansWithSongItems = 0;
        $plansWithEverySongResolved = 0;
        $parseFlags = [];
        $holdReasonCategories = [];
        $planHoldReasons = [];
        $heldPlanSemanticItemTypesByReason = [];
        $semanticItemTypes = [];
        $bookkeepingFindings = [];
        $bookkeepingAdjacentItemTypes = [];
        $attemptItemTypeChanges = [];
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
                if (($plan['song_items_resolved'] ?? null) !== null) {
                    $songItemsTotal += (int) $plan['song_items'];
                    $songItemsResolved += (int) $plan['song_items_resolved'];
                    $plansWithSongItems += ((int) $plan['song_items']) > 0 ? 1 : 0;
                    $plansWithEverySongResolved += ((int) $plan['song_items']) > 0
                        && $plan['song_items'] === $plan['song_items_resolved'] ? 1 : 0;
                }

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

            foreach ($entry['attempt_disagreement_item_type_changes'] ?? [] as $change => $count) {
                $attemptItemTypeChanges[$change] = ($attemptItemTypeChanges[$change] ?? 0) + (int) $count;
            }

            foreach ($entry['plans'] ?? [] as $plan) {
                $planDisposition = $plan['disposition'] ?? null;

                if (is_string($planDisposition)) {
                    $planDispositions[$planDisposition] = ($planDispositions[$planDisposition] ?? 0) + 1;
                }

                // Every plan, held or not: this is the denominator the per-reason rows are read against.
                foreach ($plan['semantic_item_type_counts'] ?? [] as $type => $count) {
                    $semanticItemTypes[$type] = ($semanticItemTypes[$type] ?? 0) + (int) $count;
                }

                foreach ($this->arrayRows($plan['structural_findings'] ?? null) as $finding) {
                    $rule = $finding['rule'] ?? null;

                    if (is_string($rule)) {
                        $bookkeepingFindings[$rule] = ($bookkeepingFindings[$rule] ?? 0) + 1;
                    }

                    foreach ([$finding['preceding_item_type'] ?? null, $finding['following_item_type'] ?? null] as $adjacent) {
                        if (is_string($adjacent)) {
                            $bookkeepingAdjacentItemTypes[$adjacent] = ($bookkeepingAdjacentItemTypes[$adjacent] ?? 0) + 1;
                        }
                    }
                }

                foreach ($plan['hold_reasons'] ?? [] as $reason) {
                    $planHoldReasons[$reason] = ($planHoldReasons[$reason] ?? 0) + 1;

                    foreach ($plan['semantic_item_type_counts'] ?? [] as $type => $count) {
                        $heldPlanSemanticItemTypesByReason[$reason][$type] = ($heldPlanSemanticItemTypesByReason[$reason][$type] ?? 0) + (int) $count;
                    }
                }
            }
        }

        ksort($methods);
        ksort($dispositions);
        ksort($parseFlags);
        ksort($holdReasonCategories);
        ksort($planHoldReasons);
        ksort($heldPlanSemanticItemTypesByReason);

        foreach ($heldPlanSemanticItemTypesByReason as &$types) {
            ksort($types);
        }
        unset($types);
        ksort($semanticItemTypes);
        ksort($bookkeepingFindings);
        ksort($bookkeepingAdjacentItemTypes);
        ksort($attemptItemTypeChanges);
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
            /**
             * Both of these score `identity_correct` — date, service slot and non-emptiness, never
             * an item. Item 0(3) renamed the underlying field but deliberately left these two keys
             * and `confidence_calibration`'s band-map shape alone: FR-D4's precision floor and the
             * recorded v11/v12 calibration tables are quoted against them, and reshaping either
             * would silently break the comparison the floor depends on. `auto_import_precision`
             * gains only an additive `measure` marker; the calibration bands stay a bare band map
             * so iterating them still yields bands and nothing else. `content_accuracy` below is
             * the item-level companion these two cannot be.
             */
            'auto_import_precision' => ['measure' => 'identity_correct'] + $this->autoImportPrecision($entries),
            'confidence_calibration' => $this->confidenceCalibration($entries),
            /**
             * Item 0(3)'s content-level measure, fed by the seeded song catalogue (item 0(1)).
             * Where `identity_correct` never opens an item, this opens every song item: a title
             * that resolves to a catalogued song is evidence the *content* was extracted well
             * enough to name a real song.
             *
             * Read it with two caveats it would otherwise be over-read against. A miss is not
             * automatically an extraction failure — `title_hygiene` splits the misses into the
             * four owners, and on the measured corpus only about a quarter were damaged
             * extractions. And this is a lower bound on content quality generally: it can only
             * see song items, which are the one class the catalogue can adjudicate. The
             * authoritative content measure across all item classes is the corroborated
             * item-level ground truth (item 0(2)), which this run cannot reach.
             *
             * Not restricted to full-content sources: a partial source's song titles are real
             * titles, and a partial's silence about other items does not impeach them.
             */
            'content_accuracy' => [
                'measure' => 'song_title_resolution',
                'song_items' => $songItemsTotal,
                'song_items_resolved' => $songItemsResolved,
                'rate' => $this->rate($songItemsResolved, $songItemsTotal),
                'plans_with_song_items' => $plansWithSongItems,
                'plans_with_every_song_resolved' => $plansWithEverySongResolved,
                'plan_rate' => $this->rate($plansWithEverySongResolved, $plansWithSongItems),
            ],
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
            /**
             * IC3 item 2's pre-policy census. A plan contributes once to each reason it carries,
             * so overlapping reasons intentionally overlap here too. This identifies whether
             * bookkeeping and disagreement holds actually concern a downstream item type before
             * review triage is changed; it does not alter disposition or import eligibility.
             */
            'held_plan_semantic_item_types_by_reason' => $heldPlanSemanticItemTypesByReason,
            /**
             * The denominator for the row above: the item mix of every plan the run produced,
             * held or not.
             */
            'semantic_item_type_counts' => $semanticItemTypes,
            /**
             * The same census expressed against that denominator. A hold reason whose item mix
             * matches the corpus tells you nothing about which items it concerns — and every
             * reason in the 2026-08-16 rehearsal was within three points of the base rate, which
             * the raw counts alone made look like a finding about songs.
             */
            'held_plan_semantic_item_type_lift_by_reason' => $this->semanticItemTypeLift(
                $heldPlanSemanticItemTypesByReason,
                $semanticItemTypes,
            ),
            /**
             * What the bookkeeping holds are actually about: which line-accounting rule fired,
             * and the item types the offending line sits between.
             */
            'bookkeeping_finding_counts' => $bookkeepingFindings,
            'bookkeeping_finding_adjacent_item_types' => $bookkeepingAdjacentItemTypes,
            /** Which item labels the two attempts swapped, summed over the corpus. */
            'attempt_disagreement_item_type_changes' => $attemptItemTypeChanges,
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
     * Each hold reason's item mix as a share, next to the share the same type holds across the
     * whole corpus. `lift` is the difference: zero means the reason's items look exactly like an
     * ordinary order of service, and only a lift away from zero says a hold concentrates on a type.
     *
     * @param  array<string, array<string, int>>  $byReason
     * @param  array<string, int>  $baseCounts
     * @return array<string, array{items:int,types:array<string, array{items:int,share:float,base_share:float,lift:float}>}>
     */
    private function semanticItemTypeLift(array $byReason, array $baseCounts): array
    {
        $baseTotal = array_sum($baseCounts);
        $lift = [];

        foreach ($byReason as $reason => $counts) {
            $total = array_sum($counts);

            if ($total === 0) {
                continue;
            }

            $types = [];

            foreach ($counts as $type => $count) {
                $share = round($count / $total, 3);
                $baseShare = $baseTotal === 0 ? 0.0 : round(($baseCounts[$type] ?? 0) / $baseTotal, 3);
                $types[$type] = [
                    'items' => $count,
                    'share' => $share,
                    'base_share' => $baseShare,
                    'lift' => round($share - $baseShare, 3),
                ];
            }

            $lift[$reason] = ['items' => $total, 'types' => $types];
        }

        return $lift;
    }

    /**
     * @param  array<int, array{position:int,type:string,section_type?:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @return array<string, int>
     */
    private function semanticItemTypeCounts(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $metadata = $item['metadata'] ?? null;
            $type = $item['section_type'] ?? null;

            if (! is_string($type) || $type === '') {
                $type = is_array($metadata) ? ($metadata['section_type'] ?? null) : null;
            }

            if (! is_string($type) || $type === '') {
                $type = 'unknown';
            }

            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
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

        $correct = count(array_filter($plans, fn (array $plan): bool => (bool) ($plan['identity_correct'] ?? false)));

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
                        $band['correct'] += ($plan['identity_correct'] ?? false) ? 1 : 0;

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

    /**
     * How many of a plan's song items resolve to a catalogued song, through the same cascade the
     * live linker uses.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function resolvedSongItems(array $items, SongTitleResolver $songTitleResolver): int
    {
        $resolved = 0;

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'songs') {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));

            if ($title !== '' && $songTitleResolver->resolve($title) !== null) {
                $resolved++;
            }
        }

        return $resolved;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator / $denominator, 4);
    }
}
