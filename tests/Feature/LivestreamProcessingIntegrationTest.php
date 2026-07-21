<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\SermonSourceType;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Sermon\LivestreamSegmentationService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LivestreamProcessingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('sermon_disk');
        Queue::fake();
        Bus::fake();

        // Use new unified media-processing config
        Config::set('media-processing.segmentation.rms_threshold', -30.0);
        Config::set('media-processing.segmentation.min_section_duration', 60.0);
        Config::set('media-processing.segmentation.min_sermon_duration', 300.0);
        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.types.livestream.max_file_size', 2147483648);
        Config::set('media-processing.types.livestream.allowed_extensions', ['mp4', 'mov', 'avi', 'mkv']);
        Config::set('media-processing.storage.sermon_disk', 'sermon_disk');
        Config::set('media-processing.storage.temp_disk', 'local');

        // Create temp directory for tests
        Storage::disk('local')->makeDirectory('temp');
    }

    public function test_complete_processing_pipeline_integration()
    {
        // Create a mock video file
        $videoFile = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        // Mock the VideoSegmentationService to avoid actual file validation
        $mockSegmentationService = $this->createStub(VideoSegmentationService::class);
        $mockSegmentationService->method('validateVideoFile')->willReturn(true);
        $mockSegmentationService->method('getVideoMetadata')->willReturn([
            'duration' => 3600.0,
            'format' => 'mp4',
            'size' => 50000,
        ]);

        // Mock the VideoStorageService to avoid actual file operations
        $mockStorageService = $this->createStub(VideoStorageService::class);
        $mockStorageService->method('validateStorageSpace')->willReturn(true);
        $mockStorageService->method('storeUploadedVideo')->willReturn([
            'original_filename' => 'livestream.mp4',
            'temp_path' => 'livestreams/temp_livestream.mp4',
            'full_path' => storage_path('app/livestreams/temp_livestream.mp4'),
            'file_size' => 50000,
            'mime_type' => 'video/mp4',
        ]);

        // Simulate the file being stored
        Storage::put('livestreams/temp_livestream.mp4', 'fake video content');

        $this->app->instance(VideoSegmentationService::class, $mockSegmentationService);
        $this->app->instance(VideoStorageService::class, $mockStorageService);

        // Create the service
        $service = app(LivestreamSegmentationService::class);

        // Process the livestream
        $result = $service->startProcessing($videoFile);

        // Verify processing record was created
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $result->processingId,
            'original_filename' => 'livestream.mp4',
            'status' => 'pending',
        ]);

        // Verify file was stored
        $processing = MediaProcessingLog::where('processing_id', $result->processingId)->first();
        $this->assertTrue(Storage::exists($processing->source_file_path));

        // Verify RMS generation is dispatched in the parallel phase
        Bus::assertBatched(function (PendingBatch $batch) {
            $classes = $batch->jobs->map(fn ($job) => get_class($job))->all();

            return $classes === [GenerateRmsLog::class];
        });
    }

    public function test_livestream_chain_includes_completion_notification_job()
    {
        $videoFile = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        $mockSegmentationService = $this->createStub(VideoSegmentationService::class);
        $mockSegmentationService->method('validateVideoFile')->willReturn(true);
        $mockSegmentationService->method('getVideoMetadata')->willReturn([
            'duration' => 3600.0,
            'format' => 'mp4',
            'size' => 50000,
        ]);

        $mockStorageService = $this->createStub(VideoStorageService::class);
        $mockStorageService->method('validateStorageSpace')->willReturn(true);
        $mockStorageService->method('storeUploadedVideo')->willReturn([
            'original_filename' => 'livestream.mp4',
            'temp_path' => 'livestreams/temp_livestream.mp4',
            'full_path' => storage_path('app/livestreams/temp_livestream.mp4'),
            'file_size' => 50000,
            'mime_type' => 'video/mp4',
        ]);

        Storage::put('livestreams/temp_livestream.mp4', 'fake video content');

        $this->app->instance(VideoSegmentationService::class, $mockSegmentationService);
        $this->app->instance(VideoStorageService::class, $mockStorageService);

        $service = app(LivestreamSegmentationService::class);
        $result = $service->startProcessing($videoFile);

        $this->assertNotNull($result->processingId);

        $pendingBatch = null;

        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch) {
            $pendingBatch = $batch;

            return true;
        });

        $this->assertNotNull($pendingBatch);

        $thenCallbacks = $pendingBatch->thenCallbacks();
        $this->assertNotEmpty($thenCallbacks);

        $thenCallback = $thenCallbacks[0];
        if (is_object($thenCallback) && method_exists($thenCallback, 'getClosure')) {
            $thenCallback = $thenCallback->getClosure();
        }

        $fakeBatch = Bus::dispatchFakeBatch('livestream-chain-callback-test');
        $thenCallback($fakeBatch);

        Bus::assertChained([
            AnalyzeSegments::class,
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ProjectLivestreamServiceStructure::class,
            MatchSongsFromTranscript::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ]);
    }

    public function test_segmentation_analysis_integration()
    {
        // Create processing record with sample RMS data
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-segmentation',
            'status' => 'processing',
            'duration' => 3600.0,
        ]);

        // Create mock RMS log file
        $rmsLogContent = $this->createMockRmsLog();
        Storage::put('temp/rms_test-segmentation.log', $rmsLogContent);

        $segmentationService = app(VideoSegmentationService::class);

        // Simulate RMS analysis
        $segments = [
            ['start' => 0, 'end' => 180, 'classification' => 'song'],
            ['start' => 180, 'end' => 2100, 'classification' => 'speech'],
            ['start' => 2100, 'end' => 2280, 'classification' => 'song'],
            ['start' => 2280, 'end' => 3600, 'classification' => 'speech'],
        ];

        // Store segments in database
        foreach ($segments as $index => $segment) {
            LivestreamSegment::create([
                'processing_id' => $processing->processing_id,
                'media_processing_log_id' => $processing->id,
                'segment_index' => $index + 1,
                'segment_order' => $index + 1,
                'start_time' => $segment['start'],
                'end_time' => $segment['end'],
                'duration' => $segment['end'] - $segment['start'],
                'classification' => $segment['classification'],
                'is_sermon_candidate' => $segment['classification'] === 'speech' &&
                                     ($segment['end'] - $segment['start']) > 300,
                'avg_rms' => 0.5,
                'peak_rms' => 0.8,
                'metadata' => [],
            ]);
        }

        // Verify segments were created correctly
        $this->assertDatabaseCount('livestream_segments', 4);

        $sermonSegment = LivestreamSegment::where('media_processing_log_id', $processing->id)
            ->where('is_sermon_candidate', true)
            ->first();

        $this->assertNotNull($sermonSegment);
        $this->assertEquals(180, $sermonSegment->start_time);
        $this->assertEquals(2100, $sermonSegment->end_time);
        $this->assertEquals(LivestreamSegmentClassification::Speech, $sermonSegment->classification);
    }

    public function test_video_storage_integration()
    {
        $storageService = app(VideoStorageService::class);

        // Create a temporary video file using fake storage
        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');
        $tempVideoPath = Storage::disk('local')->path('temp/test_video.mp4');

        // Create a fake uploaded file
        $uploadedFile = UploadedFile::fake()->createWithContent('test_video.mp4', 'fake video content');

        // Test video storage
        $result = $storageService->storeUploadedVideo($uploadedFile);

        $this->assertArrayHasKey('temp_path', $result);
        $this->assertArrayHasKey('original_filename', $result);
        $this->assertEquals('test_video.mp4', $result['original_filename']);

        // Clean up
        unlink($tempVideoPath);
    }

    public function test_sermon_integration_with_livestream_processing()
    {
        // Create a complete processing record
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-sermon-integration',
            'status' => 'completed',
            'original_filename' => 'sunday-service.mp4',
        ]);

        // Create segments
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $processing->id,
            'segment_index' => 1,
            'start_time' => 300,
            'end_time' => 2100,
            'classification' => 'speech',
            'is_sermon_candidate' => true,
        ]);

        // Create sermon record with livestream connection
        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $processing->processing_id,
            'video_file_path' => 'sermons/1/video.mp4',
            'source_type' => 'livestream',
            'segment_start_time' => 300,
            'segment_end_time' => 2100,
            'title' => 'Test Sermon from Livestream',
        ]);

        // Update processing with sermon link
        $processing->update(['sermon_id' => $sermon->id]);

        // Verify relationships
        $this->assertEquals($processing->processing_id, $sermon->livestreamProcessing->processing_id);
        $this->assertEquals($sermon->id, $processing->sermon_id);
        $this->assertEquals(SermonSourceType::Livestream, $sermon->source_type);
        $this->assertNotNull($sermon->video_file_path);

    }

    private function createMockRmsLog(): string
    {
        return implode("\n", [
            'frame.pts_time=0.000000 lavfi.astats.Overall.RMS_level=-25.5',
            'frame.pts_time=1.000000 lavfi.astats.Overall.RMS_level=-26.2',
            'frame.pts_time=180.000000 lavfi.astats.Overall.RMS_level=-35.1',
            'frame.pts_time=181.000000 lavfi.astats.Overall.RMS_level=-36.8',
            'frame.pts_time=2100.000000 lavfi.astats.Overall.RMS_level=-24.9',
            'frame.pts_time=2101.000000 lavfi.astats.Overall.RMS_level=-25.4',
            'frame.pts_time=2280.000000 lavfi.astats.Overall.RMS_level=-38.2',
            'frame.pts_time=2281.000000 lavfi.astats.Overall.RMS_level=-37.5',
        ]);
    }
}
