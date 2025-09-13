<?php

namespace Tests\Integration;

use App\Jobs\GenerateThumbnail;
use App\Models\Sermon;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailVideoProcessingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up storage fakes
        Storage::fake('local');
        Storage::fake('sermon_disk');
        Storage::fake('public');

        // Fake the queue to test job dispatching
        Queue::fake();

        // Enable thumbnail generation for tests
        Config::set('thumbnail-generation.enabled', true);
        Config::set('thumbnail-generation.queue.name', 'thumbnails');
        Config::set('livestream-processing.sermon_disk', 'sermon_disk');
    }

    /** @test */
    public function thumbnail_generation_integrates_with_direct_video_processing()
    {
        // Create a fake video file
        $videoPath = 'sermons/videos/test-sermon.mp4';
        Storage::disk('sermon_disk')->put($videoPath, 'fake video content');

        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Integration Test Sermon',
            'filename' => 'test-sermon.mp4',
            'filetype' => 'mp4',
        ]);

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to call the private dispatchThumbnailGeneration method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Call the method
        $method->invoke($videoProcessingService, $sermon->id, $videoPath);

        // Assert that thumbnail generation job was dispatched
        Queue::assertPushed(GenerateThumbnail::class, function ($job) use ($sermon, $videoPath) {
            return $job->sermonId === $sermon->id &&
                   str_contains($job->videoPath, $videoPath) &&
                   $job->queue === 'thumbnails';
        });
    }

    /** @test */
    public function thumbnail_generation_respects_disabled_configuration()
    {
        // Disable thumbnail generation
        Config::set('thumbnail-generation.enabled', false);

        // Create a fake video file
        $videoPath = 'sermons/videos/test-sermon.mp4';
        Storage::disk('sermon_disk')->put($videoPath, 'fake video content');

        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to call the private dispatchThumbnailGeneration method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Call the method
        $method->invoke($videoProcessingService, $sermon->id, $videoPath);

        // Assert that NO thumbnail generation job was dispatched
        Queue::assertNotPushed(GenerateThumbnail::class);
    }

    /** @test */
    public function thumbnail_generation_handles_missing_video_files_gracefully()
    {
        // Don't create the video file - it should be missing
        $videoPath = 'sermons/videos/nonexistent.mp4';

        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to call the private dispatchThumbnailGeneration method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Call the method - should not throw exception
        $method->invoke($videoProcessingService, $sermon->id, $videoPath);

        // Assert that NO thumbnail generation job was dispatched
        Queue::assertNotPushed(GenerateThumbnail::class);
    }

    /** @test */
    public function thumbnail_generation_uses_correct_queue_configuration()
    {
        // Set custom queue name
        Config::set('thumbnail-generation.queue.name', 'custom-thumbnails');

        // Create a fake video file
        $videoPath = 'sermons/videos/test-sermon.mp4';
        Storage::disk('sermon_disk')->put($videoPath, 'fake video content');

        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to call the private dispatchThumbnailGeneration method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Call the method
        $method->invoke($videoProcessingService, $sermon->id, $videoPath);

        // Assert that thumbnail generation job was dispatched to correct queue
        Queue::assertPushed(GenerateThumbnail::class, function ($job) {
            return $job->queue === 'custom-thumbnails';
        });
    }

    /** @test */
    public function thumbnail_generation_preserves_video_path_correctly()
    {
        // Create a fake video file with specific path
        $videoPath = 'sermons/videos/specific-test-sermon.mp4';
        Storage::disk('sermon_disk')->put($videoPath, 'fake video content');

        // Create a sermon
        $sermon = Sermon::factory()->create([
            'filename' => 'specific-test-sermon.mp4',
        ]);

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to call the private dispatchThumbnailGeneration method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Call the method
        $method->invoke($videoProcessingService, $sermon->id, $videoPath);

        // Assert that thumbnail generation job was dispatched with correct video path
        Queue::assertPushed(GenerateThumbnail::class, function ($job) use ($videoPath) {
            return str_contains($job->videoPath, $videoPath);
        });
    }

    /** @test */
    public function thumbnail_generation_works_with_different_video_formats()
    {
        $videoFormats = ['mp4', 'avi', 'mov', 'mkv'];

        foreach ($videoFormats as $format) {
            // Create a fake video file
            $videoPath = "sermons/videos/test-sermon.{$format}";
            Storage::disk('sermon_disk')->put($videoPath, 'fake video content');

            // Create a sermon
            $sermon = Sermon::factory()->create([
                'filename' => "test-sermon.{$format}",
                'filetype' => $format,
            ]);

            // Get the video processing service
            $videoProcessingService = app(VideoProcessingService::class);

            // Use reflection to call the private dispatchThumbnailGeneration method
            $reflection = new \ReflectionClass($videoProcessingService);
            $method = $reflection->getMethod('dispatchThumbnailGeneration');
            $method->setAccessible(true);

            // Call the method
            $method->invoke($videoProcessingService, $sermon->id, $videoPath);

            // Assert that thumbnail generation job was dispatched
            Queue::assertPushed(GenerateThumbnail::class, function ($job) use ($sermon, $format) {
                return $job->sermonId === $sermon->id &&
                       str_contains($job->videoPath, ".{$format}");
            });
        }
    }

    /** @test */
    public function thumbnail_generation_handles_concurrent_processing()
    {
        // Create multiple fake video files
        $sermons = [];
        $videoPaths = [];

        for ($i = 1; $i <= 3; $i++) {
            $videoPath = "sermons/videos/concurrent-test-{$i}.mp4";
            Storage::disk('sermon_disk')->put($videoPath, "fake video content {$i}");

            $sermon = Sermon::factory()->create([
                'title' => "Concurrent Test Sermon {$i}",
                'filename' => "concurrent-test-{$i}.mp4",
            ]);

            $sermons[] = $sermon;
            $videoPaths[] = $videoPath;
        }

        // Get the video processing service
        $videoProcessingService = app(VideoProcessingService::class);

        // Use reflection to get the private method
        $reflection = new \ReflectionClass($videoProcessingService);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);

        // Dispatch thumbnail generation for all sermons
        foreach ($sermons as $index => $sermon) {
            $method->invoke($videoProcessingService, $sermon->id, $videoPaths[$index]);
        }

        // Assert that all thumbnail generation jobs were dispatched
        Queue::assertPushed(GenerateThumbnail::class, 3);

        // Verify each job has correct parameters
        foreach ($sermons as $index => $sermon) {
            Queue::assertPushed(GenerateThumbnail::class, function ($job) use ($sermon, $index) {
                return $job->sermonId === $sermon->id &&
                       str_contains($job->videoPath, 'concurrent-test-'.($index + 1).'.mp4');
            });
        }
    }

    /** @test */
    public function thumbnail_generation_integration_with_storage_disks()
    {
        // Test with different storage disk configurations
        $diskConfigs = ['sermon_disk', 'local', 'public'];

        foreach ($diskConfigs as $diskName) {
            Config::set('livestream-processing.sermon_disk', $diskName);

            // Create a fake video file on the configured disk
            $videoPath = 'sermons/videos/storage-test.mp4';
            Storage::disk($diskName)->put($videoPath, 'fake video content');

            // Create a sermon
            $sermon = Sermon::factory()->create([
                'filename' => 'storage-test.mp4',
            ]);

            // Get the video processing service
            $videoProcessingService = app(VideoProcessingService::class);

            // Use reflection to call the private dispatchThumbnailGeneration method
            $reflection = new \ReflectionClass($videoProcessingService);
            $method = $reflection->getMethod('dispatchThumbnailGeneration');
            $method->setAccessible(true);

            // Call the method
            $method->invoke($videoProcessingService, $sermon->id, $videoPath);

            // Assert that thumbnail generation job was dispatched
            Queue::assertPushed(GenerateThumbnail::class, function ($job) use ($sermon) {
                return $job->sermonId === $sermon->id;
            });
        }
    }
}
