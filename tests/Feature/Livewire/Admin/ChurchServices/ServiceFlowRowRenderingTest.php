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
    public function it_wires_the_disclosure_button_to_the_details_region_for_screen_readers(): void
    {
        $rendered = $this->renderRow(rowIndex: 3);

        // The button must advertise which region it controls, and the region
        // must carry the matching id — the WAI-ARIA disclosure relationship.
        $this->assertStringContainsString('aria-controls="service-row-details-3"', $rendered);
        $this->assertStringContainsString('id="service-row-details-3"', $rendered);
    }

    #[Test]
    public function it_binds_aria_expanded_to_the_alpine_toggle_state(): void
    {
        $rendered = $this->renderRow();

        $this->assertStringContainsString(':aria-expanded="expanded.toString()"', $rendered);
        // The redundant static aria-expanded must not linger alongside the binding.
        $this->assertStringNotContainsString('aria-expanded="false"', $rendered);
    }

    #[Test]
    public function the_details_region_id_tracks_the_row_index(): void
    {
        $first = $this->renderRow(rowIndex: 0);
        $second = $this->renderRow(rowIndex: 1);

        $this->assertStringContainsString('id="service-row-details-0"', $first);
        $this->assertStringContainsString('id="service-row-details-1"', $second);
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
    public function it_keeps_planning_and_publication_state_for_song_sections(): void
    {
        $rendered = $this->renderRow(overrides: [
            'row_type' => 'unplanned',
            'type' => ServiceSectionType::Song,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);

        $this->assertStringContainsString('Not in plan', $rendered);
        $this->assertStringContainsString('Not Applicable', $rendered);
    }

    #[Test]
    public function it_keeps_planning_state_for_presentation_backed_sections(): void
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

        $this->assertStringContainsString('Not in plan', $rendered);
    }
}
