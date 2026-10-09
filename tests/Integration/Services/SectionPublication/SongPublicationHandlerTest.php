<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\AudioProfile;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongVideoService;
use App\Support\MediaProcessingVersion;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PublicationCandidateFixture;
use Tests\TestCase;

class SongPublicationHandlerTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);
        $this->handler = $this->handlerMeasuringClips();
    }

    /**
     * A handler whose clip-length probe answers `$clipSeconds`, or cannot measure
     * anything when null (the binaries are unavailable under `testing`).
     */
    private function handlerMeasuringClips(?float $clipSeconds = null): SongPublicationHandler
    {
        $ffprobe = null;

        if ($clipSeconds !== null) {
            $format = $this->createStub(Format::class);
            $format->method('get')->willReturn($clipSeconds);
            $ffprobe = $this->createStub(FFProbe::class);
            $ffprobe->method('format')->willReturn($format);
        }

        return new SongPublicationHandler(
            app(SongVideoService::class),
            app(ServiceSectionPublicationTransitionService::class),
            app(StorageAdapterHelper::class),
            app(SongPublicationReviewPolicy::class),
            new ExtractedMediaDurationProbe(app(StorageAdapterHelper::class), $ffprobe),
        );
    }

    /**
     * Build a publishable ServiceSection with a linked song and video path.
     */
    private function makePublishableSection(Song $song, string $videoPath): ServiceSection
    {
        $churchService = ChurchService::factory()->create(['date' => '2026-03-15']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => 'section-publications/audio.mp3',
            'extracted_at' => now(),
            'start_time' => 60.0,
            'end_time' => 240.0,
        ]);
        $this->storeCleanBoundaryArtifacts($section);
        $this->markCutUnderCurrentProcessing($section);

        return $section->fresh();
    }

    /** The candidate carries the sound that publishes only when it was cut under the current processing (§6.3). */
    private function markCutUnderCurrentProcessing(ServiceSection $section): void
    {
        $section->refresh();
        $section->update(['metadata' => [
            ...($section->metadata?->toArray() ?? []),
            'publication_candidate_extraction' => $this->candidate(),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function candidate(array $overrides = [], array $report = []): array
    {
        return PublicationCandidateFixture::current(AudioProfile::Music, $overrides, $report);
    }

    /** @param  array<string, mixed>  $candidate */
    private function recut(ServiceSection $section, string $videoPath, array $candidate): ServiceSection
    {
        $section->refresh();
        $section->update(['extracted_video_path' => $videoPath, 'metadata' => [
            ...($section->metadata?->toArray() ?? []),
            'publication_candidate_extraction' => $candidate,
        ]]);

        return $section->fresh();
    }

    #[Test]
    public function it_does_not_require_audio_extraction(): void
    {
        $this->assertFalse($this->handler->requiresAudioExtraction());
    }

    #[Test]
    public function it_does_not_require_approval_for_a_whole_song(): void
    {
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/whole-song.mp4');
        $section->forceFill(['start_time' => 600.0, 'end_time' => 840.0, 'duration' => 240.0])->save();

        $section = $section->fresh();

        $this->assertFalse($this->handler->requiresApproval($section));
        $this->assertSame(
            'retain_inclusive_candidate',
            $section->metadata->toArray()['song_publication_boundary']['action'],
        );
    }

    #[Test]
    public function it_clears_a_stale_song_review_when_the_current_boundary_is_clean(): void
    {
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/whole-song.mp4');
        $section->forceFill([
            'start_time' => 600.0,
            'end_time' => 840.0,
            'duration' => 240.0,
            'metadata' => [
                'song_publication_review' => [
                    'reasons' => [['kind' => 'song_boundary_spoken_framing', 'detail' => 'stale']],
                ],
            ],
        ])->save();

        $section = $section->fresh();

        $this->assertFalse($this->handler->requiresApproval($section));
        $this->assertArrayNotHasKey('song_publication_review', $section->metadata->toArray());
    }

    /**
     * The pilot published a 20-second clip whose own notes recorded that the
     * transcript held the hymn's introduction and no sung lyrics.
     */
    #[Test]
    public function it_requires_approval_for_a_clip_too_short_to_be_a_whole_song(): void
    {
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/short-song.mp4');
        $section->forceFill(['start_time' => 515.0, 'end_time' => 534.95, 'duration' => 19.95])->save();
        $this->storeCleanBoundaryArtifacts($section);
        $section = $section->fresh();

        $this->assertTrue($this->handler->requiresApproval($section));
        // The duration gate holds it; the content rule says why, since its words are not the song.
        $this->assertSame(
            ['short_song_clip', 'song_section_without_song'],
            array_column($section->metadata->toArray()['song_publication_review']['reasons'], 'kind'),
        );
    }

    /**
     * Two contiguous sections of one recording resolved to one song: the pilot
     * published a 224-second hymn and then the 23-second doxology that followed
     * it, both as the same song.
     */
    #[Test]
    public function it_requires_approval_for_an_adjacent_section_of_the_same_song(): void
    {
        $song = Song::factory()->create();
        $first = $this->makePublishableSection($song, 'sections/first.mp4');
        $first->forceFill(['start_time' => 1729.38, 'end_time' => 1953.83, 'duration' => 224.45])->save();
        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $first->media_processing_log_id,
            'church_service_item_id' => $first->church_service_item_id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => 'sections/second.mp4',
            'start_time' => 1953.83,
            'end_time' => 2153.83,
            'duration' => 200.0,
        ]);
        $this->storeCleanBoundaryArtifacts($second);

        $this->assertTrue($this->handler->requiresApproval($second->fresh()));
        $this->assertTrue($this->handler->requiresApproval($first->fresh()));
    }

    #[Test]
    public function it_checks_reusable_media_requires_only_video(): void
    {
        Storage::fake('public');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_reusable_media_passes_with_video_only(): void
    {
        Storage::fake('public');

        $videoPath = 'section-publications/1-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => null,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_is_eligible_when_song_match_is_confirmed_and_song_id_present(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->isEligible($section));
    }

    #[Test]
    public function it_is_eligible_for_review_when_song_match_is_inferred_and_song_id_present(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->isEligible($section));
    }

    #[Test]
    public function it_requires_approval_for_an_inferred_song_match(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'start_time' => 600.0,
            'end_time' => 840.0,
        ]);
        $this->storeCleanBoundaryArtifacts($section);

        $this->assertTrue($this->handler->requiresApproval($section));
        $this->assertSame(
            ['inferred_song_match'],
            array_column($section->metadata->toArray()['song_publication_review']['reasons'], 'kind'),
        );
    }

    /**
     * The Phase 8 pass generated single-song clips over intervals OCR had
     * already shown to hold two songs (sections 1276 and 3869). The reason must
     * reach the handler, or naming it changes nothing.
     */
    #[Test]
    public function it_requires_approval_for_an_interval_holding_a_second_song(): void
    {
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/two-songs.mp4');
        $section->forceFill([
            'start_time' => 600.0,
            'end_time' => 1058.71,
            'duration' => 458.71,
            'metadata' => [
                'additional_song_matches' => [[
                    'song_id' => Song::factory()->create()->id,
                    'title' => 'Where, O grave, is your victory?',
                    'confidence' => 0.92,
                    'match_source' => 'ocr',
                ]],
            ],
        ])->save();
        $this->storeCleanBoundaryArtifacts($section);

        $section = $section->fresh();

        $this->assertTrue($this->handler->requiresApproval($section));
        $this->assertSame(
            ['unresolved_multiple_songs'],
            array_column($section->metadata->toArray()['song_publication_review']['reasons'], 'kind'),
        );
        $this->assertSame(
            ['Where, O grave, is your victory?'],
            array_column($section->metadata->toArray()['additional_song_matches'], 'title'),
            'The second match is evidence of the service and must survive the hold.',
        );
    }

    #[Test]
    public function it_does_not_rewrite_song_review_timestamps_when_boundary_inputs_are_unchanged(): void
    {
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/short-song.mp4');
        $section->forceFill(['start_time' => 515.0, 'end_time' => 534.95, 'duration' => 19.95])->save();
        $this->storeCleanBoundaryArtifacts($section);

        $section = $section->fresh();
        $this->handler->requiresApproval($section);
        if ($section->isDirty()) {
            $section->save();
        }
        $section->refresh();
        $firstMetadata = $section->metadata?->toArray();
        $firstUpdatedAt = $section->updated_at?->toISOString();

        $this->travel(1)->minute();

        $section = $section->fresh();
        $this->handler->requiresApproval($section);
        $this->assertSame([], $section->getDirty());
        if ($section->isDirty()) {
            $section->save();
        }

        $this->assertSame($firstMetadata, $section->fresh()->metadata?->toArray());
        $this->assertSame($firstUpdatedAt, $section->fresh()->updated_at?->toISOString());
    }

    #[Test]
    public function it_is_not_eligible_when_song_match_is_unmatched(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->handler->isEligible($section));
    }

    #[Test]
    public function it_is_not_eligible_when_song_id_is_null(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'song_id' => null,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->handler->isEligible($section));
    }

    #[Test]
    public function it_is_not_eligible_without_a_church_service_item(): void
    {
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->handler->isEligible($section));
    }

    #[Test]
    public function it_publishes_a_song_section_and_creates_song_video(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/99-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'extracted-video-content');

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull($section->published_at);
        $this->assertNull($section->unpublished_expires_at);

        $expectedPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';
        $this->assertEquals($expectedPath, $section->extracted_video_path);
        Storage::disk('public')->assertExists($expectedPath);

        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->first();
        $this->assertNotNull($songVideo);
        $this->assertEquals($song->id, $songVideo->song_id);
        $this->assertEquals($expectedPath, $songVideo->video_file_path);
        $this->assertEquals($section->duration, $songVideo->duration);
        $this->assertEquals('2026-03-15', $songVideo->recorded_date->toDateString());
    }

    #[Test]
    public function a_published_song_video_records_its_measured_clip_length(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/99-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'extracted-video-content');
        $section = $this->makePublishableSection($song, $videoPath);

        $this->handlerMeasuringClips(238.37)->publish($section);

        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->firstOrFail();
        $this->assertSame(238.37, $songVideo->duration);
    }

    #[Test]
    public function it_skips_publish_when_song_video_already_exists(): void
    {
        Log::spy();

        Storage::fake('public');

        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => 'section-publications/99-abcdef0123456789/video.mp4',
            'extracted_audio_path' => null,
            'extracted_at' => now(),
        ]);

        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $section->id,
        ]);

        $candidate = $this->candidate();
        $section->update(['metadata' => [
            'publication_candidate_extraction' => $candidate,
            'song_video_extraction' => ['media_signature' => $section->mediaSignature(), 'candidate_id' => $candidate['candidate_id']],
        ]]);
        $this->handler->publish($section);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'already exists'));

        $this->assertCount(1, SongVideo::query()->where('service_section_id', $section->id)->get());
    }

    #[Test]
    public function a_version_change_regenerates_the_song_video_and_preserves_its_identity_and_feature(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/fresh.mp4');
        Storage::disk('public')->put('sections/fresh.mp4', 'regenerated-content');
        $section->update(['publication_status' => ServiceSectionPublicationStatus::Published, 'published_at' => now(),
            'metadata' => ['song_video_extraction' => ['media_signature' => $section->mediaSignature()]]]);
        $existing = SongVideo::factory()->create(['song_id' => $song->id, 'service_section_id' => $section->id, 'is_featured' => true]);
        $nextVersion = (int) config('media-processing.media_processing_version') + 1;
        config(['media-processing.media_processing_version' => $nextVersion]);
        // Candidate preparation re-cuts it under the new processing before publication runs.
        $this->markCutUnderCurrentProcessing($section);

        $this->handler->publish($section->fresh());

        $this->assertSame(1, SongVideo::query()->where('service_section_id', $section->id)->count());
        $this->assertTrue($existing->fresh()->is_featured);
        $this->assertSame('regenerated-content', Storage::disk('public')->get($existing->fresh()->video_file_path));
        $this->assertSame($section->mediaSignature(), $section->fresh()->metadata->raw['song_video_extraction']['media_signature']);
        $this->assertSame($nextVersion, $section->fresh()->metadata->raw['song_video_extraction']['media_processing']['version']);
    }

    #[Test]
    public function on_section_removed_cleans_up_song_video(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        Log::spy();

        $videoPath = 'sermons/songs/1/42.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $songVideo = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => $videoPath,
        ]);

        $this->handler->onSectionRemoved($section);

        $this->assertDatabaseMissing('song_videos', ['id' => $songVideo->id]);
        Storage::disk('public')->assertMissing($videoPath);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'cleaned up SongVideo'));
    }

    #[Test]
    public function on_section_removed_does_nothing_when_no_song_video_exists(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->handler->onSectionRemoved($section);

        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function after_extraction_is_a_noop(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->expectNotToPerformAssertions();

        $this->handler->afterExtraction($section);
    }

    private function storeCleanBoundaryArtifacts(ServiceSection $section): void
    {
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

    // ---- The candidate's sound is the published sound (§6.3) ----

    #[Test]
    public function publish_promotes_the_candidate_unchanged_and_removes_it_from_the_candidate_disk(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/1-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'candidate-video-content');

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $expectedPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';
        $this->assertEquals($expectedPath, $section->extracted_video_path);
        $this->assertEquals('candidate-video-content', Storage::disk('public')->get($expectedPath));
        Storage::disk('public')->assertMissing($videoPath);
    }

    #[Test]
    public function publish_refuses_a_candidate_cut_under_older_processing(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/2-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'untreated-video-content');
        $section = $this->makePublishableSection($song, $videoPath);
        $section->update(['metadata' => [
            ...($section->metadata?->toArray() ?? []),
            'publication_candidate_extraction' => ['media_processing' => [...MediaProcessingVersion::signature(), 'version' => 6]],
        ]]);

        try {
            $this->handler->publish($section->fresh());
            $this->fail('A candidate cut before the sound was treated must not publish.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('older media processing', $exception->getMessage());
        }

        $this->assertSame(0, SongVideo::query()->where('service_section_id', $section->id)->count());
        Storage::disk('public')->assertExists($videoPath);
    }

    #[Test]
    public function publish_refuses_a_candidate_with_no_record_of_its_processing(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/3-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');
        $section = $this->makePublishableSection($song, $videoPath);
        $metadata = $section->metadata?->toArray() ?? [];
        unset($metadata['publication_candidate_extraction']);
        $section->update(['metadata' => $metadata]);

        $this->expectException(\RuntimeException::class);

        $this->handler->publish($section->fresh());
    }

    #[Test]
    public function the_transcription_enhancement_settings_do_not_touch_publication(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public', 'media-processing.audio_enhancement.enabled' => false]);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/4-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');
        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame('video-content', Storage::disk('public')->get($section->extracted_video_path));
    }

    // ---- Publication follows the candidate the section holds now ----

    #[Test]
    public function a_recut_under_a_new_recording_override_replaces_the_published_sound(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/old.mp4');
        Storage::disk('public')->put('sections/old.mp4', 'old-sound');
        $this->handler->publish($section);
        $published = SongVideo::query()->where('service_section_id', $section->id)->firstOrFail();

        $section->processingLog->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'audio_treatment_overrides' => ['music' => ['target_lufs' => -18.0]]]);
        Storage::disk('public')->put('sections/new.mp4', 'new-sound');
        $recut = $this->recut($section, 'sections/new.mp4', $this->candidate(['target_lufs' => -18.0]));

        $this->assertFalse($this->handler->isPublishedFromCurrentCandidate($recut));
        $this->handler->publish($recut);

        $this->assertSame('new-sound', Storage::disk('public')->get($published->fresh()->video_file_path));
        $this->assertTrue($this->handler->isPublishedFromCurrentCandidate($section->fresh()));
    }

    #[Test]
    public function a_recut_with_unchanged_bounds_and_settings_still_replaces_the_published_clip(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/first.mp4');
        Storage::disk('public')->put('sections/first.mp4', 'first-cut');
        $this->handler->publish($section);
        Storage::disk('public')->put('sections/second.mp4', 'edges-moved');

        $this->handler->refreshPublished($this->recut($section, 'sections/second.mp4', $this->candidate()));

        $published = SongVideo::query()->where('service_section_id', $section->id)->sole();
        $this->assertSame('edges-moved', Storage::disk('public')->get($published->video_file_path));
    }

    #[Test]
    public function a_candidate_cut_before_the_recording_override_changed_is_refused(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/pending.mp4');
        Storage::disk('public')->put('sections/pending.mp4', 'old-sound');
        $section->processingLog->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'audio_treatment_overrides' => ['music' => ['target_lufs' => -18.0]]]);

        try {
            $this->handler->publish($section->fresh());
            $this->fail('A candidate cut with the old settings must not publish.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('other audio settings', $exception->getMessage());
        }

        $this->assertSame(0, SongVideo::query()->where('service_section_id', $section->id)->count());
        Storage::disk('public')->assertExists('sections/pending.mp4');
    }

    #[Test]
    public function settings_stored_as_json_integers_still_match(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/json.mp4');
        Storage::disk('public')->put('sections/json.mp4', 'video-content');
        $candidate = $this->candidate();
        $candidate['audio_treatment']['settings']['target_lufs'] = -16;

        $this->handler->publish($this->recut($section, 'sections/json.mp4', $candidate));

        $this->assertSame(1, SongVideo::query()->where('service_section_id', $section->id)->count());
    }

    #[Test]
    public function an_untreated_song_requires_approval(): void
    {
        $section = $this->makePublishableSection(Song::factory()->create(), 'sections/whole-song.mp4');
        $section = $this->recut($section, 'sections/whole-song.mp4', $this->candidate(report: [
            'parts' => [['untreated_reason' => 'too_quiet', 'measured' => null]],
        ]));
        $section->forceFill(['start_time' => 600.0, 'end_time' => 840.0, 'duration' => 240.0])->save();

        $this->assertTrue($this->handler->requiresApproval($section));
        $this->assertSame([SongPublicationReviewPolicy::SOUND_UNTREATED], array_column($section->metadata->raw['song_publication_review']['reasons'], 'kind'));
    }

    #[Test]
    public function a_song_that_missed_its_loudness_target_requires_approval(): void
    {
        $section = $this->makePublishableSection(Song::factory()->create(), 'sections/whole-song.mp4');
        $section = $this->recut($section, 'sections/whole-song.mp4', $this->candidate(report: [
            'loudness_misses' => ['part 1 video integrated -18.2 LUFS, target -16.0 ±1.0'],
        ]));
        $section->forceFill(['start_time' => 600.0, 'end_time' => 840.0, 'duration' => 240.0])->save();

        $this->assertTrue($this->handler->requiresApproval($section));
        $reason = $section->metadata->raw['song_publication_review']['reasons'][0];
        $this->assertSame(SongPublicationReviewPolicy::LOUDNESS_MISSED, $reason['kind']);
        $this->assertStringContainsString('-18.2 LUFS', $reason['detail']);
    }

    #[Test]
    public function a_published_song_keeps_its_clip_when_the_recut_sound_needs_review(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $song = Song::factory()->create();
        $section = $this->makePublishableSection($song, 'sections/good.mp4');
        $section->forceFill(['start_time' => 600.0, 'end_time' => 840.0, 'duration' => 240.0])->save();
        Storage::disk('public')->put('sections/good.mp4', 'good-sound');
        $this->handler->publish($section->fresh());
        Storage::disk('public')->put('sections/missed.mp4', 'off-target-sound');

        $recut = $this->recut($section, 'sections/missed.mp4', $this->candidate(report: [
            'loudness_misses' => ['part 1 video integrated -19.0 LUFS, target -16.0 ±1.0'],
        ]));
        $this->handler->refreshPublished($recut);

        $published = SongVideo::query()->where('service_section_id', $section->id)->sole();
        $this->assertSame('good-sound', Storage::disk('public')->get($published->video_file_path));
        Storage::disk('public')->assertExists('sections/missed.mp4');
        $this->assertSame([SongPublicationReviewPolicy::LOUDNESS_MISSED], array_column($recut->metadata->raw['song_publication_review']['reasons'], 'kind'));
    }

    #[Test]
    public function review_refresh_does_not_replace_a_song_with_new_content_doubts(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $section = $this->makePublishableSection(Song::factory()->create(), 'sections/old.mp4');
        $section->forceFill(['start_time' => 600.0, 'end_time' => 840.0, 'duration' => 240.0])->save();
        Storage::disk('public')->put('sections/old.mp4', 'accepted-song');
        $this->handler->publish($section->fresh());
        Storage::disk('public')->put('sections/new.mp4', 'short-fragment');
        $section->refresh()->forceFill(['end_time' => 640.0, 'duration' => 40.0])->save();
        $recut = $this->recut($section, 'sections/new.mp4', $this->candidate());
        $this->assertTrue($this->handler->requiresApproval($recut));
        $this->handler->refreshPublished($recut);
        $video = SongVideo::query()->where('service_section_id', $section->id)->sole();
        $this->assertSame('accepted-song', Storage::disk('public')->get($video->video_file_path));
    }
}
