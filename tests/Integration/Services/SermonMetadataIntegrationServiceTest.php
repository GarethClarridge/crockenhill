<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\SermonMetadataIntegrationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => 120.0,
            'segment_end_time' => 3720.0,
        ]);

        $this->assertEquals(3600.0, $this->service->getSegmentDuration($sermon));
    }

    #[Test]
    public function it_derives_concat_segment_duration_from_the_recorded_span_list(): void
    {
        // A concat cut joins a 180s reading (120-300) and a 1200s sermon
        // (900-2100) across a gap. segment_end_time stays the true source end
        // (2100), so end - start would report 1980s; the true 1380s of media
        // must come from the recorded segment list instead.
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 120.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => [
                    'mode' => 'concat_spans',
                    'segments' => [
                        ['start_time' => 120.0, 'end_time' => 300.0],
                        ['start_time' => 900.0, 'end_time' => 2100.0],
                    ],
                ],
            ],
        ]);

        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'livestream_processing_id' => $log->processing_id,
            'segment_start_time' => 120.0,
            'segment_end_time' => 2100.0,
        ]);

        $this->assertEqualsWithDelta(1380.0, $this->service->getSegmentDuration($sermon), 0.01);
    }

    #[Test]
    public function it_ignores_the_recorded_plan_once_the_sermon_bounds_are_edited(): void
    {
        // The recorded concat plan describes 120-2100, but an operator has since
        // edited the Sermon's bounds to 200-1700 via SaveSermonDetails. The
        // stale plan duration must not be used; the edited continuous span
        // (1500s) is authoritative.
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 120.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => [
                    'mode' => 'concat_spans',
                    'segments' => [
                        ['start_time' => 120.0, 'end_time' => 300.0],
                        ['start_time' => 900.0, 'end_time' => 2100.0],
                    ],
                ],
            ],
        ]);

        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Livestream,
            'livestream_processing_id' => $log->processing_id,
            'segment_start_time' => 200.0,
            'segment_end_time' => 1700.0,
        ]);

        $this->assertEqualsWithDelta(1500.0, $this->service->getSegmentDuration($sermon), 0.01);
    }

    #[Test]
    public function it_returns_null_segment_duration_for_non_livestream_sermon(): void
    {
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
        $sermon = Sermon::factory()->create([
            'source_type' => SermonSourceType::Manual,
        ]);

        $this->assertEquals([], $this->service->getLivestreamInfo($sermon));
    }

    // --- linkVideoToSermon() ---

    #[Test]
    public function it_links_video_to_sermon_record(): void
    {
        $sermon = Sermon::factory()->create();
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
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->service->linkVideoToSermon($log->processing_id, $sermon->id, 'sermons/1/video.mp4');

        $log->refresh();
        $this->assertEquals($sermon->id, $log->sermon_id);
        $this->assertEquals('sermons/1/video.mp4', $log->video_file_path);
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

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'video_file_path' => 'temp/sermon-video.mp4',
        ]);

        Storage::disk('local')->put('temp/sermon-video.mp4', str_repeat('video-bytes', 128));

        $service = $this->partialMock(SermonMetadataIntegrationService::class, function ($mock): void {
            $mock->shouldReceive('validateVideoFile')->once()->andReturnTrue();
        });

        $finalPath = $service->storeVideoForSermon($log->processing_id, $sermon->id);

        $this->assertSame("sermons/{$sermon->id}/video.mp4", $finalPath);
        Storage::disk('public')->assertExists($finalPath);
    }

    #[Test]
    public function it_names_historic_import_videos_by_processing_id_instead_of_local_sermon_id(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'video_file_path' => 'temp/sermon-video.mp4',
            'processing_metadata' => [
                'historic_import' => ['label' => 'archive recording'],
            ],
        ]);

        Storage::disk('local')->put('temp/sermon-video.mp4', str_repeat('historic-video', 128));

        $service = $this->partialMock(SermonMetadataIntegrationService::class, function ($mock): void {
            $mock->shouldReceive('validateVideoFile')->once()->andReturnTrue();
        });

        $finalPath = $service->storeVideoForSermon($log->processing_id, $sermon->id);

        $this->assertSame("historic-imports/{$log->processing_id}/sermon/video.mp4", $finalPath);
        $this->assertStringNotContainsString("/{$sermon->id}/", $finalPath);
        Storage::disk('public')->assertExists($finalPath);
    }

    #[Test]
    public function it_refuses_to_replace_a_different_existing_historic_video(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'video_file_path' => 'temp/sermon-video.mp4',
            'processing_metadata' => [
                'historic_import' => ['label' => 'archive recording'],
            ],
        ]);
        $finalPath = "historic-imports/{$log->processing_id}/sermon/video.mp4";

        Storage::disk('local')->put('temp/sermon-video.mp4', str_repeat('new-video', 128));
        Storage::disk('public')->put($finalPath, str_repeat('different-video', 128));

        $service = $this->partialMock(SermonMetadataIntegrationService::class, function ($mock): void {
            $mock->shouldReceive('validateVideoFile')->once()->andReturnTrue();
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different content');

        $service->storeVideoForSermon($log->processing_id, $sermon->id);
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_processing_id(): void
    {
        $sermon = Sermon::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->linkVideoToSermon('nonexistent-id', $sermon->id, 'sermons/1/video.mp4');
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_sermon_id(): void
    {
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
        $sermon = Sermon::factory()->create(['video_file_path' => null]);

        $info = $this->service->getVideoInfo($sermon->id);

        $this->assertFalse($info['has_video']);
    }

    #[Test]
    public function it_returns_video_info_for_sermon_with_video(): void
    {
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
        $sermon = Sermon::factory()->create(['video_file_path' => null]);

        $preview = $this->service->getVideoPreviewData($sermon->id);

        $this->assertFalse($preview['has_video']);
    }

    #[Test]
    public function it_returns_preview_data_with_format(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

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
