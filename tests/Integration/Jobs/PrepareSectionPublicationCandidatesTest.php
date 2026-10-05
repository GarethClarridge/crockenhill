<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Actions\HoldSectionForContentReview;
use App\Contracts\SpeakerIdentificationInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\HistoricStagingContext;
use App\Data\ServiceSectionMetadata;
use App\Data\SpeakerMatchResult;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
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
use App\Models\SongVideo;
use App\Models\SpeakerProfile;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\SectionPublication\TalkPublicationHandler;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Import\HistoricReleaseReviewHolds;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\MediaAssetPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\BanksNoWordOutputEdges;
use Tests\TestCase;

class PrepareSectionPublicationCandidatesTest extends TestCase
{
    use BanksNoWordOutputEdges;
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $probe = $this->createStub(ExtractedMediaDurationProbe::class);
        $probe->method('durationOf')->willReturn(300.0);
        $this->instance(ExtractedMediaDurationProbe::class, $probe);
    }

    #[Test]
    public function a_missing_word_window_blocks_only_that_output_and_records_why(): void
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
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => ['manual_review' => ['status' => 'required', 'reason_code' => 'sermon_section_content_held']],
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
            'start_time' => 113.38,
            'end_time' => 300.0,
        ]);

        $other = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song, 'start_time' => 400.0, 'end_time' => 500.0,
            'needs_manual_review' => false, 'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['confidence_level' => 'high'],
        ]);
        $processingLog->putServiceTranscriptPath('temp/shared.json');
        Storage::disk('local')->put('temp/shared.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 112.62, 'end' => 114.14, 'text' => "Let's stand and sing King of Kings."],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with($this->anything(), $this->callback(fn (object $segment): bool => $segment->start_time === 400.0), $this->anything())
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
        $this->assertNull($section->extracted_video_path);
        $this->assertSame('edge_word_timings_missing', $section->metadata->raw['publication_candidate_extraction_blocked']['reason']);
        $this->assertNotNull($other->fresh()->extracted_video_path);
    }

    /** F09: a song's cut widened through a shared cue must not carry a held talk into publishable media. */
    #[Test]
    public function a_cut_widened_into_a_held_section_blocks_only_that_output_and_records_why(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake([AutoPublishServiceSection::class]);
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['song' => SongPublicationHandler::class],
        ]);
        $item = ChurchServiceItem::factory()->create(['song_id' => Song::factory()->create()->id]);
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create(['source_file_path' => 'livestreams/source.mp4']);
        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        $song = fn (float $start, float $end): ServiceSection => ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song, 'status' => ServiceSectionStatus::Identified,
            'start_time' => $start, 'end_time' => $end, 'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed, 'metadata' => ['confidence_level' => 'high'],
        ]);
        $section = $song(100.0, 300.0);
        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'section_type' => ServiceSectionType::ShortTalk,
            'start_time' => 300.0, 'end_time' => 400.0, 'needs_manual_review' => true,
            'metadata' => ['review_flags' => [HoldSectionForContentReview::FLAG]],
        ]);
        $other = $song(600.0, 700.0);
        $processingLog->putServiceTranscriptPath('temp/shared.json');
        Storage::disk('local')->put('temp/shared.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 299.5, 'end' => 301.0, 'text' => 'Amen. Now a word for the children.'],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->bankNoWordOutputEdges($processingLog);
        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with($this->anything(), $this->callback(fn (object $segment): bool => $segment->start_time === 600.0), $this->anything())
            ->willReturnCallback(function (): string {
                Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

                return 'temp/section-video.mp4';
            });

        (new PrepareSectionPublicationCandidates($processingLog))->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $section->refresh();
        $this->assertNull($section->extracted_video_path);
        $this->assertSame('span_crosses_held_section', $section->metadata->raw['publication_candidate_extraction_blocked']['reason']);
        $this->assertSame([$talk->id], $section->metadata->raw['publication_candidate_extraction_blocked']['crossed_held_section_ids']);
        Bus::assertNotDispatched(AutoPublishServiceSection::class, fn ($job): bool => $job->serviceSectionId === $section->id);
        $this->assertNotNull($other->fresh()->extracted_video_path);
    }

    #[Test]
    public function run_1221s_song_cut_includes_the_shared_welcome_cue_and_records_the_widening(): void
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
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => ['manual_review' => ['status' => 'required', 'reason_code' => 'sermon_section_content_held']],
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
            'start_time' => 113.38,
            'end_time' => 300.0,
        ]);

        $heldSections = [];
        foreach (['content_defect_hold', 'structure_ensemble_disagrees'] as $index => $flag) {
            $heldSections[] = ServiceSection::factory()->create([
                'media_processing_log_id' => $processingLog->id, 'church_service_item_id' => $item->id,
                'section_type' => ServiceSectionType::Song, 'start_time' => 400.0 + 200 * $index,
                'end_time' => 500.0 + 200 * $index, 'needs_manual_review' => true,
                'song_match_type' => ServiceSectionSongMatchType::Confirmed,
                'metadata' => ['review_flags' => [$flag]],
            ]);
        }
        $processingLog->putServiceTranscriptPath('temp/shared.json');
        Storage::disk('local')->put('temp/shared.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 112.62, 'end' => 114.14, 'text' => "Let's stand and sing King of Kings."],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        $this->bankNoWordOutputEdges($processingLog);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->exactly(2))
            ->method('extractSegmentAsFile')
            ->with($this->anything(), $this->callback(fn (object $segment): bool => in_array($segment->start_time, [112.62, 400.0], true)), $this->anything())
            ->willReturnCallback(function (): string {
                Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

                return 'temp/section-video.mp4';
            });
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
        $audit = $section->metadata->raw['publication_candidate_extraction'];
        $this->assertSame(112.62, $audit['segments'][0]['start_time']);
        $this->assertEqualsWithDelta(0.76, $audit['cue_edge_widening'][0]['seconds_added'], 0.0001);
        $this->assertSame(113.38, (float) $section->start_time);
        $this->assertNotNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNotNull($heldSections[0]->refresh()->extracted_video_path);
        $recorded = data_get($processingLog->fresh()->processing_metadata?->toArray(), 'media_outputs.section_'.$heldSections[0]->id);
        $this->assertSame(hash('sha256', 'section-video'), $recorded['sha256'] ?? null);
        $this->assertSame($heldSections[0]->extracted_video_path, $recorded['path'] ?? null);
        $this->assertTrue($heldSections[0]->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $heldSections[0]->publication_status);
        $this->assertNull($heldSections[1]->refresh()->extracted_video_path);
        Bus::assertNotDispatched(AutoPublishServiceSection::class, fn ($job): bool => $job->serviceSectionId === $heldSections[0]->id);
        (new AutoPublishServiceSection($heldSections[0]->id))->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $heldSections[0]->refresh()->publication_status);
        $video = SongVideo::factory()->create([
            'service_section_id' => $heldSections[0]->id,
            'video_file_path' => $heldSections[0]->extracted_video_path,
            'asset_disk' => $heldSections[0]->asset_disk,
            'publication_state' => SermonPublicationState::Quarantined,
        ]);
        $this->assertNotEmpty(app(HistoricReleaseReviewHolds::class)->assess([], [$video]));
        Storage::disk($heldSections[0]->extractedAssetDisk())->assertExists($heldSections[0]->extracted_video_path);
        $this->assertSame(ProcessingStatus::Failed, $processingLog->fresh()->status);
        $this->assertSame('manual_review_required', $processingLog->fresh()->current_step);
    }

    /**
     * Audio and video of one section must land on the same disk. Changing only
     * the video's disk would split the pair, and DeleteLivestreamUpload's
     * per-field disk map would then be right about one and wrong about the other.
     */
    #[Test]
    public function it_extracts_publishable_section_media_and_marks_pending_approval(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
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
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';
        // The real extractor writes this file; the mock only returns its path. Speaker
        // identification now checks the media disk before spawning a subprocess, so a
        // section with no file resolves to `missing_audio` and never reaches identify().
        Storage::disk(MediaAssetPath::disk())->put($expectedAudioPath, 'section-audio');

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
        $this->assertSame('matched', $section->metadata['talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertSame('Alice Speaker', $section->metadata['talk_speaker']['reviewed']['preacher_name'] ?? null);
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
            'media-processing.section_publishing.require_high_confidence' => true,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
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
    public function it_flags_ambiguous_talk_speaker_matches_for_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
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
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high'],
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';
        // The real extractor writes this file; the mock only returns its path. Speaker
        // identification now checks the media disk before spawning a subprocess, so a
        // section with no file resolves to `missing_audio` and never reaches identify().
        Storage::disk(MediaAssetPath::disk())->put($expectedAudioPath, 'section-audio');

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
        $this->assertSame('ambiguous', $section->metadata['talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertArrayNotHasKey('reviewed', $section->metadata['talk_speaker'] ?? []);
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
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
            'section_type' => ServiceSectionType::ShortTalk->value,
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
        // The real extractor writes this file; the mock only returns its path. Speaker
        // identification now checks the media disk before spawning a subprocess, so a
        // section with no file resolves to `missing_audio` and never reaches identify().
        Storage::disk(MediaAssetPath::disk())->put($expectedAudioPath, 'section-audio');

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
        $this->assertSame('matched', $section->metadata['talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertSame('Bob Preacher', $section->metadata['talk_speaker']['reviewed']['preacher_name'] ?? null);
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
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
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'metadata' => [
                'confidence_level' => 'high',
                'publication_candidate_extraction' => [
                    'processing_id' => $processingLog->processing_id,
                    'media_signature' => 'stale-signature',
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
            $section->mediaSignature(),
            $section->metadata['publication_candidate_extraction']['media_signature'] ?? null
        );
    }

    /**
     * Confirming a short talk's speaker and type are approval facts: the cut does
     * not depend on them, so the candidate media stamped before them stays valid.
     */
    #[Test]
    public function it_reuses_candidate_media_after_the_speaker_and_talk_type_are_confirmed(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);
        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('public')->put('sermons/sections/kept/video.mp4', 'kept-video');
        Storage::disk('public')->put('sermons/sections/kept/audio.mp3', 'kept-audio');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'asset_disk' => 'public',
            'extracted_video_path' => 'sermons/sections/kept/video.mp4',
            'extracted_audio_path' => 'sermons/sections/kept/audio.mp3',
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);
        $section->metadata = ServiceSectionMetadata::fromArray([
            'confidence_level' => 'high',
            'publication_candidate_extraction' => [
                'processing_id' => $processingLog->processing_id,
                'media_signature' => $section->mediaSignature(),
            ],
            'talk_speaker' => ['reviewed' => ['preacher_id' => null, 'preacher_name' => 'Jane Doe', 'source' => 'manual']],
            'talk_type' => ['proposed' => 'childrens_talk', 'reviewed' => ['value' => 'testimony', 'user_id' => 1, 'at' => now()->toIso8601String()]],
        ]);
        $section->save();

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractSegmentAsFile');
        $videoExtractor->expects($this->never())->method('extractOptimizedAudio');

        (new PrepareSectionPublicationCandidates($processingLog))->handle(
            $videoExtractor,
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class)
        );

        $this->assertSame('sermons/sections/kept/video.mp4', $section->refresh()->extracted_video_path);
    }

    /**
     * A candidate is always cut to the configured candidate disk, so a section
     * still naming the disk a previous promotion moved it to describes bytes that
     * are somewhere else.
     *
     * Found by P8-Q13a step 1: section 2714 on run 1220 had been promoted to
     * `historic_quarantine` on 2026-09-07 and its quarantine copy later went
     * missing. The re-cut wrote the replacement to staging and left `asset_disk`
     * naming quarantine, so `extractedAssetDisk()` kept reading the wrong disk and
     * `HistoricAssetPromotion` failed the whole run with "on neither staging nor
     * quarantine". Same root cause as P8-Q3: the writer used the configured disk
     * and the row went on naming another.
     */
    #[Test]
    public function it_records_the_disk_a_recut_candidate_was_actually_written_to(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('elsewhere');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'fresh-section-video');

        // Promoted to another disk once, and that copy is gone — nothing on
        // `elsewhere` holds the recorded path any more.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'metadata' => ['confidence_level' => 'high'],
            'extracted_video_path' => 'section-publications/gone/video.mp4',
            'extracted_audio_path' => 'section-publications/gone/audio.mp3',
            'asset_disk' => 'elsewhere',
            'start_time' => 120.0,
            'end_time' => 420.0,
        ]);

        $expectedAudioPath = 'section-publications/'.$section->id.'-0123456789abcdef/'.$processingLog->processing_id.'_section_'.$section->id.'.mp3';
        Storage::disk('local')->put($expectedAudioPath, 'fresh-section-audio');

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->method('extractSegmentAsFile')->willReturn('temp/section-video.mp4');
        $videoExtractor->method('extractOptimizedAudio')->willReturn([
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

        $this->assertSame(MediaAssetPath::disk(), $section->extractedAssetDisk());
        $this->assertTrue(
            Storage::disk($section->extractedAssetDisk())->exists((string) $section->extracted_video_path),
            'the row must name the disk the candidate was written to',
        );
    }

    /**
     * A recut is dispatched from a web request, where no staging context is
     * active. Without this the queue payload carries none, the worker resolves
     * `source_file_path` against the plain disk root instead of the run's batch
     * root, and preparation fails with "source video file not found" -- the same
     * failure shape that once blocked historic retries.
     */
    #[Test]
    public function a_standalone_dispatch_enters_the_runs_recorded_historic_staging_context(): void
    {
        Queue::fake();

        $operation = $this->createHistoricImportOperation();
        $context = new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'batches/20260901-canary',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );

        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'historic/source.mp4',
            'processing_metadata' => [
                'historic_import' => ['staging_context' => $context->toArray()],
            ],
        ]);

        $observed = [];
        $registry = new class(app(HistoricStagingGuard::class), $observed) extends HistoricStagingContextRegistry
        {
            /** @param array<int, string> $observed */
            public function __construct(HistoricStagingGuard $guard, public array &$observed)
            {
                parent::__construct($guard);
            }

            public function within(HistoricStagingContext $context, \Closure $callback): mixed
            {
                $this->observed[] = $context->batchRoot;

                return $callback();
            }
        };
        app()->instance(HistoricStagingContextRegistry::class, $registry);

        $this->assertFalse($registry->isActive(), 'A web request must start with no active staging context.');

        PrepareSectionPublicationCandidates::dispatchStandalone($log->fresh());

        $this->assertSame(['batches/20260901-canary'], $registry->observed);
        Queue::assertPushed(PrepareSectionPublicationCandidates::class);
    }

    #[Test]
    public function a_standalone_dispatch_for_an_ordinary_run_needs_no_staging_context(): void
    {
        Queue::fake();

        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        PrepareSectionPublicationCandidates::dispatchStandalone($log);

        Queue::assertPushed(PrepareSectionPublicationCandidates::class);
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);
        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'start_time' => 600.0,
            'end_time' => 760.5,
            'metadata' => [
                'confidence_level' => 'high',
                'talk_speaker' => [
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
        $this->assertTrue($section->hasResolvedTalkSpeaker());
        $this->assertSame(760.5, $section->metadata['short_talk_boundary']['candidate']['end_time'] ?? null);
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
                'short_talk' => TalkPublicationHandler::class,
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
        $this->bankNoWordOutputEdges($processingLog);

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

        $this->bankNoWordOutputEdges($processingLog);
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
            'media-processing.section_publishing.handlers' => ['short_talk' => TalkPublicationHandler::class],
            'media-processing.speaker_identification.enabled' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');
        Storage::disk('local')->put('temp/section-video.mp4', 'section-video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
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
        $this->bankNoWordOutputEdges($processingLog);
    }
}
