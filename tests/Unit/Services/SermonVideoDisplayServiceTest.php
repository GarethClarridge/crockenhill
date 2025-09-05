<?php

namespace Tests\Unit\Services;

use App\Models\LivestreamProcessingLog;
use App\Models\LivestreamSegment;
use App\Models\Sermon;
use App\Services\SermonVideoDisplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonVideoDisplayServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonVideoDisplayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonVideoDisplayService;
        Storage::fake('local');
    }

    public function test_get_sermon_with_video_returns_correct_data()
    {
        $processing = LivestreamProcessingLog::factory()->create([
            'original_filename' => 'test-livestream.mp4',
            'status' => 'completed',
        ]);

        $segments = LivestreamSegment::factory()->count(3)->create([
            'processing_log_id' => $processing->id,
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $processing->id,
            'video_file_path' => 'sermons/1/video.mp4',
            'source_type' => 'livestream',
            'segment_start_time' => 120.5,
            'segment_end_time' => 1800.0,
            'series' => null,
        ]);

        $processing->update(['sermon_id' => $sermon->id]);

        $result = $this->service->getSermonWithVideo($sermon->id);

        $this->assertArrayHasKey('sermon', $result);
        $this->assertArrayHasKey('has_video', $result);
        $this->assertArrayHasKey('video_url', $result);
        $this->assertArrayHasKey('source_type', $result);
        $this->assertArrayHasKey('livestream_info', $result);

        $this->assertTrue($result['has_video']);
        $this->assertEquals('livestream', $result['source_type']);
        $this->assertNotNull($result['livestream_info']);
        $this->assertEquals('test-livestream.mp4', $result['livestream_info']['original_filename']);
        $this->assertEquals(3, $result['livestream_info']['total_segments']);
        $this->assertEquals(120.5, $result['livestream_info']['segment_start']);
        $this->assertEquals(1800.0, $result['livestream_info']['segment_end']);
    }

    public function test_get_sermon_with_video_handles_missing_sermon()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon with ID 999 not found');

        $this->service->getSermonWithVideo(999);
    }

    public function test_get_sermon_with_video_handles_sermon_without_video()
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => null,
            'source_type' => 'manual',
            'series' => null,
        ]);

        $result = $this->service->getSermonWithVideo($sermon->id);

        $this->assertFalse($result['has_video']);
        $this->assertNull($result['video_url']);
        $this->assertEquals('manual', $result['source_type']);
        $this->assertNull($result['livestream_info']);
    }

    public function test_get_video_preview_data_with_video()
    {
        Storage::put('sermons/1/video.mp4', 'fake video content');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'series' => null,
        ]);

        $result = $this->service->getVideoPreviewData($sermon->id);

        $this->assertTrue($result['has_video']);
        $this->assertArrayHasKey('video_url', $result);
        $this->assertArrayHasKey('format', $result);
        $this->assertEquals('mp4', $result['format']);
    }

    public function test_get_video_preview_data_without_video()
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => null,
            'series' => null,
        ]);

        $result = $this->service->getVideoPreviewData($sermon->id);

        $this->assertFalse($result['has_video']);
        $this->assertCount(1, $result); // Only has_video key
    }

    public function test_get_sermons_by_source_type()
    {
        $processing = LivestreamProcessingLog::factory()->create();

        $livestreamSermon = Sermon::factory()->create([
            'livestream_processing_id' => $processing->id,
            'source_type' => 'livestream',
            'video_file_path' => 'video.mp4',
            'series' => null,
        ]);

        $manualSermon = Sermon::factory()->create([
            'source_type' => 'manual',
            'video_file_path' => null,
            'series' => null,
        ]);

        // Test filtering by livestream
        $livestreamResults = $this->service->getSermonsBySourceType('livestream');
        $this->assertCount(1, $livestreamResults);
        $this->assertEquals($livestreamSermon->id, $livestreamResults[0]['id']);
        $this->assertEquals('livestream', $livestreamResults[0]['source_type']);
        $this->assertTrue($livestreamResults[0]['has_video']);

        // Test filtering by manual
        $manualResults = $this->service->getSermonsBySourceType('manual');
        $this->assertCount(1, $manualResults);
        $this->assertEquals($manualSermon->id, $manualResults[0]['id']);
        $this->assertEquals('manual', $manualResults[0]['source_type']);
        $this->assertFalse($manualResults[0]['has_video']);

        // Test no filter (all sermons)
        $allResults = $this->service->getSermonsBySourceType();
        $this->assertCount(2, $allResults);
    }

    public function test_get_livestream_source_indicator()
    {
        $processing = LivestreamProcessingLog::factory()->create([
            'original_filename' => 'service-2024-01-15.mp4',
            'status' => 'completed',
        ]);

        $segments = LivestreamSegment::factory()->count(5)->create([
            'processing_log_id' => $processing->id,
        ]);

        $livestreamSermon = Sermon::factory()->create([
            'livestream_processing_id' => $processing->id,
            'source_type' => 'livestream',
            'series' => null,
        ]);

        $processing->update(['sermon_id' => $livestreamSermon->id]);

        $result = $this->service->getLivestreamSourceIndicator($livestreamSermon);

        $this->assertTrue($result['is_livestream']);
        $this->assertEquals('service-2024-01-15.mp4', $result['original_filename']);
        $this->assertEquals('completed', $result['processing_status']);
        $this->assertEquals(5, $result['segment_count']);
        $this->assertArrayHasKey('processing_date', $result);
    }

    public function test_get_livestream_source_indicator_for_manual_sermon()
    {
        $manualSermon = Sermon::factory()->create([
            'source_type' => 'manual',
            'series' => null,
        ]);

        $result = $this->service->getLivestreamSourceIndicator($manualSermon);

        $this->assertFalse($result['is_livestream']);
        $this->assertCount(1, $result); // Only is_livestream key
    }
}
