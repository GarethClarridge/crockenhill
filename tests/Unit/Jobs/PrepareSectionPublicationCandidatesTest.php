<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Services\ChildrensTalkSpeakerService;
use App\Services\ServiceSectionPublicationTransitionService;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
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
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'private/section-publications/'.$section->id.'/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';

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
            app(ChildrensTalkSpeakerService::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame($expectedAudioPath, $section->extracted_audio_path);
        $this->assertSame('private/section-publications/'.$section->id.'/video.mp4', $section->extracted_video_path);
        $this->assertNotNull($section->extracted_at);
        $this->assertNotNull($section->unpublished_expires_at);
        $this->assertSame('matched', $section->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertSame('Alice Speaker', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            'status' => 'completed',
        ]);
        Storage::disk('local')->assertExists('private/section-publications/'.$section->id.'/video.mp4');
        Storage::disk('public')->assertMissing('private/section-publications/'.$section->id.'/video.mp4');
    }

    #[Test]
    public function it_moves_non_publishable_sections_to_not_applicable(): void
    {
        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(ChildrensTalkSpeakerService::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

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

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

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
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(ChildrensTalkSpeakerService::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->publication_status);
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
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
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
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'private/section-publications/'.$section->id.'/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';

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
            app(ChildrensTalkSpeakerService::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame('ambiguous', $section->metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertArrayNotHasKey('reviewed', $section->metadata['childrens_talk_speaker'] ?? []);
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

        Log::shouldReceive('info')->once()->with('PrepareSectionPublicationCandidates job skipped: processing cancelled', \Mockery::any());

        $job = new PrepareSectionPublicationCandidates($log);
        $job->handle(
            $mockExtractor,
            app(StorageAdapterHelper::class),
            app(ChildrensTalkSpeakerService::class),
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
            'media-processing.section_publishing.extract_types' => ['childrens_talk'],
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
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
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
        $expectedAudioPath = 'private/section-publications/'.$section->id.'/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';
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
            app(ChildrensTalkSpeakerService::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();

        $this->assertSame($expectedAudioPath, $section->extracted_audio_path);
        $this->assertSame('private/section-publications/'.$section->id.'/video.mp4', $section->extracted_video_path);
        $this->assertSame(
            $section->classificationSignature(),
            $section->metadata['publication_candidate_extraction']['classification_signature'] ?? null
        );
    }
}
