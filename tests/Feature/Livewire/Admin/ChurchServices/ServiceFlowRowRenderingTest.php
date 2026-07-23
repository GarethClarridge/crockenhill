<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceFlowRowRenderingTest extends TestCase
{
    /**
     * Renders the service-flow-row partial in isolation with a minimal item shape.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function renderRow(int $rowIndex = 0, array $overrides = []): string
    {
        $item = array_merge([
            'row_type' => 'matched',
            'icon' => '',
            'type' => null,
            'type_label' => 'Sermon',
            'title_suffix' => null,
            'duration_formatted' => null,
            'start_time' => null,
            'end_time' => null,
            'description' => '',
            'planned_context' => null,
            'needs_review' => false,
            'review_reason' => null,
            'mismatch_reason' => null,
            'confidence_level' => null,
            'section_id' => null,
            'transcript_excerpt' => null,
            'metadata' => [],
            'publication_status' => null,
            'song_match_type' => null,
            'published_sermon' => null,
        ], $overrides);

        return view('livewire.admin.church-services.partials.service-flow-row', [
            'item' => $item,
            'rowIndex' => $rowIndex,
        ])->render();
    }

    #[Test]
    public function it_wires_actionable_disclosures_to_their_details_region(): void
    {
        $rendered = $this->renderRow(rowIndex: 3, overrides: [
            'row_type' => 'mismatched',
            'mismatch_reason' => 'expected_type_mismatch',
        ]);

        $this->assertStringContainsString('aria-controls="service-row-details-3"', $rendered);
        $this->assertStringContainsString('id="service-row-details-3"', $rendered);
        $this->assertStringContainsString(':aria-expanded="expanded.toString()"', $rendered);
        $this->assertStringNotContainsString('aria-expanded="false"', $rendered);
    }

    #[Test]
    public function clean_rows_are_static_and_show_transcript_evidence(): void
    {
        $rendered = $this->renderRow(overrides: [
            'transcript_excerpt' => 'Let us pray together.',
        ]);

        $this->assertStringContainsString('Let us pray together.', $rendered);
        $this->assertStringNotContainsString('<button', $rendered);
        $this->assertStringNotContainsString('aria-controls=', $rendered);
    }

    #[Test]
    public function it_hides_not_in_plan_for_section_types_that_cannot_be_planned(): void
    {
        $rendered = $this->renderRow(overrides: [
            'row_type' => 'unplanned',
            'type' => ServiceSectionType::Other,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);

        $this->assertStringNotContainsString('Not in plan', $rendered);
        $this->assertStringNotContainsString('Not Applicable', $rendered);
    }

    #[Test]
    public function recording_only_song_sections_use_neutral_provenance(): void
    {
        $rendered = $this->renderRow(overrides: [
            'row_type' => 'unplanned',
            'type' => ServiceSectionType::Song,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);

        $this->assertStringContainsString('Recording only', $rendered);
        $this->assertStringNotContainsString('Not in plan', $rendered);
        $this->assertStringNotContainsString('bg-rose', $rendered);
    }

    #[Test]
    public function presentation_backed_recording_only_sections_are_not_warnings(): void
    {
        $rendered = $this->renderRow(overrides: [
            'row_type' => 'unplanned',
            'type' => ServiceSectionType::Other,
            'metadata' => [
                'oos_alignment' => [
                    'presentation_inference' => ['resolved_type' => 'other'],
                ],
            ],
        ]);

        $this->assertStringContainsString('Recording only', $rendered);
        $this->assertStringNotContainsString('Not in plan', $rendered);
    }
}
