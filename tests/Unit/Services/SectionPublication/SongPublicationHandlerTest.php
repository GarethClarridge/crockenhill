<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SectionPublication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\SectionPublication\SongPublicationHandler;
use App\Services\ServiceSectionPublicationTransitionService;
use App\Services\SongVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongPublicationHandlerTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new SongPublicationHandler(
            app(SongVideoService::class),
            app(ServiceSectionPublicationTransitionService::class),
        );
    }

    #[Test]
    public function it_does_not_require_audio_extraction(): void
    {
        $this->assertFalse($this->handler->requiresAudioExtraction());
    }

    #[Test]
    public function it_does_not_require_approval(): void
    {
        $this->assertFalse($this->handler->requiresApproval());
    }

    #[Test]
    public function it_checks_reusable_media_requires_only_video(): void
    {
        Storage::fake('local');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertFalse($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_reusable_media_passes_with_video_only(): void
    {
        Storage::fake('local');

        $videoPath = 'private/section-publications/1/video.mp4';
        Storage::disk('local')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => null,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertTrue($this->handler->isEligible($section));
    }

    #[Test]
    public function it_is_not_eligible_when_song_match_is_inferred(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::INFERRED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertFalse($this->handler->isEligible($section));
    }

    #[Test]
    public function it_is_not_eligible_without_a_church_service_item(): void
    {
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertFalse($this->handler->isEligible($section));
    }

    #[Test]
    public function it_publishes_a_song_section_and_creates_song_video(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $churchService = ChurchService::factory()->create(['date' => '2026-03-15']);
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $videoPath = 'private/section-publications/99/video.mp4';
        Storage::disk('local')->put($videoPath, 'extracted-video-content');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => null,
            'extracted_at' => now(),
            'start_time' => 60.0,
            'end_time' => 240.0,
        ]);

        $this->handler->publish($section);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);
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

        Storage::fake('local');

        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => 'private/section-publications/99/video.mp4',
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
            'section_type' => ServiceSectionType::SONG->value,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->handler->onSectionRemoved($section);

        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function after_extraction_is_a_noop(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::SONG->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        // Should not throw or do anything.
        $this->handler->afterExtraction($section);
        $this->assertTrue(true);
    }
}
