<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\MergeAdjacentServiceSections;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeAdjacentServiceSectionsTest extends TestCase
{
    use DatabaseTransactions;

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
            'section_type' => ServiceSectionType::SONG,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 200.0,
            'duration' => 100.0,
            'source_segment_ids' => [1, 2],
        ]);

        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
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

    #[Test]
    public function it_fails_to_merge_sections_from_different_processing_runs(): void
    {
        $section1 = ServiceSection::factory()->create(['section_type' => ServiceSectionType::SONG]);
        $section2 = ServiceSection::factory()->create(['section_type' => ServiceSectionType::SONG]);

        $result = $this->action->execute($section1, $section2, $this->admin->id);

        $this->assertEquals('Both sections must belong to the same processing run.', $result);
    }

    #[Test]
    public function it_fails_to_merge_sections_of_different_types(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SERMON,
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
            'section_type' => ServiceSectionType::SONG,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
            'duration' => 50,
            'start_time' => 100,
            'end_time' => 150,
            'section_order' => 1,
        ]);

        $longer = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
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
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
        ]);
        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 201,
            'end_time' => 300,
            'section_order' => 2,
        ]);

        $this->action->execute($section1, $section2, $this->admin->id);

        Bus::assertDispatched(PrepareSectionPublicationCandidates::class, function ($job) use ($log) {
            return $job->processingLog->id === $log->id;
        });
    }

    #[Test]
    public function it_resets_extracted_media_if_present(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $section1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 100,
            'end_time' => 200,
            'section_order' => 1,
            'extracted_video_path' => 'path/to/video.mp4',
            'extracted_audio_path' => 'path/to/audio.mp3',
            'extracted_at' => now(),
            'publication_status' => ServiceSectionPublicationStatus::APPROVED,
        ]);

        // Mock hasExtractedMedia to return true by making files exist
        Storage::fake('public');
        Storage::disk('public')->put('path/to/video.mp4', 'dummy');
        Storage::disk('public')->put('path/to/audio.mp3', 'dummy');

        $section2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG,
            'start_time' => 201,
            'end_time' => 300,
            'section_order' => 2,
        ]);

        $this->action->execute($section1, $section2, $this->admin->id);

        $section1->refresh();
        $this->assertNull($section1->extracted_video_path);
        $this->assertNull($section1->extracted_audio_path);
        $this->assertNull($section1->extracted_at);
        $this->assertEquals(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section1->publication_status);
    }
}
