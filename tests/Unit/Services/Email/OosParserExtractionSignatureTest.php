<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\OosParserExtractionSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OosParserExtractionSignatureTest extends TestCase
{
    /**
     * The defect this class was extracted to close. The between-arm comparison keyed plans and
     * sorted them; the within-arm stability check compared the model's emitted order. Two replicates
     * returning the same two services in a different order therefore counted as instability while
     * not counting as discordance — inflating the very figure that gates the comparison.
     */
    #[Test]
    public function it_ignores_the_order_the_model_emitted_service_plans_in(): void
    {
        $morning = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $evening = $this->plan('2023-01-01-evening', 'evening', [$this->item(1, 'Abide With Me')]);

        $this->assertSame(
            OosParserExtractionSignature::fromPlanList([$morning, $evening], 'source'),
            OosParserExtractionSignature::fromPlanList([$evening, $morning], 'source'),
        );
    }

    /**
     * The mirror of the rule above: position is part of what an order of service *is*, so the same
     * items in a different order is a real extraction difference and must survive normalisation.
     */
    #[Test]
    public function it_keeps_item_order_within_a_plan_significant(): void
    {
        $ascending = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace'), $this->item(2, 'Abide With Me')]);
        $descending = $this->plan('2023-01-01-morning', 'morning', [$this->item(2, 'Abide With Me'), $this->item(1, 'Amazing Grace')]);

        $this->assertNotSame(
            OosParserExtractionSignature::fromPlanList([$ascending], 'source'),
            OosParserExtractionSignature::fromPlanList([$descending], 'source'),
        );
    }

    /**
     * Keying by `plan_key` makes a duplicate key silently destructive: the second plan replaces the
     * first, and a source that extracted two services is scored as though it extracted one.
     */
    #[Test]
    public function it_rejects_two_service_plans_sharing_a_plan_key(): void
    {
        $plans = [
            $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]),
            $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Abide With Me')]),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("two service plans keyed '2023-01-01-morning'");

        OosParserExtractionSignature::index($plans, 'source email-001');
    }

    #[Test]
    public function it_rejects_a_service_plan_with_no_usable_plan_key(): void
    {
        $plan = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        unset($plan['plan_key']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a usable plan_key');

        OosParserExtractionSignature::index([$plan], 'source email-001');
    }

    /**
     * Every field the evaluation defines as the thing being measured has to move the signature,
     * or the stability check reports a stability the comparison does not have.
     *
     * @param  array<string, mixed>  $mutation
     */
    #[Test]
    #[DataProvider('scoredChanges')]
    public function it_detects_a_change_to_any_scored_field(array $mutation): void
    {
        $original = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $changed = array_replace_recursive($original, $mutation);

        $this->assertNotSame(
            OosParserExtractionSignature::fromPlanList([$original], 'source'),
            OosParserExtractionSignature::fromPlanList([$changed], 'source'),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function scoredChanges(): array
    {
        return [
            'service' => [['service' => 'evening']],
            'date' => [['date' => '2023-01-08']],
            'content scope' => [['content_scope' => 'partial']],
            'item title' => [['items' => [0 => ['title' => 'Abide With Me']]]],
            'item source title' => [['items' => [0 => ['source_title' => 'AMAZING GRACE']]]],
            'item type' => [['items' => [0 => ['type' => 'reading']]]],
            'item section type' => [['items' => [0 => ['section_type' => 'communion']]]],
            'item position' => [['items' => [0 => ['position' => 9]]]],
            'source line bindings' => [['source_provenance' => ['items' => [0 => ['line_id' => 99]]]]],
            'service evidence line ids' => [['source_provenance' => ['service_evidence_line_ids' => [7]]]],
        ];
    }

    /**
     * The exclusions are as load-bearing as the inclusions: `confidence` is a continuous float and
     * the validation reasons are model prose, so folding them in measured variance in the
     * explanation rather than in the extraction.
     *
     * @param  array<string, mixed>  $mutation
     */
    #[Test]
    #[DataProvider('unscoredChanges')]
    public function it_ignores_a_change_to_a_field_the_comparison_does_not_score(array $mutation): void
    {
        $original = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $changed = array_replace_recursive($original, $mutation);

        $this->assertSame(
            OosParserExtractionSignature::fromPlanList([$original], 'source'),
            OosParserExtractionSignature::fromPlanList([$changed], 'source'),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unscoredChanges(): array
    {
        return [
            'confidence' => [['confidence' => 0.42]],
            'needs review' => [['needs_review' => true]],
            'should import' => [['should_import' => false]],
            'disposition' => [['disposition' => 'hold']],
            'validation reasons' => [['validation_reasons' => ['a different explanation']]],
            'content validation reasons' => [['content_validation_reasons' => ['a different explanation']]],
            'hold reasons' => [['hold_reasons' => ['low_confidence']]],
        ];
    }

    #[Test]
    public function it_attributes_a_difference_to_the_field_group_that_moved(): void
    {
        $original = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace'), $this->item(2, 'Abide With Me')]);
        $retitled = array_replace_recursive($original, ['items' => [1 => ['title' => 'Be Thou My Vision']]]);

        $difference = OosParserExtractionSignature::fieldDifferences(
            OosParserExtractionSignature::fromPlanList([$original], 'source'),
            OosParserExtractionSignature::fromPlanList([$retitled], 'source'),
        );

        $this->assertFalse($difference['plan_keys_differ']);
        $this->assertSame(['titles'], $difference['groups_that_differ']);

        // Only the position that moved is reported, so a one-word change is not thirty lines of diff.
        $titles = $difference['plans']['2023-01-01-morning']['titles'];
        $this->assertSame(1, $titles['changed_position_count']);
        $this->assertSame(1, $titles['changed_positions'][0]['index']);
        $this->assertSame('Abide With Me', $titles['changed_positions'][0]['first']['title']);
        $this->assertSame('Be Thou My Vision', $titles['changed_positions'][0]['second']['title']);
    }

    #[Test]
    public function it_reports_a_plan_one_side_never_produced_as_a_plan_key_difference(): void
    {
        $morning = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $evening = $this->plan('2023-01-01-evening', 'evening', [$this->item(1, 'Abide With Me')]);

        $difference = OosParserExtractionSignature::fieldDifferences(
            OosParserExtractionSignature::fromPlanList([$morning, $evening], 'source'),
            OosParserExtractionSignature::fromPlanList([$morning], 'source'),
        );

        $this->assertTrue($difference['plan_keys_differ']);
        $this->assertSame(['2023-01-01-evening'], $difference['first_only_plan_keys']);
        $this->assertSame([], $difference['second_only_plan_keys']);
        $this->assertSame([], $difference['groups_that_differ']);
    }

    #[Test]
    public function it_separates_an_item_count_change_from_a_title_change(): void
    {
        $one = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $two = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace'), $this->item(2, 'Abide With Me')]);

        $difference = OosParserExtractionSignature::fieldDifferences(
            OosParserExtractionSignature::fromPlanList([$one], 'source'),
            OosParserExtractionSignature::fromPlanList([$two], 'source'),
        );

        $this->assertSame(['item_structure', 'titles'], $difference['groups_that_differ']);
        $structure = $difference['plans']['2023-01-01-morning']['item_structure'];
        $this->assertSame(1, $structure['first_item_count']);
        $this->assertSame(2, $structure['second_item_count']);
    }

    #[Test]
    public function it_attributes_a_provenance_only_change_to_provenance(): void
    {
        $original = $this->plan('2023-01-01-morning', 'morning', [$this->item(1, 'Amazing Grace')]);
        $rebound = array_replace_recursive($original, ['source_provenance' => ['service_evidence_line_ids' => [12]]]);

        $difference = OosParserExtractionSignature::fieldDifferences(
            OosParserExtractionSignature::fromPlanList([$original], 'source'),
            OosParserExtractionSignature::fromPlanList([$rebound], 'source'),
        );

        $this->assertSame(['provenance'], $difference['groups_that_differ']);
        $this->assertSame(['service_evidence_line_ids'], $difference['plans']['2023-01-01-morning']['provenance']['fields']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function plan(string $key, string $service, array $items): array
    {
        return [
            'plan_key' => $key,
            'service' => $service,
            'date' => '2023-01-01',
            'content_scope' => 'full',
            'items' => $items,
            'confidence' => 0.9,
            'needs_review' => false,
            'should_import' => true,
            'disposition' => 'auto_import',
            'validation_reasons' => ['looks complete'],
            'content_validation_reasons' => [],
            'hold_reasons' => [],
            'source_provenance' => [
                'items' => [['line_id' => 3]],
                'service_evidence_line_ids' => [1],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function item(int $position, string $title): array
    {
        return [
            'position' => $position,
            'type' => 'song',
            'section_type' => 'worship',
            'title' => $title,
            'source_title' => $title,
            'openlp_search_title' => null,
            'metadata' => null,
        ];
    }
}
