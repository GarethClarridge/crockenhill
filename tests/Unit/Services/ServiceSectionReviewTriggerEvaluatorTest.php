<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Services\ServiceSectionReviewTriggerEvaluator;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionReviewTriggerEvaluatorTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceSectionReviewTriggerEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ServiceSectionReviewTriggerEvaluator;
    }

    #[Test]
    public function it_captures_alignment_state_correctly(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'title' => 'Test Section',
            'confidence' => 0.9,
            'metadata' => [
                'reading_reference' => 'Genesis 1:1',
                'song_id' => 456,
                'review_reason' => 'none',
            ],
        ]);

        $sections = new EloquentCollection([$section]);
        $state = $this->evaluator->captureAlignmentState($sections);

        $this->assertArrayHasKey($section->id, $state);
        $this->assertEquals($item->id, $state[$section->id]['church_service_item_id']);
        $this->assertEquals('Test Section', $state[$section->id]['title']);
        $this->assertEquals(0.9, $state[$section->id]['confidence']);
        $this->assertEquals('Genesis 1:1', $state[$section->id]['reading_reference']);
        $this->assertEquals(456, $state[$section->id]['song_id']);
        $this->assertEquals('none', $state[$section->id]['review_reason']);
    }

    #[Test]
    public function it_triggers_ambiguous_sermon_detection(): void
    {
        $section = ServiceSection::factory()->create([
            'metadata' => ['review_reason' => 'secondary_sermon_candidate'],
        ]);

        $sections = new EloquentCollection([$section]);
        $triggers = $this->evaluator->evaluate($sections, [], 0, [], false);

        $this->assertContains('ambiguous_sermon_detection', $triggers);
    }

    #[Test]
    public function it_triggers_unmatched_song_sections_and_updates_metadata(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::SONG,
            'confidence' => 0.9,
            'metadata' => [],
        ]);

        $sections = new EloquentCollection([$section]);
        // Section ID is NOT in matchedSongSectionIds
        $triggers = $this->evaluator->evaluate($sections, [], 0, [], false);

        $this->assertContains('unmatched_song_sections', $triggers);
        $this->assertTrue($section->needs_manual_review);
        $this->assertContains('unmatched_song_section', $section->metadata['review_flags']);
        $this->assertEquals('unmatched_song_section', $section->metadata['review_reason']);
        // 0.9 - 0.10 = 0.8
        $this->assertEquals(0.8, $section->confidence);
    }

    #[Test]
    public function it_triggers_oos_structure_mismatch(): void
    {
        $sections = new EloquentCollection;
        $triggers = $this->evaluator->evaluate($sections, [], 1, [], false);

        $this->assertContains('oos_structure_mismatch', $triggers);
    }

    #[Test]
    public function it_triggers_late_oos_alignment_changed(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
        ]);

        $beforeState = [
            $section->id => [
                'church_service_item_id' => 999999, // Different from $item->id
                'title' => $section->title,
                'confidence' => ServiceSectionConfidence::resolve($section->confidence, $section->metadata),
                'reading_reference' => null,
                'song_id' => null,
                'review_reason' => null,
            ],
        ];

        $sections = new EloquentCollection([$section]);
        $triggers = $this->evaluator->evaluate($sections, [], 0, $beforeState, true);

        $this->assertContains('late_oos_alignment_changed', $triggers);
    }

    #[Test]
    public function it_triggers_too_many_low_confidence_sections(): void
    {
        // 5 sections, 2 low confidence (40% > 20%)
        $sections = new EloquentCollection;

        // High confidence
        for ($i = 0; $i < 3; $i++) {
            $sections->push(ServiceSection::factory()->create(['confidence' => 0.9]));
        }

        // Low confidence
        for ($i = 0; $i < 2; $i++) {
            $sections->push(ServiceSection::factory()->create(['confidence' => 0.4]));
        }

        $triggers = $this->evaluator->evaluate($sections, [], 0, [], false);

        $this->assertContains('too_many_low_confidence_sections', $triggers);
    }

    #[Test]
    public function it_returns_correct_unmatched_song_section_count(): void
    {
        $matched = ServiceSection::factory()->create(['section_type' => ServiceSectionType::SONG]);
        $unmatched = ServiceSection::factory()->create(['section_type' => ServiceSectionType::SONG]);
        $other = ServiceSection::factory()->create(['section_type' => ServiceSectionType::SERMON]);

        $sections = new EloquentCollection([$matched, $unmatched, $other]);

        $count = $this->evaluator->unmatchedSongSectionCount($sections, [$matched->id]);

        $this->assertEquals(1, $count);
    }

    #[Test]
    public function it_returns_correct_low_confidence_section_count(): void
    {
        $high = ServiceSection::factory()->create(['confidence' => 0.9]);
        $low = ServiceSection::factory()->create(['confidence' => 0.4]);

        $sections = new EloquentCollection([$high, $low]);

        $count = $this->evaluator->lowConfidenceSectionCount($sections);

        $this->assertEquals(1, $count);
    }
}
