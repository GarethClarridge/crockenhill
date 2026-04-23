<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServiceSection;
use App\Services\SectionAlignmentBaselineRestorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SectionAlignmentBaselineRestorerTest extends TestCase
{
    private SectionAlignmentBaselineRestorer $restorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restorer = new SectionAlignmentBaselineRestorer;
    }

    // ── prepare: baseline restore ────────────────────────────────────────────

    #[Test]
    public function it_restores_confidence_from_base_confidence_in_metadata(): void
    {
        $section = $this->makeSection(confidence: 0.90, metadata: [
            'oos_alignment' => [
                'base_confidence' => 0.55,
                'base_needs_manual_review' => false,
                'base_title' => 'Song One',
                'base_church_service_item_id' => null,
            ],
        ]);

        $this->restorer->prepare($section);

        $this->assertEqualsWithDelta(0.55, $section->confidence, 0.001);
    }

    #[Test]
    public function it_restores_title_from_base_title_in_metadata(): void
    {
        $section = $this->makeSection(title: 'Overwritten Title', metadata: [
            'oos_alignment' => [
                'base_confidence' => 0.5,
                'base_needs_manual_review' => false,
                'base_title' => 'Original Title',
                'base_church_service_item_id' => null,
            ],
        ]);

        $this->restorer->prepare($section);

        $this->assertSame('Original Title', $section->title);
    }

    #[Test]
    public function it_restores_church_service_item_id_from_base_in_metadata(): void
    {
        $section = $this->makeSection(churchServiceItemId: 99, metadata: [
            'oos_alignment' => [
                'base_confidence' => 0.5,
                'base_needs_manual_review' => false,
                'base_title' => null,
                'base_church_service_item_id' => null,
            ],
        ]);

        $this->restorer->prepare($section);

        $this->assertNull($section->church_service_item_id);
    }

    #[Test]
    public function it_clears_oos_owned_alignment_metadata_keys(): void
    {
        $section = $this->makeSection(metadata: [
            'oos_alignment' => ['matched_item_id' => 5],
            'song_id' => 10,
            'reading_reference' => 'Psalm 23',
        ]);

        $this->restorer->prepare($section);

        $this->assertArrayNotHasKey('oos_alignment', $section->metadata);
        $this->assertArrayNotHasKey('song_id', $section->metadata);
        $this->assertArrayNotHasKey('reading_reference', $section->metadata);
    }

    #[Test]
    public function it_removes_oos_owned_review_flags_while_preserving_external_flags(): void
    {
        $section = $this->makeSection(metadata: [
            'review_flags' => ['external_flag', 'oos_structure_mismatch', 'inferred_childrens_talk'],
            'review_reason' => 'external_flag',
        ]);

        $this->restorer->prepare($section);

        $this->assertContains('external_flag', $section->metadata['review_flags']);
        $this->assertNotContains('oos_structure_mismatch', $section->metadata['review_flags']);
        $this->assertNotContains('inferred_childrens_talk', $section->metadata['review_flags']);
    }

    #[Test]
    public function it_clears_review_flags_key_when_only_oos_flags_remain(): void
    {
        $section = $this->makeSection(metadata: [
            'review_flags' => ['oos_structure_mismatch'],
            'review_reason' => 'oos_structure_mismatch',
        ]);

        $this->restorer->prepare($section);

        $this->assertArrayNotHasKey('review_flags', $section->metadata);
        $this->assertArrayNotHasKey('review_reason', $section->metadata);
    }

    #[Test]
    public function it_preserves_non_oos_review_reason_after_clearing_oos_flags(): void
    {
        $section = $this->makeSection(metadata: [
            'review_flags' => ['external_flag', 'unmatched_song_section'],
            'review_reason' => 'external_flag',
        ]);

        $this->restorer->prepare($section);

        $this->assertSame('external_flag', $section->metadata['review_reason']);
    }

    #[Test]
    public function it_resets_legacy_openlp_aligned_sections_to_null_title_and_item(): void
    {
        $section = $this->makeSection(
            title: 'Some Song',
            churchServiceItemId: 42,
            metadata: ['classification_mode' => 'openlp_aligned'],
        );

        $this->restorer->prepare($section);

        $this->assertNull($section->title);
        $this->assertNull($section->church_service_item_id);
    }

    // ── baseAlignmentMetadata ────────────────────────────────────────────────

    #[Test]
    public function it_writes_current_section_values_as_base_on_first_run(): void
    {
        $section = $this->makeSection(
            confidence: 0.60,
            needsManualReview: false,
            title: 'First Title',
            churchServiceItemId: 7,
        );

        $base = $this->restorer->baseAlignmentMetadata($section);

        $this->assertEqualsWithDelta(0.60, $base['base_confidence'], 0.001);
        $this->assertFalse($base['base_needs_manual_review']);
        $this->assertSame('First Title', $base['base_title']);
        $this->assertSame(7, $base['base_church_service_item_id']);
    }

    #[Test]
    public function it_preserves_existing_base_values_on_subsequent_runs(): void
    {
        $section = $this->makeSection(
            confidence: 0.90,
            needsManualReview: true,
            title: 'Rerun Title',
            metadata: [
                'oos_alignment' => [
                    'base_confidence' => 0.40,
                    'base_needs_manual_review' => false,
                    'base_title' => 'Original Title',
                    'base_church_service_item_id' => null,
                ],
            ],
        );

        $base = $this->restorer->baseAlignmentMetadata($section);

        $this->assertEqualsWithDelta(0.40, $base['base_confidence'], 0.001);
        $this->assertFalse($base['base_needs_manual_review']);
        $this->assertSame('Original Title', $base['base_title']);
        $this->assertNull($base['base_church_service_item_id']);
    }

    // ── clearOosReviewFlags ──────────────────────────────────────────────────

    #[Test]
    public function it_removes_all_oos_owned_flags(): void
    {
        $flags = ['external', 'oos_structure_mismatch', 'song_alignment_inferred', 'ambiguous_childrens_talk'];

        $result = $this->restorer->clearOosReviewFlags($flags);

        $this->assertSame(['external'], $result);
    }

    #[Test]
    public function it_returns_empty_array_when_all_flags_are_oos_owned(): void
    {
        $flags = ['oos_structure_mismatch', 'unmatched_song_section', 'inferred_childrens_talk'];

        $result = $this->restorer->clearOosReviewFlags($flags);

        $this->assertSame([], $result);
    }

    // ── persistConfidenceLevel ───────────────────────────────────────────────

    #[Test]
    public function it_normalizes_confidence_to_column_only(): void
    {
        $section = $this->makeSection(confidence: 0.90);

        $this->restorer->persistConfidenceLevel($section);

        $this->assertEqualsWithDelta(0.90, $section->confidence, 0.001);
        $this->assertEqualsWithDelta(0.90, $section->metadata['confidence_score'], 0.001);
        $this->assertArrayNotHasKey('confidence_level', $section->metadata->toArray());
    }

    #[Test]
    public function it_preserves_confidence_score_for_legacy_fallback(): void
    {
        $section = $this->makeSection(confidence: 0.60);

        $this->restorer->persistConfidenceLevel($section);

        $this->assertEqualsWithDelta(0.60, $section->metadata['confidence_score'], 0.001);
        $this->assertArrayNotHasKey('confidence_level', $section->metadata->toArray());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function makeSection(
        float $confidence = 0.50,
        bool $needsManualReview = false,
        ?string $title = null,
        ?int $churchServiceItemId = null,
        array $metadata = [],
    ): ServiceSection {
        $section = new ServiceSection;
        $section->confidence = $confidence;
        $section->needs_manual_review = $needsManualReview;
        $section->title = $title;
        $section->church_service_item_id = $churchServiceItemId;
        $section->metadata = $metadata;

        return $section;
    }
}
