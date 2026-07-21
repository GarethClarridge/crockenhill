<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceFlowBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceFlowBuilderTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Icon mapping
    // -------------------------------------------------------------------------

    #[Test]
    public function icons_are_assigned_correctly_by_section_type(): void
    {
        $run = $this->createRun();

        $rows = [
            $this->makeRow(['section_type' => ServiceSectionType::Song]),
            $this->makeRow(['section_type' => ServiceSectionType::Sermon]),
            $this->makeRow(['section_type' => ServiceSectionType::ChildrensTalk]),
            $this->makeRow(['section_type' => ServiceSectionType::BibleReading]),
            $this->makeRow(['section_type' => ServiceSectionType::Prayer]),
            $this->makeRow(['section_type' => ServiceSectionType::Welcome]),
            $this->makeRow(['section_type' => ServiceSectionType::Notices]),
        ];

        $flow = ServiceFlowBuilder::build($rows, $run);

        $this->assertSame('♫', $flow[0]['icon']);
        $this->assertSame('🎤', $flow[1]['icon']);
        $this->assertSame('📖', $flow[2]['icon']);
        $this->assertSame('📕', $flow[3]['icon']);
        $this->assertSame('', $flow[4]['icon']);
        $this->assertSame('', $flow[5]['icon']);
        $this->assertSame('', $flow[6]['icon']);
    }

    #[Test]
    public function planned_only_row_has_empty_icon(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow(['section_type' => null, 'row_type' => 'planned_only']);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('', $flow[0]['icon']);
    }

    // -------------------------------------------------------------------------
    // Description from ai_notes
    // -------------------------------------------------------------------------

    #[Test]
    public function description_uses_first_ai_note_when_present(): void
    {
        $run = $this->createRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => ['ai_notes' => ['Welcomes congregation', 'Second note']],
        ]);

        $run->load('serviceSections');

        $row = $this->makeRow(['section_id' => $section->id, 'section_type' => ServiceSectionType::Welcome]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Welcomes congregation', $flow[0]['description']);
    }

    #[Test]
    public function description_prefers_the_llm_section_summary_when_present(): void
    {
        $run = $this->createRun();
        $summary = 'The sermon explains God’s faithfulness from Joshua chapter one.';

        $row = $this->makeRow([
            'section_summary' => $summary,
            'metadata' => ['ai_notes' => ['A less useful fallback.']],
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame($summary, $flow[0]['description']);
        $this->assertSame($summary, $flow[0]['section_summary']);
    }

    #[Test]
    public function description_is_truncated_to_120_chars(): void
    {
        $run = $this->createRun();

        $longNote = str_repeat('A', 130);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => ['ai_notes' => [$longNote]],
        ]);

        $run->load('serviceSections');

        $row = $this->makeRow(['section_id' => $section->id, 'section_type' => ServiceSectionType::Prayer]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame(120, mb_strlen($flow[0]['description']));
        $this->assertStringEndsWith('...', $flow[0]['description']);
    }

    #[Test]
    public function description_falls_back_to_transcript_excerpt_when_no_ai_notes(): void
    {
        $run = $this->createRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => ['transcript' => 'Let us pray together'],
        ]);

        $run->load('serviceSections');

        $row = $this->makeRow(['section_id' => $section->id, 'section_type' => ServiceSectionType::Prayer]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Let us pray together', $flow[0]['description']);
    }

    #[Test]
    public function description_for_planned_only_row_is_contextual_default(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'section_id' => null,
            'section_type' => null,
            'row_type' => 'planned_only',
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertStringContainsString('Expected from Order of Service', $flow[0]['description']);
    }

    #[Test]
    public function description_for_unmatched_song_includes_unmatched_label(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
            'song_title' => null,
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertStringContainsString('Unmatched', $flow[0]['description']);
    }

    // -------------------------------------------------------------------------
    // planned_context string
    // -------------------------------------------------------------------------

    #[Test]
    public function planned_context_for_matched_row_shows_planned_title(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'row_type' => 'matched',
            'planned_title' => 'Welcome',
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Planned: Welcome', $flow[0]['planned_context']);
    }

    #[Test]
    public function planned_context_for_mismatched_row_shows_expected_and_detected_types(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'row_type' => 'mismatched',
            'planned_title' => 'Prayer',
            'expected_section_type' => ServiceSectionType::Prayer,
            'section_type' => ServiceSectionType::Welcome,
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertStringContainsString('Expected Prayer', $flow[0]['planned_context']);
        $this->assertStringContainsString('detected Welcome', $flow[0]['planned_context']);
    }

    #[Test]
    public function planned_context_for_planned_only_row_is_not_detected_label(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'row_type' => 'planned_only',
            'planned_title' => 'Song',
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertStringContainsString('Expected from Order of Service', $flow[0]['planned_context']);
        $this->assertStringContainsString('not detected', $flow[0]['planned_context']);
    }

    #[Test]
    public function planned_context_for_unplanned_row_is_not_in_order_of_service(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow(['row_type' => 'unplanned', 'planned_title' => null]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Not in Order of Service', $flow[0]['planned_context']);
    }

    // -------------------------------------------------------------------------
    // duration_formatted
    // -------------------------------------------------------------------------

    #[Test]
    public function duration_formatted_converts_seconds_to_readable_string(): void
    {
        $run = $this->createRun();

        $rows = [
            $this->makeRow(['start_time' => 0.0, 'end_time' => 45.0]),    // 45s
            $this->makeRow(['start_time' => 0.0, 'end_time' => 120.0]),   // 2m
            $this->makeRow(['start_time' => 0.0, 'end_time' => 135.0]),   // 2m 15s
        ];

        $flow = ServiceFlowBuilder::build($rows, $run);

        $this->assertSame('45s', $flow[0]['duration_formatted']);
        $this->assertSame('2m', $flow[1]['duration_formatted']);
        $this->assertSame('2m 15s', $flow[2]['duration_formatted']);
    }

    #[Test]
    public function duration_formatted_is_null_when_times_missing(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow(['start_time' => null, 'end_time' => null]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertNull($flow[0]['duration_formatted']);
    }

    // -------------------------------------------------------------------------
    // title_suffix for songs
    // -------------------------------------------------------------------------

    #[Test]
    public function title_suffix_for_song_uses_song_title_from_row(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'section_type' => ServiceSectionType::Song,
            'song_title' => 'Though the Nations Rage',
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Though the Nations Rage', $flow[0]['title_suffix']);
    }

    #[Test]
    public function title_suffix_for_song_falls_back_to_song_title_hint_in_metadata(): void
    {
        $run = $this->createRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 300.0,
            'metadata' => ['song_title_hint' => 'Your Word'],
        ]);

        $run->load('serviceSections');

        $row = $this->makeRow([
            'section_id' => $section->id,
            'section_type' => ServiceSectionType::Song,
            'song_title' => null,
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('Your Word', $flow[0]['title_suffix']);
    }

    // -------------------------------------------------------------------------
    // sermon title from ai_analysis
    // -------------------------------------------------------------------------

    #[Test]
    public function sermon_title_suffix_comes_from_processing_log_ai_analysis(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'ai_analysis' => ['title' => '2 Peter 1 — Stirred up by reminder'],
        ]);

        $row = $this->makeRow(['section_type' => ServiceSectionType::Sermon]);
        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame('2 Peter 1 — Stirred up by reminder', $flow[0]['title_suffix']);
    }

    // -------------------------------------------------------------------------
    // planned_only rows preserved
    // -------------------------------------------------------------------------

    #[Test]
    public function planned_only_rows_are_preserved_with_correct_fields(): void
    {
        $run = $this->createRun();

        $timelineRows = [
            [
                'row_type' => 'planned_only',
                'section_id' => null,
                'section_type' => null,
                'item_type' => 'bibles',
                'start_time' => null,
                'end_time' => null,
                'planned_title' => 'Bible Reading',
                'planned_item_state' => 'active',
                'needs_review' => false,
                'review_reason' => null,
                'mismatch_reason' => null,
                'confidence_level' => null,
                'song_match_type' => null,
                'song_title' => null,
                'item_id' => 5,
                'position' => 3,
                'item_source' => null,
                'song_id' => null,
                'expected_section_type' => null,
                'publication_status' => null,
                'published_sermon' => null,
                'presentation_inference' => null,
                'section_order' => null,
                'section_title' => null,
                'confidence' => null,
            ],
        ];

        $flow = ServiceFlowBuilder::build($timelineRows, $run);

        $this->assertCount(1, $flow);
        $this->assertSame('planned_only', $flow[0]['row_type']);
        $this->assertStringContainsString('Expected from Order of Service', $flow[0]['planned_context']);
        $this->assertNull($flow[0]['start_time']);
        $this->assertNull($flow[0]['end_time']);
    }

    // -------------------------------------------------------------------------
    // publication status passthrough
    // -------------------------------------------------------------------------

    #[Test]
    public function publication_status_is_passed_through_to_flow_item(): void
    {
        $run = $this->createRun();

        $row = $this->makeRow([
            'section_type' => ServiceSectionType::Sermon,
            'publication_status' => ServiceSectionPublicationStatus::Published,
        ]);

        $flow = ServiceFlowBuilder::build([$row], $run);

        $this->assertSame(ServiceSectionPublicationStatus::Published, $flow[0]['publication_status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createRun(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create();
    }

    /**
     * Build a minimal timeline row for testing ServiceFlowBuilder directly.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'row_type' => 'unplanned',
            'section_id' => null,
            'section_type' => ServiceSectionType::Prayer,
            'item_type' => null,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'planned_title' => null,
            'planned_item_state' => null,
            'needs_review' => false,
            'review_reason' => null,
            'mismatch_reason' => null,
            'confidence_level' => null,
            'song_match_type' => null,
            'song_title' => null,
            'item_id' => null,
            'position' => null,
            'item_source' => null,
            'song_id' => null,
            'expected_section_type' => null,
            'publication_status' => null,
            'published_sermon' => null,
            'presentation_inference' => null,
            'section_order' => null,
            'section_title' => null,
            'section_summary' => null,
            'confidence' => null,
        ], $overrides);
    }
}
