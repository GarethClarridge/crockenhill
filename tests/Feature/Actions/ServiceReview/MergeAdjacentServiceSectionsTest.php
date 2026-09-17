<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Actions\ServiceReview\MergeAdjacentServiceSections;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeAdjacentServiceSectionsTest extends TestCase
{
    use RefreshDatabase;

    private MergeAdjacentServiceSections $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(MergeAdjacentServiceSections::class);
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function it_merges_two_adjacent_sections_successfully(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 200.0,
            'duration' => 100.0,
            'source_segment_ids' => [1, 2],
        ]);

        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 2,
            'start_time' => 201.0, // 1 second gap
            'end_time' => 300.0,
            'duration' => 99.0,
            'source_segment_ids' => [3],
        ]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertNull($result);

        $section1->refresh();
        $this->assertEquals(100.0, $section1->start_time);
        $this->assertEquals(300.0, $section1->end_time);
        $this->assertEquals(200.0, $section1->duration);
        $this->assertEquals([1, 2, 3], $section1->source_segment_ids);

        $this->assertDatabaseMissing('service_sections', ['id' => $section2->id]);
    }

    /**
     * The merged row takes the removed section's span, so it takes its holds. Both
     * mergers merged the review column but left the flag and the recorded reasons on
     * the row they deleted, and a later confirmation would have released content an
     * operator proved wrong (Codex review 2026-09-15 P2).
     */
    #[Test]
    public function a_merge_carries_the_removed_sections_content_hold(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $primary = $this->songSection($log, 1, 100.0, 200.0);
        $secondary = $this->songSection($log, 2, 201.0, 260.0);

        app(HoldSectionForContentReview::class)($secondary, 'Clip opens on a prayer', 'plan §3.2');

        $this->assertNull($this->action->execute($primary, $secondary->refresh(), $this->admin->id));

        $primary->refresh();
        $metadata = $primary->metadata?->toArray() ?? [];

        $this->assertTrue($primary->needs_manual_review);
        $this->assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? []);
        $this->assertSame('Clip opens on a prayer', $metadata[HoldSectionForContentReview::METADATA_KEY][0]['reason'] ?? null);
        $this->assertDatabaseMissing('service_sections', ['id' => $secondary->id]);
    }

    /**
     * The existing cases carry the removed row's hold. The survivor's own hold
     * has to stay too, and the two must not collapse into one: the merged row
     * now holds both pieces of content, so it needs both reasons.
     */
    #[Test]
    public function a_merge_keeps_the_surviving_sections_own_hold_alongside_the_carried_one(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $primary = $this->songSection($log, 1, 100.0, 200.0);
        $secondary = $this->songSection($log, 2, 201.0, 260.0);

        app(HoldSectionForContentReview::class)($primary, 'Clip starts mid-verse', 'plan §4.1b');
        app(HoldSectionForContentReview::class)($secondary, 'Clip opens on a prayer', 'plan §3.2');

        $this->assertNull($this->action->execute($primary->refresh(), $secondary->refresh(), $this->admin->id));

        $primary->refresh();
        $metadata = $primary->metadata?->toArray() ?? [];
        $reasons = array_column($metadata[HoldSectionForContentReview::METADATA_KEY] ?? [], 'reason');

        $this->assertTrue($primary->needs_manual_review);
        $this->assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? []);
        $this->assertContains('Clip starts mid-verse', $reasons, "The survivor's own hold was overwritten.");
        $this->assertContains('Clip opens on a prayer', $reasons, "The removed section's hold was lost.");
        $this->assertDatabaseMissing('service_sections', ['id' => $secondary->id]);
    }

    /**
     * The action promotes the longer section to primary, so a held caller-primary
     * can become the row that is deleted. Its hold must still survive.
     */
    #[Test]
    public function a_merge_carries_the_hold_when_the_held_section_is_the_shorter_one(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $short = $this->songSection($log, 1, 100.0, 140.0);
        $long = $this->songSection($log, 2, 141.0, 320.0);

        app(HoldSectionForContentReview::class)($short, 'Clip starts mid-verse', 'plan §4.1b');

        // Passed short-first; the action swaps, so the held row is the one removed.
        $this->assertNull($this->action->execute($short->refresh(), $long->refresh(), $this->admin->id));

        $long->refresh();
        $metadata = $long->metadata?->toArray() ?? [];

        $this->assertTrue($long->needs_manual_review);
        $this->assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? []);
        $this->assertSame('Clip starts mid-verse', $metadata[HoldSectionForContentReview::METADATA_KEY][0]['reason'] ?? null);
        $this->assertDatabaseMissing('service_sections', ['id' => $short->id]);
    }

    #[Test]
    public function a_merge_does_not_re_raise_a_hold_an_operator_released(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $primary = $this->songSection($log, 1, 100.0, 200.0);
        $secondary = $this->songSection($log, 2, 201.0, 260.0);

        app(HoldSectionForContentReview::class)($secondary, 'Clip opens on a prayer', 'plan §3.2');
        app(ConfirmServiceSection::class)->execute($secondary->refresh(), $this->admin->id);

        $this->action->execute($primary, $secondary->refresh(), $this->admin->id);

        $primary->refresh();

        $this->assertFalse($primary->needs_manual_review);
        $this->assertNotContains(HoldSectionForContentReview::FLAG, $primary->metadata?->toArray()['review_flags'] ?? []);
    }

    private function songSection(MediaProcessingLog $log, int $order, float $start, float $end): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
    }

    #[Test]
    public function it_fails_to_merge_sections_from_different_processing_runs(): void
    {
        $section1 = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song]);
        $section2 = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('Both sections must belong to the same processing run.', $result);
    }

    #[Test]
    public function it_fails_to_merge_sections_of_different_types(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
        ]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('Both sections must have the same section type.', $result);
    }

    #[Test]
    public function it_fails_to_merge_published_sections(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'publication_status' => ServiceSectionPublicationStatus::Published,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
        ]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('Published sections cannot be merged.', $result);
    }

    #[Test]
    public function it_fails_to_merge_sections_with_too_large_gap(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 205, // gap of 5 seconds, default max is 2
            'end_time' => 300,
            'section_order' => 2,
        ]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('The sections are not close enough to merge (gap exceeds the configured threshold).', $result);
    }

    #[Test]
    public function it_fails_to_merge_sections_with_intervening_sections(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'start_time' => 100,
            'end_time' => 200,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_order' => 2,
        ]);

        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 3,
            'start_time' => 201,
            'end_time' => 300,
        ]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('There are other sections between these two — they cannot be merged.', $result);
    }

    #[Test]
    public function it_selects_the_longer_section_as_primary(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $shorter = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'duration' => 50,
            'start_time' => 100,
            'end_time' => 150,
            'section_order' => 1,
        ]);

        $longer = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'duration' => 100,
            'start_time' => 151,
            'end_time' => 251,
            'section_order' => 2,
        ]);

        // Pass shorter as primary, it should swap
        $result = $this->action->execute($shorter, $longer, $this->admin->id);

        $this->assertNull($result);

        $longer->refresh();
        $this->assertEquals(100, $longer->start_time);
        $this->assertEquals(251, $longer->end_time);
        $this->assertEquals(151, $longer->duration);

        $this->assertDatabaseMissing('service_sections', ['id' => $shorter->id]);
    }

    #[Test]
    public function it_updates_metadata_with_merge_information(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 201,
            'end_time' => 300,
            'section_order' => 2,
            'metadata' => [
                'review_reason' => 'Poor confidence',
            ],
        ]);

        $this->action->execute($section1, $section2, $this->admin->id);

        $section1->refresh();
        $metadata = $section1->metadata->toArray();

        $this->assertTrue($metadata['manually_merged']);
        $this->assertEquals($this->admin->id, $metadata['manually_merged_by_user_id']);
        $this->assertNotNull($metadata['manually_merged_at']);
        $this->assertEquals('Poor confidence', $metadata['merged_review_reason']);
    }

    #[Test]
    public function it_dispatches_reclassification_job_if_source_video_exists(): void
    {
        Bus::fake();
        Storage::fake('local');

        $log = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/video.mp4',
        ]);

        Storage::disk('local')->put('temp/video.mp4', 'dummy content');

        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 201,
            'end_time' => 300,
            'section_order' => 2,
        ]);

        $this->action->execute($section1, $section2, $this->admin->id);

        Bus::assertDispatched(PrepareSectionPublicationCandidates::class, function (PrepareSectionPublicationCandidates $job) use ($log) {
            return $job->processingLog->id === $log->id;
        });
    }

    #[Test]
    public function it_resets_extracted_media_if_present(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
            'extracted_video_path' => 'path/to/video.mp4',
            'extracted_audio_path' => 'path/to/audio.mp3',
            'extracted_at' => now(),
            'publication_status' => ServiceSectionPublicationStatus::Approved,
        ]);

        // Mock hasExtractedMedia to return true by making files exist
        Storage::fake('public');
        Storage::disk('public')->put('path/to/video.mp4', 'dummy');
        Storage::disk('public')->put('path/to/audio.mp3', 'dummy');

        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 201,
            'end_time' => 300,
            'section_order' => 2,
        ]);

        $this->action->execute($section1, $section2, $this->admin->id);

        $section1->refresh();
        $this->assertNull($section1->extracted_video_path);
        $this->assertNull($section1->extracted_audio_path);
        $this->assertNull($section1->extracted_at);
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section1->publication_status);
    }
}
