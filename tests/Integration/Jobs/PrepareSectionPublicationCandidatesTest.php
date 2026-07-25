<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AutoPublishServiceSection;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SpeakerProfile;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
            'media-processing.speaker_identification.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');
        Storage::disk('public')->put('sermons/audio/section.mp3', 'section-audio');

        $preacher = Preacher::factory()->create(['name' => 'Alice Speaker']);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $speakerService = $this->createMock(SpeakerIdentificationInterface::class);
        $speakerService->expects($this->once())
            ->method('identify')
            ->willReturn(SpeakerMatchResult::matched(
                $profile->load('preacher'),
                0.91,
                0.66,
                [$profile->id => 0.91]
            ));
        $this->instance(SpeakerIdentificationInterface::class, $speakerService);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => $expectedAudioPath,
                'full_path' => Storage::disk('local')->path($expectedAudioPath),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame($expectedAudioPath, $section->extracted_audio_path);
        $videoPath = $this->assertCandidateVideoPath($section);
        $this->assertNotNull($section->extracted_at);
        $this->assertNotNull($section->unpublished_expires_at);
        $this->assertSame('matched', $section->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertSame('Alice Speaker', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            'status' => 'completed',
        ]);
        // Candidates live on the sermon disk, not the local one that a production
        // deploy wipes — and audio and video must land on the *same* disk.
        Storage::disk('public')->assertExists($videoPath);
        Storage::disk('local')->assertMissing($videoPath);
    }

    #[Test]
    public function it_moves_non_publishable_sections_to_not_applicable(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'status' => ServiceSectionStatus::Identified->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNotNull($section->unpublished_expires_at);
    }

    #[Test]
    public function it_keeps_admin_approved_sections_when_they_become_ineligible(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.section_publishing.require_high_confidence' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'confidence' => 0.72,
            'metadata' => ['confidence_level' => 'low'],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->publication_status);
    }

    #[Test]
    public function it_flags_ambiguous_childrens_talk_speaker_matches_for_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.speaker_identification.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');
        Storage::disk('public')->put('sermons/audio/section.mp3', 'section-audio');

        $preacher = Preacher::factory()->create(['name' => 'Alice Speaker']);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $speakerService = $this->createMock(SpeakerIdentificationInterface::class);
        $speakerService->expects($this->once())
            ->method('identify')
            ->willReturn(SpeakerMatchResult::noMatch(
                0.88,
                0.84,
                [$profile->id => 0.88],
                'Margin below threshold'
            ));
        $this->instance(SpeakerIdentificationInterface::class, $speakerService);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => $expectedAudioPath,
                'full_path' => Storage::disk('local')->path($expectedAudioPath),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame('ambiguous', $section->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertArrayNotHasKey('reviewed', $section->metadata['childrens_talk_speaker'] ?? []);
    }

    #[Test]
    public function it_moves_a_heuristically_demoted_childrens_talk_to_pending_approval_when_speaker_matches(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
            'media-processing.speaker_identification.enabled' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');
        Storage::disk('public')->put('sermons/audio/section.mp3', 'section-audio');

        $preacher = Preacher::factory()->create(['name' => 'Bob Preacher']);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $speakerService = $this->createMock(SpeakerIdentificationInterface::class);
        $speakerService->expects($this->once())
            ->method('identify')
            ->willReturn(SpeakerMatchResult::matched(
                $profile->load('preacher'),
                0.93,
                0.68,
                [$profile->id => 0.93]
            ));
        $this->instance(SpeakerIdentificationInterface::class, $speakerService);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => [
                'confidence_level' => 'high',
                'review_reason' => 'demoted_secondary_sermon_to_childrens_talk',
                'review_flags' => ['heuristic_demotion'],
                'original_ai_classification' => ServiceSectionType::Sermon->value,
            ],
            'start_time' => 200.0,
            'end_time' => 680.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => $expectedAudioPath,
                'full_path' => Storage::disk('local')->path($expectedAudioPath),
                'original_size' => 2048,
                'final_size' => 2048,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame($expectedAudioPath, $section->extracted_audio_path);
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNotNull($section->extracted_at);
        $this->assertSame('matched', $section->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertSame('Bob Preacher', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');
        $mockExtractor->expects($this->never())->method('extractOptimizedAudio');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $job = new PrepareSectionPublicationCandidates($log);
        $job->handle(
            $mockExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );
    }

    #[Test]
    public function it_reextracts_candidate_media_when_existing_assets_belong_to_a_stale_classification_signature(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('public')->put('sermons/sections/old/video.mp4', 'stale-video');
        Storage::disk('public')->put('sermons/audio/old.mp3', 'stale-audio');
        Storage::disk('local')->put('temp/section-video.mp4', 'fresh-section-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'metadata' => [
                'confidence_level' => 'high',
                'publication_candidate_extraction' => [
                    'processing_id' => $processingLog->processing_id,
                    'classification_signature' => 'stale-signature',
                    'extracted_at' => now()->subDay()->toIso8601String(),
                ],
            ],
            'extracted_video_path' => 'sermons/sections/old/video.mp4',
            'extracted_audio_path' => 'sermons/audio/old.mp3',
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';
        Storage::disk('local')->put($expectedAudioPath, 'fresh-section-audio');

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => $expectedAudioPath,
                'full_path' => Storage::disk('local')->path($expectedAudioPath),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame($expectedAudioPath, $section->extracted_audio_path);
        $this->assertCandidateVideoPath($section);
        $this->assertSame(
            $section->classificationSignature(),
            $section->metadata['publication_candidate_extraction']['classification_signature'] ?? null
        );
    }

    #[Test]
    public function it_dispatches_auto_publish_for_confirmed_song_sections(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake([AutoPublishServiceSection::class]);

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'childrens_talk' => SermonPublicationHandler::class,
                'song' => SongPublicationHandler::class,
            ],
        ]);

        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
            'church_service_id' => $churchService->id,
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 60.0,
            'end_time' => 300.0,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->never())
            ->method('extractOptimizedAudio');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        // Song sections do NOT go to PENDING_APPROVAL — they dispatch auto-publish instead.
        $this->assertNotSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNotNull($section->extracted_at);

        Bus::assertDispatched(AutoPublishServiceSection::class, function (AutoPublishServiceSection $job) use ($section) {
            // Verify it was dispatched for the correct section.
            return $job->serviceSectionId === $section->id;
        });
    }

    #[Test]
    public function it_skips_unmatched_song_sections_as_ineligible(): void
    {
        Bus::fake([AutoPublishServiceSection::class]);

        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'song' => SongPublicationHandler::class,
            ],
        ]);

        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);

        Bus::assertNotDispatched(AutoPublishServiceSection::class);
    }

    #[Test]
    public function it_does_not_extract_audio_for_song_sections(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake([AutoPublishServiceSection::class]);

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'song' => SongPublicationHandler::class,
            ],
        ]);

        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 60.0,
            'end_time' => 300.0,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        // The key assertion: audio extraction should NEVER be called for songs.
        $videoExtractor->expects($this->never())
            ->method('extractOptimizedAudio');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
    }

    /**
     * Audio and video of one section must land on the same disk. Changing only
     * the video's disk would split the pair, and DeleteLivestreamUpload's
     * per-field disk map would then be right about one and wrong about the other.
     */
    #[Test]
    public function it_extracts_candidate_audio_onto_the_same_disk_and_directory_as_the_video(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);

        $capturedAudioDirectory = null;

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'public',
                $this->callback(function (?string $directory) use (&$capturedAudioDirectory): bool {
                    $capturedAudioDirectory = $directory;

                    return true;
                }),
            )
            ->willReturn([
                'audio_path' => 'section-publications/captured/audio.mp3',
                'full_path' => '/tmp/audio.mp3',
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $videoPath = $this->assertCandidateVideoPath($section);
        $this->assertSame(dirname($videoPath), $capturedAudioDirectory);
    }

    /**
     * Asserts the stored candidate video path has the shape WP4 requires, and
     * returns it.
     *
     * The clips are unpublished review material on a public-read bucket, so the
     * key must not be derivable from the section id — hence the trailing
     * component. It is deliberately not recomputed here: a test that repeated
     * the derivation would only be comparing it to itself.
     */
    private function assertCandidateVideoPath(ServiceSection $section): string
    {
        $path = (string) $section->extracted_video_path;

        $this->assertMatchesRegularExpression(
            '#^section-publications/'.$section->id.'-[0-9a-f]{16}/video\.mp4$#',
            $path,
            'Candidate video must sit under a per-section directory that cannot be walked by id.',
        );

        return $path;
    }
}
