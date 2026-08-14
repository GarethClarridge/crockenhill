<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Song\SongTitleResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OosArchiveEvaluatorTest extends TestCase
{
    #[Test]
    public function it_reports_per_entry_date_service_item_confidence_and_song_metrics(): void
    {
        $entry = $this->entry();
        $parseResult = $this->parseResult();

        $result = (new OosArchiveEvaluator)->evaluate(
            $entry,
            $parseResult,
            disposition: 'eligible',
            gateReasons: [],
            songTitleResolver: $this->songTitleResolver(),
            eligiblePlanKeys: ['morning:2026-07-12'],
        );

        $this->assertTrue($result['date']['matches']);
        $this->assertSame('subject_textual', $result['date']['method']);
        $this->assertSame(['morning', 'evening'], $result['services']['expected']);
        $this->assertSame(['morning'], $result['services']['detected']);
        $this->assertTrue($result['plans'][0]['exact_correct']);
        $this->assertTrue($result['plans'][0]['gate_eligible']);
        $this->assertSame(2, $result['plans'][0]['expected_item_count']);
        $this->assertTrue($result['plans'][0]['item_count_matches']);
        $this->assertSame(
            ['hits' => 1, 'total' => 1, 'rate' => 1.0, 'by_type' => ['exact' => 1], 'unmatched_titles' => []],
            $result['song_link'],
        );
        $this->assertTrue($result['gate_eligible']);
    }

    #[Test]
    public function it_reports_song_match_types_and_unmatched_titles_through_the_real_cascade(): void
    {
        $evaluator = new OosArchiveEvaluator;

        $result = $evaluator->evaluate(
            $this->entry(),
            $this->parseResult(items: [
                ['position' => 1, 'type' => 'songs', 'title' => "NIP 'Amazing Grace'", 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                ['position' => 2, 'type' => 'songs', 'title' => 'A Chorus Nobody Catalogued', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ]),
            disposition: 'eligible',
            gateReasons: [],
            songTitleResolver: $this->songTitleResolver(),
        );

        $this->assertSame(1, $result['song_link']['hits']);
        $this->assertSame(2, $result['song_link']['total']);
        $this->assertSame(['stripped_number' => 1], $result['song_link']['by_type']);
        $this->assertSame(['A Chorus Nobody Catalogued'], $result['song_link']['unmatched_titles']);

        $aggregate = $evaluator->aggregate([$result, $result]);

        $this->assertSame(['stripped_number' => 2], $aggregate['song_link_hit_rate']['by_type']);
        $this->assertSame(['A Chorus Nobody Catalogued' => 2], $aggregate['song_link_hit_rate']['top_unmatched_titles']);
    }

    #[Test]
    public function it_reports_totals_but_no_rate_without_a_resolver(): void
    {
        $result = (new OosArchiveEvaluator)->evaluate($this->entry(), $this->parseResult(), 'dry_run');

        $this->assertSame(
            ['hits' => 0, 'total' => 1, 'rate' => null, 'by_type' => [], 'unmatched_titles' => []],
            $result['song_link'],
        );
    }

    #[Test]
    public function it_reports_a_complete_reason_census_for_a_held_source(): void
    {
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.82,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::InvalidExtraction,
            validationReasons: ['Items claim source lines out of order.', 'Unclassified sign-off line.'],
            contentValidationReasons: ['Items claim source lines out of order.'],
            holdReasons: [
                OosEmailPlanHoldReason::ContentInvalid,
                OosEmailPlanHoldReason::Bookkeeping,
                OosEmailPlanHoldReason::AttemptDisagreement,
                OosEmailPlanHoldReason::LowConfidence,
            ],
            contentScope: OosEmailContentScope::Full,
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.82,
            needsReview: true,
            shouldImport: false,
            importMetadata: ['date_extraction' => ['method' => 'llm']],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::InvalidExtraction,
            validationReasons: $plan->validationReasons,
            extractionAttempts: [
                ['attempt' => 1, 'plans' => [['service' => 'morning', 'date' => '2026-07-12', 'content_scope' => 'full', 'item_count' => 2]]],
                [
                    'attempt' => 2,
                    'plans' => [['service' => 'evening', 'date' => '2026-07-12', 'content_scope' => 'full', 'item_count' => 1]],
                    'disagreement_categories' => ['service', 'item_count'],
                ],
            ],
            consensus: false,
        );

        $result = (new OosArchiveEvaluator)->evaluate(
            $this->entry(),
            $parseResult,
            disposition: 'held_for_review',
            eligiblePlanKeys: ['morning:2026-07-12'],
            importedPlanKeys: [],
        );

        $this->assertSame('invalid_extraction', $result['plans'][0]['disposition']);
        $this->assertSame('full', $result['plans'][0]['content_scope']);
        $this->assertFalse($result['plans'][0]['consensus']);
        $this->assertSame($plan->validationReasons, $result['plans'][0]['validation_reasons']);
        $this->assertSame($plan->contentValidationReasons, $result['plans'][0]['content_validation_reasons']);
        $this->assertSame(2, $result['attempt_count']);
        $this->assertSame(['service', 'item_count'], $result['attempt_disagreement_categories']);
        $this->assertSame(['morning:2026-07-12'], $result['corroborated_plan_keys']);
        $this->assertSame([], $result['imported_plan_keys']);
        $this->assertSame(['morning:2026-07-12'], $result['held_plan_keys']);
        $this->assertTrue($result['held']);
        $this->assertSame(
            ['content_invalid', 'bookkeeping', 'attempt_disagreement', 'low_confidence'],
            $result['hold_reason_categories'],
        );

        $aggregate = (new OosArchiveEvaluator)->aggregate([$result]);
        $this->assertSame(
            ['attempt_disagreement' => 1, 'bookkeeping' => 1, 'content_invalid' => 1, 'low_confidence' => 1],
            $aggregate['hold_reason_category_counts'],
        );
        $this->assertSame(['invalid_extraction' => 1], $aggregate['plan_disposition_counts']);
        $this->assertSame(0, $aggregate['adjudicated_sources']);
    }

    /**
     * A corrective call that threw is not a parser disagreement. Rebuilding the categories from
     * the stored attempts read the error attempt's missing `plans` key as a disagreement across
     * every compared field, inflating the census on exactly the sources that failed hardest.
     */
    #[Test]
    public function it_does_not_report_a_failed_corrective_call_as_an_attempt_disagreement(): void
    {
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.82,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.82,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
            extractionAttempts: [
                ['attempt' => 1, 'selected' => true, 'plans' => [['service' => 'morning', 'date' => '2026-07-12', 'content_scope' => 'full', 'item_count' => 1]]],
                // Exactly what the parser records when correct() throws.
                ['attempt' => 2, 'selected' => false, 'error' => 'API timeout'],
            ],
            consensus: false,
        );

        $result = (new OosArchiveEvaluator)->evaluate($this->entry(), $parseResult, 'held_for_review');

        $this->assertSame([], $result['attempt_disagreement_categories']);
        $this->assertSame(['low_confidence'], $result['hold_reason_categories']);
    }

    /**
     * A held plan inside an entry that imported another plan is reported at plan level but must
     * not be counted as a held *source*, or the census stops being comparable to the recorded
     * held-source baseline.
     */
    #[Test]
    public function it_counts_a_held_plan_inside_an_imported_entry_at_plan_level_only(): void
    {
        $imported = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.95,
            needsReview: false,
            shouldImport: true,
        );
        $held = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.60,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $imported->items,
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
            servicePlans: [$imported, $held],
        );

        $result = (new OosArchiveEvaluator)->evaluate(
            $this->entry(),
            $parseResult,
            disposition: 'created',
            eligiblePlanKeys: ['morning:2026-07-12'],
            importedPlanKeys: ['morning:2026-07-12'],
        );

        $this->assertFalse($result['held']);
        $this->assertSame(['morning:2026-07-12'], $result['imported_plan_keys']);
        $this->assertSame(['evening:2026-07-12'], $result['held_plan_keys']);
        $this->assertSame([], $result['hold_reason_categories']);
        $this->assertSame(['low_confidence'], $result['plans'][1]['hold_reasons']);

        $aggregate = (new OosArchiveEvaluator)->aggregate([$result]);
        $this->assertSame([], $aggregate['hold_reason_category_counts']);
        $this->assertSame(['low_confidence' => 1], $aggregate['held_plan_reason_counts']);
    }

    /**
     * An entry that failed part-way really did import the plans the importer reported, so the
     * report must take them from the import result rather than re-deriving them from dispositions.
     */
    #[Test]
    public function it_takes_the_imported_plan_keys_from_the_importer_on_a_partial_failure(): void
    {
        $created = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.95,
            needsReview: false,
            shouldImport: true,
        );
        $failed = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.95,
            needsReview: false,
            shouldImport: true,
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $created->items,
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
            servicePlans: [$created, $failed],
        );

        $result = (new OosArchiveEvaluator)->evaluate(
            $this->entry(),
            $parseResult,
            disposition: 'import_failed',
            error: 'evening:2026-07-12: import failed',
            eligiblePlanKeys: ['morning:2026-07-12', 'evening:2026-07-12'],
            importedPlanKeys: ['morning:2026-07-12'],
        );

        $this->assertSame(['morning:2026-07-12'], $result['imported_plan_keys']);
        $this->assertSame(['evening:2026-07-12'], $result['held_plan_keys']);
    }

    /** A dry run imported nothing because nothing was attempted; that is not "imported nothing". */
    #[Test]
    public function it_distinguishes_no_import_attempt_from_an_import_that_took_nothing(): void
    {
        $result = (new OosArchiveEvaluator)->evaluate($this->entry(), null, 'dry_run', ['dry_run']);

        $this->assertNull($result['imported_plan_keys']);
        $this->assertNull($result['held_plan_keys']);
    }

    #[Test]
    public function it_distinguishes_bookkeeping_holds_from_source_gates(): void
    {
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            validationReasons: ['Unclassified sign-off line.'],
            holdReasons: [OosEmailPlanHoldReason::Bookkeeping],
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
            validationReasons: $plan->validationReasons,
        );
        $evaluator = new OosArchiveEvaluator;

        $bookkeeping = $evaluator->evaluate($this->entry(), $parseResult, 'held_for_review');
        $sourceGated = $evaluator->evaluate($this->entry(), $parseResult, 'held_for_review', ['curated_service_not_parsed']);

        $this->assertSame(['bookkeeping'], $bookkeeping['hold_reason_categories']);
        $this->assertSame(['source_gate', 'bookkeeping'], $sourceGated['hold_reason_categories']);
    }

    #[Test]
    public function it_does_not_call_consensus_backed_auto_importable_confidence_a_hold_reason(): void
    {
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: [['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.82,
            needsReview: false,
            shouldImport: true,
        );
        $parseResult = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.82,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
            servicePlans: [$plan],
            extractionAttempts: [
                ['attempt' => 1, 'plans' => [['service' => 'morning', 'date' => '2026-07-12', 'content_scope' => 'full', 'item_count' => 1]]],
                ['attempt' => 2, 'plans' => [['service' => 'morning', 'date' => '2026-07-12', 'content_scope' => 'full', 'item_count' => 1]]],
            ],
            consensus: true,
        );

        $result = (new OosArchiveEvaluator)->evaluate(
            $this->entry(),
            $parseResult,
            disposition: 'created',
            eligiblePlanKeys: ['morning:2026-07-12'],
            importedPlanKeys: ['morning:2026-07-12'],
        );

        $this->assertSame([], $result['hold_reason_categories']);
        $this->assertSame(['morning:2026-07-12'], $result['imported_plan_keys']);
        $this->assertSame([], $result['held_plan_keys']);
    }

    #[Test]
    public function it_evaluates_every_service_plan_in_a_multi_service_parse(): void
    {
        $parseResult = $this->multiPlanParseResult();

        $result = (new OosArchiveEvaluator)->evaluate(
            $this->entry(),
            $parseResult,
            disposition: 'eligible',
            gateReasons: [],
            eligiblePlanKeys: ['morning:2026-07-12', 'evening:2026-07-12'],
        );

        $this->assertSame(['morning', 'evening'], $result['services']['detected']);
        $this->assertSame(['morning' => 2, 'evening' => 1], $result['item_counts']['detected']);
        $this->assertCount(2, $result['plans']);
        $this->assertTrue($result['plans'][1]['exact_correct']);
        $this->assertTrue($result['plans'][1]['gate_eligible']);
        $this->assertSame(1, $result['plans'][1]['expected_item_count']);
        $this->assertTrue($result['plans'][1]['item_count_matches']);

        $aggregate = (new OosArchiveEvaluator)->aggregate([$result]);
        $this->assertSame(1.0, $aggregate['service_metrics']['evening']['recall']);
        $this->assertSame(['correct' => 2, 'total' => 2, 'rate' => 1.0], $aggregate['auto_import_precision']);
        $this->assertSame(['matched' => 2, 'checked' => 2, 'rate' => 1.0], $aggregate['item_count_reconciliation']);
    }

    /**
     * §7.5 keeps heuristic item counts out of the manifest, so almost every entry asserts none.
     * An unasserted count must report as "not measured", never as a score: an LCS against an
     * empty expected list scores 0.0, which reads as "the parse got every item wrong" when the
     * truth is that nobody said what the right answer was.
     */
    #[Test]
    public function it_declines_to_score_item_counts_no_one_asserted(): void
    {
        $evaluator = new OosArchiveEvaluator;

        $result = $evaluator->evaluate(
            $this->entry(itemLineCounts: []),
            $this->parseResult(),
            'eligible',
            eligiblePlanKeys: ['morning:2026-07-12'],
        );

        $this->assertNull($result['plans'][0]['expected_item_count']);
        $this->assertNull($result['plans'][0]['item_count_matches']);
        $this->assertSame(2, $result['plans'][0]['item_count'], 'the detected count is still reported');
        $this->assertTrue($result['plans'][0]['exact_correct'], 'a missing count is not a failure');

        $this->assertSame(
            ['matched' => 0, 'checked' => 0, 'rate' => null],
            $evaluator->aggregate([$result])['item_count_reconciliation'],
        );
    }

    #[Test]
    public function it_reports_an_item_count_that_contradicts_the_asserted_one(): void
    {
        $evaluator = new OosArchiveEvaluator;

        $result = $evaluator->evaluate(
            $this->entry(itemLineCounts: ['morning' => 13]),
            $this->parseResult(),
            'eligible',
        );

        $this->assertSame(13, $result['plans'][0]['expected_item_count']);
        $this->assertFalse($result['plans'][0]['item_count_matches']);
        $this->assertSame(
            ['matched' => 0, 'checked' => 1, 'rate' => 0.0],
            $evaluator->aggregate([$result])['item_count_reconciliation'],
        );
    }

    #[Test]
    public function it_aggregates_metrics_only_over_the_appropriate_cohorts(): void
    {
        $evaluator = new OosArchiveEvaluator;
        $correct = $evaluator->evaluate(
            $this->entry(),
            $this->parseResult(),
            'eligible',
            eligiblePlanKeys: ['morning:2026-07-12'],
        );
        $missedEvening = $evaluator->evaluate(
            $this->entry(index: 2, services: ['evening']),
            $this->parseResult(service: SermonService::Morning, date: '2026-07-19', confidence: 0.80),
            'skipped',
            ['service_not_curated'],
        );
        $partial = $evaluator->evaluate(
            $this->entry(index: 3, contentScope: 'partial', services: []),
            $this->parseResult(),
            'skipped',
            ['partial_source_scope'],
        );

        $aggregate = $evaluator->aggregate([$correct, $missedEvening, $partial]);

        $this->assertSame(['correct' => 2, 'total' => 3, 'rate' => 0.6667], $aggregate['date_accuracy']['all']);
        $this->assertSame(0.5, $aggregate['service_metrics']['morning']['precision']);
        $this->assertSame(1.0, $aggregate['service_metrics']['morning']['recall']);
        $this->assertSame(0.0, $aggregate['service_metrics']['evening']['recall']);
        $this->assertSame(['correct' => 1, 'total' => 1, 'rate' => 1.0], $aggregate['auto_import_precision']);
        $this->assertSame(1, $aggregate['dispositions']['eligible']);
        $this->assertSame(2, $aggregate['dispositions']['skipped']);
        $this->assertArrayHasKey('0.90-1.00', $aggregate['confidence_calibration']);
        $this->assertSame(
            ['correct' => 1, 'total' => 1, 'rate' => 1.0],
            $aggregate['date_accuracy']['partial'],
            'a partial order still has a curated date to be right or wrong about',
        );
    }

    /**
     * @param  list<string>  $services
     * @param  array<string, int>  $itemLineCounts  only what a person asserted, which for most of
     *                                              the real corpus is nothing at all
     */
    private function entry(
        int $index = 1,
        string $contentScope = 'full',
        array $services = ['morning', 'evening'],
        array $itemLineCounts = ['morning' => 2, 'evening' => 1],
    ): OosArchiveEntry {
        return new OosArchiveEntry(
            index: $index,
            itemKey: "2026-07-12-{$index}",
            subject: 'Details for Sunday 12 July 2026',
            bodyPlain: 'Morning and evening',
            groundTruthDate: '2026-07-12',
            contentScope: $contentScope,
            servicesPresent: $services,
            itemLineCounts: $itemLineCounts,
            curation: [
                'date_decision' => 'explicit',
                'date_decision_reason' => null,
                'parse_decision' => 'strict',
                'content_scope' => $contentScope,
                'partial_scope_reason' => $contentScope === 'partial' ? 'hymn list only' : null,
                'payload' => 'verbatim',
                'service_label' => null,
                'title_override' => null,
                'supersedes' => null,
                'expected_item_count' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => 'oos-curation-draft-v1',
            ],
            syntheticMessageId: "<oos-2026-07-12-{$index}@crockenhill.local>",
            sourceKey: "<oos-2026-07-12-{$index}@crockenhill.local>|morning:2026-07-12",
            supersedesSourceKey: null,
            inputHash: str_repeat('a', 64),
            syntheticReceivedAt: CarbonImmutable::parse('2026-07-10 09:00', 'Europe/London'),
        );
    }

    private function songTitleResolver(): SongTitleResolver
    {
        return SongTitleResolver::fromRows([
            ['id' => 1, 'canonical_key' => 'amazing grace', 'title' => 'Amazing Grace'],
        ], ['fuzzy_enabled' => false]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     */
    private function parseResult(
        SermonService $service = SermonService::Morning,
        string $date = '2026-07-12',
        float $confidence = 0.95,
        ?array $items = null,
    ): OosEmailParseResult {
        return new OosEmailParseResult(
            date: $date,
            service: $service,
            items: $items ?? [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: $confidence,
            needsReview: false,
            shouldImport: true,
            importMetadata: [
                'date_extraction' => ['method' => 'subject_textual'],
                'confidence_score' => $confidence,
            ],
        );
    }

    private function multiPlanParseResult(): OosEmailParseResult
    {
        $morningItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
        ];
        $eveningItems = [
            ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
        ];

        return new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Morning,
            items: $morningItems,
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: [
                'date_extraction' => ['method' => 'subject_textual'],
                'confidence_score' => 0.95,
            ],
            servicePlans: [
                new OosEmailServicePlan(SermonService::Morning, '2026-07-12', $morningItems, 0.95, false, true),
                new OosEmailServicePlan(SermonService::Evening, '2026-07-12', $eveningItems, 0.92, false, true),
            ],
        );
    }
}
