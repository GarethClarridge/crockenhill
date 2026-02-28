<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrepareSectionPublicationCandidatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_extracts_publishable_section_media_and_marks_pending_approval(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');
        Storage::disk('public')->put('sermons/audio/section.mp3', 'section-audio');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'sermons/audio/section.mp3',
                'full_path' => Storage::disk('public')->path('sermons/audio/section.mp3'),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class));

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
        $this->assertSame('sermons/audio/section.mp3', $section->extracted_audio_path);
        $this->assertSame('sermons/sections/'.$section->id.'/video.mp4', $section->extracted_video_path);
        $this->assertNotNull($section->extracted_at);
        $this->assertNotNull($section->unpublished_expires_at);
        Storage::disk('public')->assertExists('sermons/sections/'.$section->id.'/video.mp4');
    }

    #[Test]
    public function it_moves_non_publishable_sections_to_not_applicable(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class));

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
        $this->assertNotNull($section->unpublished_expires_at);
    }

    #[Test]
    public function it_keeps_admin_approved_sections_when_they_become_ineligible(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
            'media-processing.section_publishing.require_high_confidence' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'metadata' => ['confidence_level' => 'low'],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class));

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->publication_status);
    }
}
