<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\SermonMetadataIntegrationService;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
/** @method mixed shouldReceive(...$args) */
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonMetadataIntegrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonMetadataIntegrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SermonMetadataIntegrationService::class);
    }

    // --- getSegmentDuration() ---

    #[Test]
    public function it_returns_segment_duration_for_livestream_sermon(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => 120.0,
            'segment_end_time' => 3720.0,
        ]);

        $this->assertEquals(3600.0, $this->service->getSegmentDuration($sermon));
    }

    #[Test]
    public function it_returns_null_segment_duration_for_non_livestream_sermon(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Manual,
            'segment_start_time' => 120.0,
            'segment_end_time' => 3720.0,
        ]);

        $this->assertNull($this->service->getSegmentDuration($sermon));
    }

    #[Test]
    public function it_returns_null_segment_duration_when_times_missing(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => null,
            'segment_end_time' => null,
        ]);

        $this->assertNull($this->service->getSegmentDuration($sermon));
    }

    // --- getSegmentDurationFormatted() ---

    #[Test]
    public function it_returns_formatted_segment_duration(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => 60.0,
            'segment_end_time' => 2790.0, // 2730s = 45m 30s
        ]);

        $this->assertEquals('45m 30s', $this->service->getSegmentDurationFormatted($sermon));
    }

    // --- getLivestreamInfo() ---

    #[Test]
    public function it_returns_livestream_info_array_for_livestream_sermon(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => 60.0,
            'segment_end_time' => 3660.0,
            'video_file_path' => null,
        ]);

        $info = $this->service->getLivestreamInfo($sermon);

        $this->assertArrayHasKey('processing_id', $info);
        $this->assertArrayHasKey('segment_start_time', $info);
        $this->assertArrayHasKey('segment_end_time', $info);
        $this->assertArrayHasKey('segment_duration', $info);
        $this->assertArrayHasKey('segment_duration_formatted', $info);
        $this->assertArrayHasKey('has_video', $info);
        $this->assertArrayHasKey('video_url', $info);
        $this->assertEquals(3600.0, $info['segment_duration']);
    }

    #[Test]
    public function it_returns_empty_array_for_non_livestream_sermon(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Manual,
        ]);

        $this->assertEquals([], $this->service->getLivestreamInfo($sermon));
    }

    // --- storeVideoForSermon() ---

    #[Test]
    public function it_extracts_video_using_fallback_search_logic(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        $processingId = 'fallback-test-proc';
        MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => $processingId,
            'video_file_path' => null, // No path in log
        ]);

        $tempPath = "temp/livestreams/{$processingId}/segments/sermon.mp4";
        $videoContent = "\x00\x00\x00\x18ftypmp42";
        Storage::disk('local')->put($tempPath, $videoContent);

        $finalPath = $this->service->storeVideoForSermon($processingId, $sermon->id);

        $this->assertSame("sermons/{$sermon->id}/video.mp4", $finalPath);
        Storage::disk('public')->assertExists($finalPath);
    }

    #[Test]
    public function it_extracts_video_from_sermon_disk_if_already_moved(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        $processingId = 'moved-test-proc';
        $relativePath = 'temp/sermon-video.mp4';

        MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => $processingId,
            'video_file_path' => $relativePath,
        ]);

        $videoContent = "\x00\x00\x00\x18ftypmp42";
        Storage::disk('public')->put($relativePath, $videoContent);

        $finalPath = $this->service->storeVideoForSermon($processingId, $sermon->id);

        $this->assertSame("sermons/{$sermon->id}/video.mp4", $finalPath);
    }

    #[Test]
    public function it_throws_exception_when_no_video_is_found_during_storage(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'missing-video-proc',
            'video_file_path' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No sermon video found for processing ID: missing-video-proc');

        $this->service->storeVideoForSermon('missing-video-proc', $sermon->id);
    }

    #[Test]
    public function it_persists_the_sermon_video_to_permanent_storage(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'video_file_path' => 'temp/sermon-video.mp4',
        ]);

        // Use valid MP4 content to satisfy integrated validateVideoFile call
        Storage::disk('local')->put('temp/sermon-video.mp4', "\x00\x00\x00\x18ftypmp42");

        $finalPath = $this->service->storeVideoForSermon($log->processing_id, $sermon->id);

        $this->assertSame("sermons/{$sermon->id}/video.mp4", $finalPath);
        Storage::disk('public')->assertExists($finalPath);
    }

    // --- linkVideoToSermon() ---

    #[Test]
    public function it_links_video_to_sermon_record(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_start_time' => 120.5,
            'sermon_end_time' => 3600.0,
        ]);

        $this->service->linkVideoToSermon($log->processing_id, $sermon->id, 'sermons/1/video.mp4');

        $sermon->refresh();
        $this->assertEquals($log->processing_id, $sermon->livestream_processing_id);
        $this->assertEquals('sermons/1/video.mp4', $sermon->video_file_path);
        $this->assertEquals(SermonSourceType::Livestream, $sermon->source_type);
    }

    #[Test]
    public function it_updates_processing_log_with_sermon_link(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->service->linkVideoToSermon($log->processing_id, $sermon->id, 'sermons/1/video.mp4');

        $log->refresh();
        $this->assertEquals($sermon->id, $log->sermon_id);
        $this->assertEquals('sermons/1/video.mp4', $log->video_file_path);
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_processing_id(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->linkVideoToSermon('nonexistent-id', $sermon->id, 'sermons/1/video.mp4');
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_sermon_id(): void
    {
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->linkVideoToSermon($log->processing_id, 99999, 'sermons/1/video.mp4');
    }

    // --- getVideoInfo() ---

    #[Test]
    public function it_returns_no_video_info_for_nonexistent_sermon(): void
    {
        $info = $this->service->getVideoInfo(99999);

        $this->assertFalse($info['has_video']);
    }

    #[Test]
    public function it_returns_no_video_info_for_sermon_without_video(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['video_file_path' => null]);

        $info = $this->service->getVideoInfo($sermon->id);

        $this->assertFalse($info['has_video']);
    }

    #[Test]
    public function it_returns_video_info_for_sermon_with_video(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'source_type' => SermonSourceType::VideoUpload,
        ]);

        $info = $this->service->getVideoInfo($sermon->id);

        $this->assertTrue($info['has_video']);
        $this->assertEquals(SermonSourceType::VideoUpload, $info['source_type']);
        $this->assertArrayHasKey('video_path', $info);
    }

    // --- getVideoPreviewData() ---

    #[Test]
    public function it_returns_no_preview_for_nonexistent_sermon(): void
    {
        $preview = $this->service->getVideoPreviewData(99999);

        $this->assertFalse($preview['has_video']);
    }

    #[Test]
    public function it_returns_no_preview_for_sermon_without_video(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['video_file_path' => null]);

        $preview = $this->service->getVideoPreviewData($sermon->id);

        $this->assertFalse($preview['has_video']);
    }

    #[Test]
    public function it_returns_preview_data_with_format(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $preview = $this->service->getVideoPreviewData($sermon->id);

        $this->assertTrue($preview['has_video']);
        $this->assertEquals('mp4', $preview['format']);
        $this->assertArrayHasKey('file_size', $preview);
        $this->assertArrayHasKey('file_size_formatted', $preview);
    }

    // --- cleanupTemporaryVideoFiles() ---

    #[Test]
    public function it_cleans_up_temporary_video_files(): void
    {
        Storage::fake();
        Storage::put('temp/livestreams/test-123/segments/sermon.mp4', 'video data');

        $this->service->cleanupTemporaryVideoFiles('test-123');

        Storage::assertMissing('temp/livestreams/test-123');
    }

    #[Test]
    public function it_handles_cleanup_when_directory_does_not_exist(): void
    {
        Storage::fake();

        $this->expectNotToPerformAssertions();

        $this->service->cleanupTemporaryVideoFiles('nonexistent-id');
    }

    // --- validateVideoFile() (local files) ---

    #[Test]
    public function it_rejects_unsupported_mime_types(): void
    {
        $tempTxt = tempnam(sys_get_temp_dir(), 'test_not_video_');
        file_put_contents($tempTxt, 'This is a text file, not a video.');

        try {
            $this->assertFalse($this->service->validateVideoFile($tempTxt));
        } finally {
            @unlink($tempTxt);
        }
    }

    #[Test]
    public function it_returns_false_when_disk_download_fails_during_validation(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('remote/video.mp4', 'some content');

        /** @var mixed $helperMock */
        $helperMock = $this->mock(StorageAdapterHelper::class);
        $helperMock->shouldReceive('downloadToTemp')
            ->once()
            ->andThrow(new \Exception('Download failed'));

        $service = $this->app->make(SermonMetadataIntegrationService::class);
        $result = $service->validateVideoFile('remote/video.mp4', 'public');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_validates_disk_based_video_with_temporary_download_and_cleanup(): void
    {
        Storage::fake('public');
        $videoContent = "\x00\x00\x00\x18ftypmp42";
        Storage::disk('public')->put('remote/video.mp4', $videoContent);

        // We need a real local file for mime_content_type to work in the service
        $tempFile = tempnam(sys_get_temp_dir(), 'val');
        file_put_contents($tempFile, $videoContent);

        /** @var mixed $helperMock */
        $helperMock = $this->mock(StorageAdapterHelper::class);
        $helperMock->shouldReceive('downloadToTemp')
            ->once()
            ->with('remote/video.mp4', 'public', 'local', 'temp/validation')
            ->andReturn($tempFile);

        $helperMock->shouldReceive('cleanupTempFile')
            ->once()
            ->with($tempFile);

        $service = $this->app->make(SermonMetadataIntegrationService::class);
        $result = $service->validateVideoFile('remote/video.mp4', 'public');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_validates_supported_video_mime_types(): void
    {
        $tempMp4 = tempnam(sys_get_temp_dir(), 'test_video_');
        file_put_contents($tempMp4, "\x00\x00\x00\x18ftypmp42"); // MP4 magic bytes

        $tempMov = tempnam(sys_get_temp_dir(), 'test_video_');
        file_put_contents($tempMov, "\x00\x00\x00\x14ftypqt  "); // MOV magic bytes

        try {
            $this->assertTrue($this->service->validateVideoFile($tempMp4));
            $this->assertTrue($this->service->validateVideoFile($tempMov));
        } finally {
            @unlink($tempMp4);
            @unlink($tempMov);
        }
    }

    #[Test]
    public function it_returns_false_for_nonexistent_local_video(): void
    {
        $result = $this->service->validateVideoFile('/nonexistent/path/video.mp4');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_for_zero_size_local_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video_');

        $result = $this->service->validateVideoFile($tempFile);

        @unlink($tempFile);
        $this->assertFalse($result);
    }
}
