<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

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
use App\Services\Media\Audio\AudioEnhancementService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongPublicationHandlerTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationHandler $handler;

    /** @var AudioEnhancementService&MockInterface */
    private AudioEnhancementService $audioEnhancement;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);
        $this->audioEnhancement = $this->mock(AudioEnhancementService::class);

        $this->handler = new SongPublicationHandler(
            app(SongVideoService::class),
            app(ServiceSectionPublicationTransitionService::class),
            $this->audioEnhancement,
            app(StorageAdapterHelper::class),
            app(SongPublicationReviewPolicy::class),
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
        $this->assertSame(
            ['short_song_clip'],
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

        // Enhancement disabled (returns null) → fallback to original clip promotion.
        $this->audioEnhancement->shouldReceive('enhanceVideo')->andReturn(null);

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

        $this->handler->publish($section);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'already exists'));

        $this->assertCount(1, SongVideo::query()->where('service_section_id', $section->id)->get());
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

    // ---- Enhancement integration tests ----

    #[Test]
    public function publish_promotes_enhanced_video_when_enhancement_succeeds(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/1-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'original-video-content');

        // Create a real temp file as the "enhanced" output so promoteLocalFileAsVideo can stream it.
        $enhancedTempPath = tempnam(sys_get_temp_dir(), 'enhanced_').'.mp4';
        file_put_contents($enhancedTempPath, 'enhanced-video-content');

        $this->audioEnhancement->shouldReceive('enhanceVideo')->andReturn($enhancedTempPath);

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $expectedPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';
        $this->assertEquals($expectedPath, $section->extracted_video_path);

        // The published content should be the enhanced video, not the original.
        Storage::disk('public')->assertExists($expectedPath);
        $this->assertEquals('enhanced-video-content', Storage::disk('public')->get($expectedPath));

        // The original extracted clip should be removed from the source disk.
        Storage::disk('public')->assertMissing($videoPath);

        // Temp file should have been cleaned up by the finally block.
        $this->assertFileDoesNotExist($enhancedTempPath);
    }

    #[Test]
    public function publish_promotes_original_clip_when_enhancement_returns_null(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/2-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'original-video-content');

        $this->audioEnhancement->shouldReceive('enhanceVideo')->andReturn(null);

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $expectedPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';
        $this->assertEquals($expectedPath, $section->extracted_video_path);

        Storage::disk('public')->assertExists($expectedPath);
        $this->assertEquals('original-video-content', Storage::disk('public')->get($expectedPath));
    }

    #[Test]
    public function publish_cleans_up_enhanced_temp_file_after_promotion(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/3-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $enhancedTempPath = tempnam(sys_get_temp_dir(), 'cleanup_test_').'.mp4';
        file_put_contents($enhancedTempPath, 'enhanced-content');

        $this->audioEnhancement->shouldReceive('enhanceVideo')->andReturn($enhancedTempPath);

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        // The finally block must have deleted the enhanced temp file.
        $this->assertFileDoesNotExist($enhancedTempPath);
    }

    #[Test]
    public function publish_is_unchanged_when_audio_enhancement_is_disabled(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        // Simulate enhancement being disabled by having it return null.
        $this->audioEnhancement->shouldReceive('enhanceVideo')->andReturn(null);

        $song = Song::factory()->create();
        $videoPath = 'section-publications/4-abcdef0123456789/video.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = $this->makePublishableSection($song, $videoPath);

        $this->handler->publish($section);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull($section->published_at);

        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->first();
        $this->assertNotNull($songVideo);
    }
}
