<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\MediaProcessingIdentityResolver;
use App\Services\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishApprovedServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_publishes_approved_section_and_links_created_sermon(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-10',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'extracted_video_path' => 'sermons/sections/10/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-10.mp3',
            'start_time' => 500.0,
            'end_time' => 900.0,
            'duration' => 400.0,
            'title' => "Children's Talk",
        ]);
        $section->metadata = array_merge($section->metadata ?? [], [
            'publication' => [
                'approved_signature' => $section->classificationSignature(),
                'approved_at' => now()->toIso8601String(),
            ],
        ]);
        $section->save();

        Storage::disk('public')->put('sermons/sections/10/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-10.mp3', 'audio');

        $createdSermon = Sermon::factory()->create();

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->once())
            ->method('createSermon')
            ->willReturn($createdSermon);

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle($sermonCreationService, app(MediaProcessingIdentityResolver::class));

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);
        $this->assertSame($createdSermon->id, $section->published_sermon_id);
        $this->assertNotNull($section->published_at);
        $this->assertNull($section->unpublished_expires_at);
    }

    #[Test]
    public function it_is_a_no_op_for_non_approved_sections(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_video_path' => 'sermons/sections/11/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-11.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/11/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-11.mp3', 'audio');

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->never())->method('createSermon');

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle($sermonCreationService, app(MediaProcessingIdentityResolver::class));

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
        $this->assertNull($section->published_sermon_id);
    }

    #[Test]
    public function it_rejects_publish_when_section_signature_differs_from_approval_signature(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'extracted_video_path' => 'sermons/sections/12/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-12.mp3',
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
                'publication' => [
                    'approved_signature' => 'outdated-signature',
                    'approved_at' => now()->subMinute()->toIso8601String(),
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/12/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-12.mp3', 'audio');

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->never())->method('createSermon');

        $job = new PublishApprovedServiceSection($section->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section classification changed since approval');

        $job->handle($sermonCreationService, app(MediaProcessingIdentityResolver::class));
    }

    #[Test]
    public function it_is_a_no_op_when_section_is_already_published_with_linked_sermon(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = Sermon::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
            'published_sermon_id' => $sermon->id,
        ]);

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->never())->method('createSermon');

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle($sermonCreationService, app(MediaProcessingIdentityResolver::class));

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);
        $this->assertSame($sermon->id, $section->published_sermon_id);
    }
}
