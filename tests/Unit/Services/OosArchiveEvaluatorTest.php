<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
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
        $this->assertSame(1.0, $result['plans'][0]['ordered_item_quality']);
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
        $this->assertSame(1.0, $result['plans'][1]['ordered_item_quality']);

        $aggregate = (new OosArchiveEvaluator)->aggregate([$result]);
        $this->assertSame(1.0, $aggregate['service_metrics']['evening']['recall']);
        $this->assertSame(['correct' => 2, 'total' => 2, 'rate' => 1.0], $aggregate['auto_import_precision']);
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
            ['service_not_in_ground_truth'],
        );
        $unverified = $evaluator->evaluate(
            $this->entry(index: 3, labelQuality: 'unverified', services: []),
            $this->parseResult(),
            'skipped',
            ['unverified_service_ground_truth'],
        );

        $aggregate = $evaluator->aggregate([$correct, $missedEvening, $unverified]);

        $this->assertSame(['correct' => 2, 'total' => 3, 'rate' => 0.6667], $aggregate['date_accuracy']['all']);
        $this->assertSame(0.5, $aggregate['service_metrics']['morning']['precision']);
        $this->assertSame(1.0, $aggregate['service_metrics']['morning']['recall']);
        $this->assertSame(0.0, $aggregate['service_metrics']['evening']['recall']);
        $this->assertSame(['correct' => 1, 'total' => 1, 'rate' => 1.0], $aggregate['auto_import_precision']);
        $this->assertSame(1, $aggregate['dispositions']['eligible']);
        $this->assertSame(2, $aggregate['dispositions']['skipped']);
        $this->assertArrayHasKey('0.90-1.00', $aggregate['confidence_calibration']);
    }

    /**
     * @param  list<string>  $services
     */
    private function entry(
        int $index = 1,
        string $labelQuality = 'full',
        array $services = ['morning', 'evening'],
    ): OosArchiveEntry {
        return new OosArchiveEntry(
            index: $index,
            heading: 'Sunday 12 July 2026',
            subject: 'Details for Sunday 12 July 2026',
            bodyPlain: 'Morning and evening',
            headingDate: '2026-07-12',
            correctedDate: null,
            groundTruthDate: '2026-07-12',
            labelQuality: $labelQuality,
            servicesPresent: $services,
            itemLineCounts: ['morning' => 2, 'evening' => 1],
            itemLines: [
                'morning' => ['Amazing Grace', 'Prayer'],
                'evening' => ['Welcome'],
            ],
            flags: [],
            syntheticMessageId: "<oos-archive-{$index}@crockenhill.local>",
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
