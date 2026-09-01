<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AutoPublishServiceSection;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\SendCompletionNotification;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SpeakerProfile;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class PrepareSectionPublicationCandidatesTest extends TestCase
{
    use CreatesHistoricImportOperations;
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
    public function a_standalone_historic_preparation_closes_the_run_and_queues_cleanup(): void
    {
        Storage::fake('local');
        Storage::fake('historic_staging');
        Bus::fake();

        config([
            'media-processing.section_publishing.enabled' => true,
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
            'thumbnail-generation.storage.disk' => 'historic_staging',
        ]);

        $operation = $this->createHistoricImportOperation();
        $context = app(HistoricStagingGuard::class)
            ->contextForApprovedPlan($operation->manifest_hashes['video'], $operation->plan_hash);
        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/recut-source.mp4',
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'recut-source',
                    'staging_context' => $context->toArray(),
                ],
            ],
        ]);

        PrepareSectionPublicationCandidates::registerHistoricNestedJob($processingLog);

        $job = new PrepareSectionPublicationCandidates($processingLog, true);
        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');

        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );

        $this->assertSame(ProcessingStatus::Completed, $processingLog->fresh()->status);
        $this->assertSame('completed', HistoricImportNestedJob::query()->sole()->state);
        Bus::assertDispatched(CleanupTemporaryFiles::class, static function (CleanupTemporaryFiles $cleanup): bool {
            return true;
        });
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
    public function it_extracts_a_reviewed_childrens_talk_recut_exactly_once_and_keeps_approval_mandatory(): void
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

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'start_time' => 600.0,
            'end_time' => 760.5,
            'metadata' => [
                'confidence_level' => 'high',
                'childrens_talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => null,
                        'preacher_name' => 'Mary Helper',
                        'source' => 'manual',
                    ],
                ],
            ],
        ]);

        $capturedSegment = null;
        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturnCallback(function (string $inputPath, object $segment, ?string $outputFilename) use (&$capturedSegment): string {
                $capturedSegment = [
                    'start_time' => $segment->start_time,
                    'end_time' => $segment->end_time,
                ];
                Storage::disk('local')->put('temp/reviewed-child.mp4', 'recut-video');

                return 'temp/reviewed-child.mp4';
            });
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturnCallback(function (string $inputPath, object $segment, string $filename, string $disk, string $directory): array {
                $audioPath = $directory.'/'.$filename;
                Storage::disk($disk)->put($audioPath, 'recut-audio');

                return [
                    'audio_path' => $audioPath,
                    'full_path' => Storage::disk($disk)->path($audioPath),
                    'original_size' => 1024,
                    'final_size' => 1024,
                    'compression_applied' => false,
                    'compression_ratio' => 1.0,
                    'valid_for_transcription' => true,
                ];
            });

        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );
        $section->refresh();
        $firstMetadata = $section->metadata?->toArray();
        $firstUpdatedAt = $section->updated_at?->toISOString();
        $job->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );

        $section->refresh();

        $this->assertSame([
            'start_time' => 600.0,
            'end_time' => 760.5,
        ], $capturedSegment);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertTrue($section->hasResolvedChildrensTalkSpeaker());
        $this->assertSame(760.5, $section->metadata['childrens_talk_boundary']['candidate']['end_time'] ?? null);
        $this->assertSame($firstMetadata, $section->metadata?->toArray());
        $this->assertSame($firstUpdatedAt, $section->updated_at?->toISOString());
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
        $this->storeCleanSongBoundaryArtifacts($section);

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
    public function it_routes_inferred_song_sections_to_review_instead_of_auto_publish(): void
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
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'metadata' => [],
            'start_time' => 60.0,
            'end_time' => 300.0,
        ]);
        $this->storeCleanSongBoundaryArtifacts($section);

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

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNotNull($section->unpublished_expires_at);
        $this->assertSame(
            ['inferred_song_match'],
            array_column($section->metadata->toArray()['song_publication_review']['reasons'], 'kind'),
        );
        Bus::assertNotDispatched(AutoPublishServiceSection::class);
    }

    #[Test]
    public function it_routes_a_song_with_corroborated_boundary_risk_to_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake([AutoPublishServiceSection::class]);

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'local',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
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
        $transcriptPath = 'service-transcripts/test-'.$processingLog->processing_id.'.normalized.json';
        $rmsPath = 'service-transcripts/test-'.$processingLog->processing_id.'.rms.json';

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');
        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => [
                ['start' => 60.0, 'end' => 72.0, 'text' => 'Please stand as we sing.'],
                ['start' => 80.0, 'end' => 180.0, 'text' => 'We will sing now.'],
            ],
            'duration' => 300.0,
            'source' => 'mock',
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put($rmsPath, implode("\n", [
            'pts_time:60.000',
            'lavfi.astats.Overall.RMS_level=-20.0',
            'pts_time:72.000',
            'lavfi.astats.Overall.RMS_level=-20.0',
            'pts_time:76.000',
            'lavfi.astats.Overall.RMS_level=-20.0',
            'pts_time:80.000',
            'lavfi.astats.Overall.RMS_level=-20.0',
        ]));
        $processingLog->putServiceTranscriptPath($transcriptPath);
        $processingLog->forceFill(['rms_log_path' => $rmsPath])->save();

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

        (new PrepareSectionPublicationCandidates($processingLog))->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertSame(
            ['song_boundary_spoken_framing'],
            array_column($section->metadata->toArray()['song_publication_review']['reasons'], 'kind'),
        );
        $this->assertSame(
            'retain_inclusive_candidate',
            $section->metadata->toArray()['song_publication_boundary']['action'],
        );
        $this->assertSame(60.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
        Bus::assertNotDispatched(AutoPublishServiceSection::class);
    }

    #[Test]
    public function historic_completion_suppresses_notifications_and_owns_nested_publication_work(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake([AutoPublishServiceSection::class]);
        Mail::fake();

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => [
                'song' => SongPublicationHandler::class,
            ],
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        $operation = $this->createHistoricImportOperation();
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'livestreams/source.mp4',
            'church_service_id' => $churchService->id,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $operation->operation_id,
                    'job_key' => 'historic-video-job',
                ],
            ],
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
        $this->storeCleanSongBoundaryArtifacts($section);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('temp/section-video.mp4');
        $videoExtractor->expects($this->never())
            ->method('extractOptimizedAudio');

        (new PrepareSectionPublicationCandidates($processingLog))->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );
        (new SendCompletionNotification($processingLog))->handle();

        $nestedJob = HistoricImportNestedJob::query()->sole();

        $this->assertSame($operation->id, $nestedJob->historic_import_operation_id);
        $this->assertSame($processingLog->id, $nestedJob->media_processing_log_id);
        $this->assertSame(AutoPublishServiceSection::class, $nestedJob->job_type);
        $this->assertSame("auto-publish-section-{$section->id}", $nestedJob->job_key);
        $this->assertSame('queued', $nestedJob->state);
        Bus::assertDispatched(
            AutoPublishServiceSection::class,
            fn (AutoPublishServiceSection $job): bool => $job->serviceSectionId === $section->id,
        );
        Mail::assertNothingSent();
        $this->assertSame(['success'], $operation->alerts()->pluck('kind')->all());
        $this->assertSame(1, $operation->journalEntries()->where('event', 'notification_suppressed')->count());
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

    private function storeCleanSongBoundaryArtifacts(ServiceSection $section): void
    {
        config(['media-processing.storage.transcript_disk' => 'local']);

        $processingLog = $section->processingLog;
        $transcriptPath = 'service-transcripts/test-'.$processingLog->processing_id.'.normalized.json';
        $rmsPath = 'service-transcripts/test-'.$processingLog->processing_id.'.rms.json';

        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => [[
                'start' => (float) $section->start_time,
                'end' => (float) $section->end_time,
                'text' => 'The song begins.',
            ]],
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put(
            $rmsPath,
            "pts_time:{$section->start_time}\nlavfi.astats.Overall.RMS_level=-20.0",
        );

        $processingLog->putServiceTranscriptPath($transcriptPath);
        $processingLog->forceFill(['rms_log_path' => $rmsPath])->save();
    }
}
