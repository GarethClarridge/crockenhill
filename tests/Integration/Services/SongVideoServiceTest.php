<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\Song\SongVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class SongVideoServiceTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private SongVideoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SongVideoService;
    }

    #[Test]
    public function it_generates_a_video_url(): void
    {
        Storage::fake('public');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $video = SongVideo::factory()->create([
            'video_file_path' => 'sermons/songs/1/abc123.mp4',
        ]);

        $url = $this->service->getVideoUrl($video);

        $this->assertStringContainsString('sermons/songs/1/abc123.mp4', $url);
    }

    #[Test]
    public function it_returns_display_video_for_song(): void
    {
        $song = Song::factory()->create();

        $this->assertNull($this->service->getDisplayVideoForSong($song));

        $video = SongVideo::factory()->create([
            'song_id' => $song->id,
            'recorded_date' => '2026-01-15',
        ]);

        $displayVideo = $this->service->getDisplayVideoForSong($song->fresh());

        $this->assertNotNull($displayVideo);
        $this->assertEquals($video->id, $displayVideo->id);
    }

    #[Test]
    public function it_features_a_video_and_unfeatures_others(): void
    {
        $song = Song::factory()->create();

        $video1 = SongVideo::factory()->featured()->create(['song_id' => $song->id]);
        $video2 = SongVideo::factory()->create(['song_id' => $song->id]);

        $this->assertTrue($video1->fresh()->is_featured);
        $this->assertFalse($video2->fresh()->is_featured);

        $this->service->featureVideo($video2);

        $this->assertFalse($video1->fresh()->is_featured);
        $this->assertTrue($video2->fresh()->is_featured);
    }

    #[Test]
    public function it_unfeatures_a_video(): void
    {
        $video = SongVideo::factory()->featured()->create();

        $this->assertTrue($video->is_featured);

        $this->service->unfeatureVideo($video);

        $this->assertFalse($video->fresh()->is_featured);
    }

    #[Test]
    public function it_deletes_a_manual_video_without_resetting_sections(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $videoPath = 'sermons/songs/1/manual-upload.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $video = SongVideo::factory()->manual()->create([
            'video_file_path' => $videoPath,
        ]);

        $this->service->deleteVideo($video);

        $this->assertDatabaseMissing('song_videos', ['id' => $video->id]);
        Storage::disk('public')->assertMissing($videoPath);
    }

    #[Test]
    public function it_deletes_an_auto_extracted_video_and_resets_linked_section(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $videoPath = 'sermons/songs/1/42.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $video = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => $videoPath,
        ]);

        $this->service->deleteVideo($video);

        $this->assertDatabaseMissing('song_videos', ['id' => $video->id]);
        Storage::disk('public')->assertMissing($videoPath);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull($section->published_at);
    }

    #[Test]
    public function it_does_not_reset_section_that_is_not_published(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $videoPath = 'sermons/songs/1/42.mp4';
        Storage::disk('public')->put($videoPath, 'video-content');

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $video = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => $videoPath,
        ]);

        $this->service->deleteVideo($video);

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
    }

    #[Test]
    public function it_creates_a_video_from_upload_with_ulid_path(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $file = UploadedFile::fake()->create('my-song.mp4', 5000, 'video/mp4');

        $video = $this->service->createFromUpload($song, $file);

        $this->assertEquals($song->id, $video->song_id);
        $this->assertFalse($video->is_featured);
        $this->assertNull($video->service_section_id);
        $this->assertStringStartsWith('sermons/songs/'.$song->id.'/', $video->video_file_path);
        $this->assertStringEndsWith('.mp4', $video->video_file_path);
        Storage::disk('public')->assertExists($video->video_file_path);
    }

    #[Test]
    public function it_creates_a_video_from_extraction(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-15',
        ]);

        $song = Song::factory()->create();

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
            'start_time' => 100.0,
            'end_time' => 340.5,
        ]);

        $videoPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';

        $video = $this->service->createFromExtraction($section, $videoPath);

        $this->assertEquals($song->id, $video->song_id);
        $this->assertEquals($section->id, $video->service_section_id);
        $this->assertEquals($churchService->id, $video->church_service_id);
        $this->assertEquals($videoPath, $video->video_file_path);
        $this->assertEquals($section->duration, $video->duration);
        $this->assertEquals('2026-03-15', $video->recorded_date->toDateString());
        $this->assertFalse($video->is_featured);
    }

    #[Test]
    public function historic_extraction_creates_a_private_operation_bound_video(): void
    {
        $operation = $this->createHistoricImportOperation();
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');

        $churchService = ChurchService::factory()->create(['date' => '2026-03-15']);
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'historic_import_operation_id' => $operation->id,
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $video = $this->service->createFromExtraction($section, 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4');

        $this->assertSame(SermonPublicationState::Quarantined, $video->publication_state);
        $this->assertSame('historic_staging', $video->asset_disk);
        $this->assertSame($operation->id, $video->historic_import_operation_id);
    }

    #[Test]
    public function it_resolves_a_recorded_asset_disk_after_global_storage_changes(): void
    {
        Storage::fake('recorded_media');
        Storage::fake('public');
        config()->set('media-processing.storage.sermon_disk', 'public');
        $recordedDisk = Storage::disk('recorded_media');
        Storage::shouldReceive('disk')->with('recorded_media')->once()->andReturn($recordedDisk);

        $path = 'sermons/songs/1/recorded.mp4';
        $video = SongVideo::factory()->create([
            'video_file_path' => $path,
            'asset_disk' => 'recorded_media',
        ]);

        $url = $this->service->getVideoUrl($video);

        $this->assertStringContainsString($path, $url);
    }

    #[Test]
    public function it_deletes_a_video_from_its_recorded_asset_disk(): void
    {
        Storage::fake('recorded_media');
        Storage::fake('public');
        config()->set('media-processing.storage.sermon_disk', 'public');

        $path = 'sermons/songs/1/recorded.mp4';
        Storage::disk('recorded_media')->put($path, 'video-content');
        $video = SongVideo::factory()->create([
            'video_file_path' => $path,
            'asset_disk' => 'recorded_media',
        ]);

        $this->service->deleteVideo($video);

        Storage::disk('recorded_media')->assertMissing($path);
        $this->assertDatabaseMissing('song_videos', ['id' => $video->id]);
    }
}
