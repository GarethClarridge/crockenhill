<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\Sermon\SermonCreationService;
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
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'welcome' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-10',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'extracted_video_path' => 'section-publications/10/video.mp4',
            'extracted_audio_path' => 'section-publications/10/section-10.mp3',
            'start_time' => 500.0,
            'end_time' => 900.0,
            'duration' => 400.0,
            'title' => "Children's Talk",
        ]);
        $section->metadata = array_merge($section->metadata?->toArray() ?? [], [
            'publication' => [
                'approved_signature' => $section->classificationSignature(),
                'approved_at' => now()->toIso8601String(),
            ],
        ]);
        $section->save();

        Storage::disk('public')->put('section-publications/10/video.mp4', 'video');
        Storage::disk('public')->put('section-publications/10/section-10.mp3', 'audio');

        $createdSermon = Sermon::factory()->create();

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->once())
            ->method('createSermon')
            ->willReturn($createdSermon);
        $this->instance(SermonCreationService::class, $sermonCreationService);

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame($createdSermon->id, $section->published_sermon_id);
        $this->assertNotNull($section->published_at);
        $this->assertNull($section->unpublished_expires_at);
        $this->assertSame('sermons/sections/'.$section->id.'/video.mp4', $section->extracted_video_path);
        $this->assertSame('sermons/audio/section-10.mp3', $section->extracted_audio_path);
        Storage::disk('public')->assertExists('sermons/sections/'.$section->id.'/video.mp4');
        Storage::disk('public')->assertExists('sermons/audio/section-10.mp3');
        Storage::disk('public')->assertMissing('section-publications/10/video.mp4');
        Storage::disk('public')->assertMissing('section-publications/10/section-10.mp3');
    }

    #[Test]
    public function it_is_a_no_op_for_non_approved_sections(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'welcome' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/11/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-11.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/11/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-11.mp3', 'audio');

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->never())->method('createSermon');
        $this->instance(SermonCreationService::class, $sermonCreationService);

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertNull($section->published_sermon_id);
    }

    #[Test]
    public function it_rejects_publish_when_section_signature_differs_from_approval_signature(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'welcome' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
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
        $this->instance(SermonCreationService::class, $sermonCreationService);

        $job = new PublishApprovedServiceSection($section->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section classification changed since approval');

        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );
    }

    #[Test]
    public function it_is_a_no_op_when_section_is_already_published_with_linked_sermon(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'childrens_talk' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = Sermon::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $sermon->id,
        ]);

        $sermonCreationService = $this->createMock(SermonCreationService::class);
        $sermonCreationService->expects($this->never())->method('createSermon');
        $this->instance(SermonCreationService::class, $sermonCreationService);

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame($sermon->id, $section->published_sermon_id);
    }

    #[Test]
    public function it_publishes_childrens_talk_sections_with_childrens_talk_content_type(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'childrens_talk' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-31',
            'extracted_service' => SermonService::Morning->value,
            'original_filename' => '2026-05-31-morning-service.mp4',
        ]);
        $preacher = Preacher::factory()->create(['name' => 'Mary Helper']);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'extracted_video_path' => 'sermons/sections/13/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-13.mp3',
            'start_time' => 300.0,
            'end_time' => 780.0,
            'duration' => 480.0,
            'title' => "Children's Talk",
            'metadata' => [
                'childrens_talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => $preacher->id,
                        'preacher_name' => $preacher->name,
                        'source' => PreacherSource::Manual->value,
                        'confidence' => null,
                    ],
                ],
            ],
        ]);
        $section->metadata = array_merge($section->metadata?->toArray() ?? [], [
            'publication' => [
                'approved_signature' => $section->classificationSignature(),
                'approved_at' => now()->toIso8601String(),
            ],
        ]);
        $section->save();

        Storage::disk('public')->put('sermons/sections/13/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-13.mp3', 'audio');

        $job = new PublishApprovedServiceSection($section->id);
        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );

        $section->refresh();
        $sermon = Sermon::query()->findOrFail($section->published_sermon_id);

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame(SermonContentType::ChildrensTalk, $sermon->content_type);
        $this->assertSame($preacher->id, $sermon->preacher_id);
        $this->assertSame($preacher->name, $sermon->preacher);
    }

    #[Test]
    public function it_requires_a_confirmed_childrens_talk_speaker_before_publishing(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'childrens_talk' => SermonPublicationHandler::class,
            ],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'original_filename' => '2026-06-07-morning-service.mp4',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'extracted_video_path' => 'sermons/sections/14/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-14.mp3',
            'metadata' => [
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'preacher_name' => 'Detected Speaker',
                        'confidence' => 0.62,
                    ],
                ],
            ],
        ]);
        $section->metadata = array_merge($section->metadata?->toArray() ?? [], [
            'publication' => [
                'approved_signature' => $section->classificationSignature(),
                'approved_at' => now()->toIso8601String(),
            ],
        ]);
        $section->save();

        Storage::disk('public')->put('sermons/sections/14/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-14.mp3', 'audio');

        $job = new PublishApprovedServiceSection($section->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Children's talk speaker must be reviewed before publication");

        $job->handle(
            app(SectionPublicationHandlerFactory::class),
        );
    }
}
