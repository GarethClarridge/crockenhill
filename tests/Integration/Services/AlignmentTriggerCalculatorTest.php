<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Services\ChurchService\AlignmentTriggerCalculator;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlignmentTriggerCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AlignmentTriggerCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(AlignmentTriggerCalculator::class);
    }

    // ── captureAlignmentState ──────────────────────────────────────────────

    #[Test]
    public function it_captures_all_relevant_state_fields(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'title' => 'Morning Prayer',
            'confidence' => 0.9,
            'song_match_type' => 'confirmed',
            'metadata' => [
                'reading_reference' => 'Psalm 23',
                'song_id' => 42,
                'review_reason' => 'no_high_confidence_sermon_candidate',
                'oos_alignment' => ['song_match_type' => 'confirmed'],
            ],
        ]);

        $state = $this->calculator->captureAlignmentState(new EloquentCollection([$section]));

        $this->assertArrayHasKey($section->id, $state);
        $snap = $state[$section->id];
        $this->assertSame($item->id, $snap['church_service_item_id']);
        $this->assertSame('Morning Prayer', $snap['title']);
        $this->assertSame(0.9, $snap['confidence']);
        $this->assertSame('Psalm 23', $snap['reading_reference']);
        $this->assertSame(42, $snap['song_id']);
        $this->assertSame('confirmed', $snap['song_match_type']);
        $this->assertSame('no_high_confidence_sermon_candidate', $snap['review_reason']);
    }

    // ── ambiguous_sermon_detection ─────────────────────────────────────────

    #[Test]
    public function it_triggers_ambiguous_sermon_detection_when_secondary_sermon_candidate(): void
    {
        $section = ServiceSection::factory()->create([
            'metadata' => ['review_reason' => 'secondary_sermon_candidate'],
        ]);

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            [],
            false
        );

        $this->assertContains('ambiguous_sermon_detection', $triggers);
    }

    #[Test]
    public function it_triggers_ambiguous_sermon_detection_when_no_high_confidence_sermon_candidate(): void
    {
        $section = ServiceSection::factory()->create([
            'metadata' => ['review_reason' => 'no_high_confidence_sermon_candidate'],
        ]);

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            [],
            false
        );

        $this->assertContains('ambiguous_sermon_detection', $triggers);
    }

    // ── unmatched_song_sections ────────────────────────────────────────────

    #[Test]
    public function it_triggers_unmatched_song_sections_when_the_passed_collection_is_non_empty(): void
    {
        $unmatchedSection = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::SONG,
        ]);

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$unmatchedSection]),
            0,
            new Collection([$unmatchedSection]),
            [],
            false
        );

        $this->assertContains('unmatched_song_sections', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_unmatched_song_sections_when_the_collection_is_empty(): void
    {
        $section = ServiceSection::factory()->create(['confidence' => 0.9]);

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            [],
            false
        );

        $this->assertNotContains('unmatched_song_sections', $triggers);
    }

    // ── oos_structure_mismatch ─────────────────────────────────────────────

    #[Test]
    public function it_triggers_oos_structure_mismatch_when_mismatch_count_is_greater_than_zero(): void
    {
        $triggers = $this->calculator->calculate(
            new EloquentCollection,
            1,
            new Collection,
            [],
            false
        );

        $this->assertContains('oos_structure_mismatch', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_oos_structure_mismatch_when_count_is_zero(): void
    {
        $triggers = $this->calculator->calculate(
            new EloquentCollection,
            0,
            new Collection,
            [],
            false
        );

        $this->assertNotContains('oos_structure_mismatch', $triggers);
    }

    // ── too_many_low_confidence_sections ───────────────────────────────────

    #[Test]
    public function it_triggers_too_many_low_confidence_sections_when_ratio_exceeds_20_percent(): void
    {
        // 5 sections, 2 low confidence = 40%
        $sections = new EloquentCollection;

        for ($i = 0; $i < 3; $i++) {
            $sections->push(ServiceSection::factory()->create(['confidence' => 0.9]));
        }

        for ($i = 0; $i < 2; $i++) {
            $sections->push(ServiceSection::factory()->create(['confidence' => 0.4]));
        }

        $triggers = $this->calculator->calculate($sections, 0, new Collection, [], false);

        $this->assertContains('too_many_low_confidence_sections', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_too_many_low_confidence_sections_when_ratio_is_exactly_20_percent(): void
    {
        // 5 sections, 1 low confidence = 20% — threshold is strictly > 0.20
        $sections = new EloquentCollection;

        for ($i = 0; $i < 4; $i++) {
            $sections->push(ServiceSection::factory()->create(['confidence' => 0.9]));
        }

        $sections->push(ServiceSection::factory()->create(['confidence' => 0.4]));

        $triggers = $this->calculator->calculate($sections, 0, new Collection, [], false);

        $this->assertNotContains('too_many_low_confidence_sections', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_too_many_low_confidence_sections_when_sections_are_empty(): void
    {
        $triggers = $this->calculator->calculate(
            new EloquentCollection,
            0,
            new Collection,
            [],
            false
        );

        $this->assertNotContains('too_many_low_confidence_sections', $triggers);
    }

    // ── late_oos_alignment_changed ─────────────────────────────────────────

    #[Test]
    public function it_triggers_late_oos_alignment_changed_when_state_differs_and_late_arrival_is_true(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'title' => 'New Title',
            'confidence' => 0.9,
        ]);

        $beforeState = [
            $section->id => [
                'church_service_item_id' => 999,
                'title' => 'Old Title',
                'confidence' => 0.5,
                'reading_reference' => null,
                'song_id' => null,
                'song_match_type' => null,
                'review_reason' => null,
            ],
        ];

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            $beforeState,
            true
        );

        $this->assertContains('late_oos_alignment_changed', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_late_oos_alignment_changed_when_state_is_identical(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'title' => 'Unchanged',
            'confidence' => 0.9,
            'metadata' => [],
        ]);

        // Capture state then immediately compare — must be equal
        $beforeState = $this->calculator->captureAlignmentState(new EloquentCollection([$section]));

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            $beforeState,
            true
        );

        $this->assertNotContains('late_oos_alignment_changed', $triggers);
    }

    #[Test]
    public function it_does_not_trigger_late_oos_alignment_changed_when_late_arrival_is_false(): void
    {
        $section = ServiceSection::factory()->create(['confidence' => 0.9]);

        $beforeState = [
            $section->id => [
                'church_service_item_id' => 999,
                'title' => 'Different',
                'confidence' => 0.1,
                'reading_reference' => null,
                'song_id' => null,
                'song_match_type' => null,
                'review_reason' => null,
            ],
        ];

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            $beforeState,
            false
        );

        $this->assertNotContains('late_oos_alignment_changed', $triggers);
    }

    // ── manual_review_sections ─────────────────────────────────────────────

    #[Test]
    public function it_triggers_manual_review_sections_when_any_section_needs_review(): void
    {
        $section = ServiceSection::factory()->create(['needs_manual_review' => true]);

        $triggers = $this->calculator->calculate(
            new EloquentCollection([$section]),
            0,
            new Collection,
            [],
            false
        );

        $this->assertContains('manual_review_sections', $triggers);
    }

    // ── lowConfidenceSectionCount ──────────────────────────────────────────

    #[Test]
    public function it_returns_correct_low_confidence_section_count(): void
    {
        $high = ServiceSection::factory()->create(['confidence' => ServiceSectionConfidence::HIGH_THRESHOLD]);
        $low = ServiceSection::factory()->create(['confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.01]);

        $count = $this->calculator->lowConfidenceSectionCount(new EloquentCollection([$high, $low]));

        $this->assertSame(1, $count);
    }

    #[Test]
    public function it_deduplicates_triggers(): void
    {
        // Two sections both needing review — should produce only one 'manual_review_sections' entry
        $sections = new EloquentCollection([
            ServiceSection::factory()->create(['needs_manual_review' => true]),
            ServiceSection::factory()->create(['needs_manual_review' => true]),
        ]);

        $triggers = $this->calculator->calculate($sections, 0, new Collection, [], false);

        $this->assertSame(count(array_unique($triggers)), count($triggers));
    }
}
