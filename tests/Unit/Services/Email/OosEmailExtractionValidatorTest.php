<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailExtractionValidationResult;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Enums\OosEmailStructuralFindingRule;
use App\Services\Email\OosEmailExtractionValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F65. The validator polices two different things: whether the extracted order is trustworthy,
 * and whether the model accounted for every source line. Only the first should be able to make a
 * plan unreachable by review, and provenance bookkeeping that cannot impeach the content — a line
 * cited as both evidence and an item, evidence shared between plans — should not fire at all.
 */
class OosEmailExtractionValidatorTest extends TestCase
{
    private OosEmailExtractionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new OosEmailExtractionValidator;
    }

    #[Test]
    public function a_line_cited_as_both_service_evidence_and_an_item_is_not_a_finding(): void
    {
        // The opening hymn doubles as the only marker of where the order starts — the content is
        // still extracted exactly once.
        $result = $this->validate(
            ['Morning Service', 'Hymn: Amazing Grace', 'Sermon'],
            evidenceLineIds: [2],
            items: [[2], [3]],
            ignoredLineIds: [1],
        );

        $this->assertSame([], $result->allReasons());
    }

    #[Test]
    public function one_line_shared_as_evidence_by_two_plans_is_not_a_finding(): void
    {
        $source = OosEmailSourceDocument::fromBody("Sunday 12 July\nWelcome\nHymn\nEvening: 6pm\nPrayer");
        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.9,
            services: [
                $this->plan('morning', [1], [[2], [3]]),
                $this->plan('evening', [1, 4], [[5]]),
            ],
            serviceCount: 2,
            ignoredLines: [],
            provenanceComplete: true,
        );

        $this->assertSame([], $this->validator->validate($source, $extraction)->allReasons());
    }

    /**
     * The deterministic half of the named-person hymn-intro rules: a plan carrying nothing but
     * songs, bounded by a prose sentence rather than a heading, has to survive validation or the
     * prompt asking the model to produce one would just move the loss downstream.
     */
    #[Test]
    public function a_song_only_plan_bounded_by_a_prose_intro_sentence_is_not_a_finding(): void
    {
        $result = $this->validate(
            [
                'Jon would like the following hymns tomorrow morning:',
                'Be Thou My Vision',
                'In Christ Alone',
                'How Great Thou Art',
            ],
            evidenceLineIds: [1],
            items: [[2], [3], [4]],
        );

        $this->assertSame([], $result->allReasons());
    }

    /**
     * Characterisation, not endorsement. EVENING_SERVICE_PATTERN only asks whether an evidence
     * line contains an evening/PM token, so ordinary prose mentioning the evening clears it just
     * as a standalone "Evening: 6pm" heading would. Nothing here can tell the two apart, which is
     * why refusing a prose-only evening boundary lives in the extractor's system prompt and why
     * weakening that rule has no deterministic net beneath it.
     */
    #[Test]
    public function the_evening_boundary_check_cannot_tell_prose_from_a_heading(): void
    {
        $source = OosEmailSourceDocument::fromBody("And in the evening Jon is leading\nHymn 101");
        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.9,
            services: [$this->plan('evening', [1], [[2]])],
            serviceCount: 1,
            ignoredLines: [],
            provenanceComplete: true,
        );

        $this->assertSame([], $this->validator->validate($source, $extraction)->allReasons());
    }

    #[Test]
    public function two_items_claiming_the_same_line_is_still_a_content_finding(): void
    {
        $result = $this->validate(
            ['Morning Service', 'Hymn: Amazing Grace', 'Sermon'],
            evidenceLineIds: [1],
            items: [[2], [2], [3]],
        );

        $this->assertNotSame([], $result->contentReasonsForPlan(0));
    }

    #[Test]
    public function an_ignored_line_after_the_last_item_is_not_a_finding(): void
    {
        // A trailing appendix — sermon outlines, another service's reading — is not a dropped item.
        $result = $this->validate(
            ['Morning Service', 'Welcome', 'Sermon', 'Reading: Ezra 3:1-6', '1) As one'],
            evidenceLineIds: [1],
            items: [[2], [3]],
            ignoredLineIds: [4, 5],
        );

        $this->assertSame([], $result->allReasons());
    }

    #[Test]
    public function an_ignored_line_between_two_items_remains_a_finding_but_not_a_content_one(): void
    {
        $result = $this->validate(
            ['Morning Service', 'Welcome', 'Reading: Ezra 3:1-6', 'Sermon'],
            evidenceLineIds: [1],
            items: [[2], [4]],
            ignoredLineIds: [3],
        );

        $this->assertNotSame([], $result->allReasons());
        $this->assertSame([], $result->contentReasonsForPlan(0));
    }

    #[Test]
    public function an_unaccounted_source_line_is_a_finding_but_not_a_content_one(): void
    {
        $result = $this->validate(
            ['Morning Service', 'Welcome', 'Sermon', 'Many thanks,'],
            evidenceLineIds: [1],
            items: [[2], [3]],
        );

        $this->assertNotSame([], $result->allReasons());
        $this->assertSame([], $result->contentReasonsForPlan(0));
    }

    /**
     * The unaccounted-line rule used to report document-wide, and `reasonsForPlan()` merges global
     * reasons into every plan — so one stray line in a two-service email held both orders. A line
     * inside one plan's item span is that plan's problem.
     */
    #[Test]
    public function an_unaccounted_line_inside_one_plans_span_is_only_that_plans_finding(): void
    {
        $result = $this->validateTwoPlans();

        $this->assertSame([], $result->globalReasons);
        $this->assertNotSame([], $result->reasonsForPlan(0));
        $this->assertSame([], $result->reasonsForPlan(1));
    }

    /**
     * A sign-off or an appendix sits after every order and belongs to neither, so it stays where
     * it was: document-wide.
     */
    #[Test]
    public function an_unaccounted_line_outside_every_plan_span_stays_document_wide(): void
    {
        $result = $this->validate(
            ['Morning Service', 'Welcome', 'Sermon', 'Many thanks,'],
            evidenceLineIds: [1],
            items: [[2], [3]],
        );

        $this->assertNotSame([], $result->globalReasons);
        $this->assertNull($result->structuralFindings[0]->planIndex);
    }

    #[Test]
    public function a_bookkeeping_finding_records_its_line_and_the_items_it_sits_between(): void
    {
        $source = OosEmailSourceDocument::fromBody(
            "Morning Service\nHymn: Amazing Grace\nReading: Ezra 3:1-6\nSermon",
        );
        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.9,
            services: [$this->typedPlan('morning', [1], [[2, 'song'], [4, 'sermon']])],
            serviceCount: 1,
            ignoredLines: [['line_id' => 3, 'reason' => 'context']],
            provenanceComplete: true,
        );

        $findings = $this->validator->validate($source, $extraction)->structuralFindings;

        $this->assertCount(1, $findings);
        $this->assertSame(OosEmailStructuralFindingRule::LineIgnoredInsideItemSpan, $findings[0]->rule);
        $this->assertSame(3, $findings[0]->lineId);
        $this->assertSame(0, $findings[0]->planIndex);
        $this->assertSame('song', $findings[0]->precedingItemType);
        $this->assertSame('sermon', $findings[0]->followingItemType);
    }

    #[Test]
    public function items_out_of_source_order_remain_a_content_finding(): void
    {
        $result = $this->validate(
            ['Morning Service', 'Welcome', 'Sermon'],
            evidenceLineIds: [1],
            items: [[3], [2]],
        );

        $this->assertNotSame([], $result->contentReasonsForPlan(0));
    }

    /**
     * @param  list<string>  $lines
     * @param  list<int>  $evidenceLineIds
     * @param  list<list<int>>  $items
     * @param  list<int>  $ignoredLineIds
     */
    private function validate(
        array $lines,
        array $evidenceLineIds,
        array $items,
        array $ignoredLineIds = [],
    ): OosEmailExtractionValidationResult {
        $source = OosEmailSourceDocument::fromBody(implode("\n", $lines));
        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.9,
            services: [$this->plan('morning', $evidenceLineIds, $items)],
            serviceCount: 1,
            ignoredLines: array_map(
                static fn (int $lineId): array => ['line_id' => $lineId, 'reason' => 'context'],
                $ignoredLineIds,
            ),
            provenanceComplete: true,
        );

        return $this->validator->validate($source, $extraction);
    }

    /** A morning and an evening order, with one unaccounted line inside the morning item span. */
    private function validateTwoPlans(): OosEmailExtractionValidationResult
    {
        $source = OosEmailSourceDocument::fromBody(
            "Morning Service\nWelcome\nMany thanks,\nSermon\nEvening: 6pm\nPrayer",
        );
        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.9,
            services: [
                $this->plan('morning', [1], [[2], [4]]),
                $this->plan('evening', [5], [[6]]),
            ],
            serviceCount: 2,
            ignoredLines: [],
            provenanceComplete: true,
        );

        return $this->validator->validate($source, $extraction);
    }

    /**
     * @param  list<int>  $evidenceLineIds
     * @param  list<array{0:int,1:string}>  $items
     * @return array<string, mixed>
     */
    private function typedPlan(string $service, array $evidenceLineIds, array $items): array
    {
        $plan = $this->plan($service, $evidenceLineIds, array_map(
            static fn (array $item): array => [$item[0]],
            $items,
        ));

        foreach ($items as $index => $item) {
            $plan['items'][$index]['type'] = $item[1];
        }

        return $plan;
    }

    /**
     * @param  list<int>  $evidenceLineIds
     * @param  list<list<int>>  $items
     * @return array<string, mixed>
     */
    private function plan(string $service, array $evidenceLineIds, array $items): array
    {
        return [
            'service' => $service,
            'date' => '2026-07-12',
            'content_scope' => 'full',
            'service_evidence_line_ids' => $evidenceLineIds,
            'items' => array_map(
                static fn (array $lineIds): array => [
                    'type' => 'other',
                    'title' => 'Item',
                    'source_line_ids' => $lineIds,
                    'continuation' => false,
                ],
                $items,
            ),
            'confidence' => 0.9,
        ];
    }
}
