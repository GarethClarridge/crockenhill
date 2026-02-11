<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonMetadataIntegrationService;
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
        $this->service = new SermonMetadataIntegrationService;
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
        $this->assertEquals('livestream', $sermon->source_type);
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
    public function it_throws_exception_for_nonexistent_processing_id(): void
    {
        $sermon = Sermon::factory()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->linkVideoToSermon('nonexistent-id', $sermon->id, 'sermons/1/video.mp4');
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_sermon_id(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

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
            'source_type' => 'video_upload',
        ]);

        $info = $this->service->getVideoInfo($sermon->id);

        $this->assertTrue($info['has_video']);
        $this->assertEquals('video_upload', $info['source_type']);
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

        // Should not throw any exception
        $this->service->cleanupTemporaryVideoFiles('nonexistent-id');

        $this->assertTrue(true);
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
