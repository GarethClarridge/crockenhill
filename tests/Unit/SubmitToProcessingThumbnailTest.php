<?php

namespace Tests\Unit;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SubmitToProcessing;
use App\Models\LivestreamProcessingLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmitToProcessingThumbnailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('sermon_disk');
        Queue::fake();
    }

    public function test_dispatch_thumbnail_generation_method_when_enabled()
    {
        // Enable thumbnail generation
        Config::set('thumbnail-generation.enabled', true);
        Config::set('thumbnail-generation.queue.name', 'thumbnails');
        Config::set('livestream-processing.sermon_disk', 'sermon_disk');
        
        // Create a fake video file
        Storage::disk('sermon_disk')->put('sermons/videos/test.mp4', 'fake video content');
        $videoPath = 'sermons/videos/test.mp4';
        
        // Create a processing log
        $processingLog = new LivestreamProcessingLog([
            'processing_id' => 'test-123',
            'status' => 'processing',
        ]);
        
        // Create job instance
        $job = new SubmitToProcessing($processingLog);
        
        // Use reflection to call the private method
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);
        
        // Call the method
        $method->invoke($job, 123, $videoPath);
        
        // Assert thumbnail job was dispatched
        Queue::assertPushed(GenerateThumbnail::class, function ($job) {
            return $job->sermonId === 123 && 
                   str_contains($job->videoPath, 'sermons/videos/test.mp4') &&
                   $job->queue === 'thumbnails';
        });
    }

    public function test_skip_thumbnail_generation_method_when_disabled()
    {
        // Disable thumbnail generation
        Config::set('thumbnail-generation.enabled', false);
        Config::set('livestream-processing.sermon_disk', 'sermon_disk');
        
        // Create a fake video file
        Storage::disk('sermon_disk')->put('sermons/videos/test.mp4', 'fake video content');
        $videoPath = 'sermons/videos/test.mp4';
        
        // Create a processing log
        $processingLog = new LivestreamProcessingLog([
            'processing_id' => 'test-123',
            'status' => 'processing',
        ]);
        
        // Create job instance
        $job = new SubmitToProcessing($processingLog);
        
        // Use reflection to call the private method
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);
        
        // Call the method
        $method->invoke($job, 123, $videoPath);
        
        // Assert NO thumbnail job was dispatched
        Queue::assertNotPushed(GenerateThumbnail::class);
    }

    public function test_skip_thumbnail_generation_method_for_missing_video()
    {
        // Enable thumbnail generation
        Config::set('thumbnail-generation.enabled', true);
        Config::set('livestream-processing.sermon_disk', 'sermon_disk');
        
        // Don't create the video file - it should be missing
        $videoPath = 'sermons/videos/nonexistent.mp4';
        
        // Create a processing log
        $processingLog = new LivestreamProcessingLog([
            'processing_id' => 'test-123',
            'status' => 'processing',
        ]);
        
        // Create job instance
        $job = new SubmitToProcessing($processingLog);
        
        // Use reflection to call the private method
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('dispatchThumbnailGeneration');
        $method->setAccessible(true);
        
        // Call the method - should not throw exception
        $method->invoke($job, 123, $videoPath);
        
        // Assert NO thumbnail job was dispatched
        Queue::assertNotPushed(GenerateThumbnail::class);
    }
}