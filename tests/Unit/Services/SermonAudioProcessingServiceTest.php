<?php

namespace Tests\Unit\Services;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\MetadataExtractionService;
use App\Services\ProcessingPipelineBuilder;
use App\Services\SermonAudioProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAudioProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonAudioProcessingService $service;
    private MetadataExtractionService $metadataService;
    private ProcessingPipelineBuilder $pipelineBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->metadataService = $this->createMock(MetadataExtractionService::class);
        $this->pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);

        // Bind pipeline builder to container since it's resolved via app()
        $this->app->instance(ProcessingPipelineBuilder::class, $this->pipelineBuilder);

        $this->service = new SermonAudioProcessingService($this->metadataService);

        // Setup storage fakes
        Storage::fake('public');
        Storage::fake('local');
        
        // Setup config defaults
        Config::set('media-processing.processing.max_file_size', 10 * 1024 * 1024); // 10MB
        Config::set('media-processing.processing.allowed_mime_types', ['audio/mpeg', 'audio/mp3']);
        Config::set('media-processing.processing.allowed_extensions', ['mp3']);
        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function it_validates_audio_file_size(): void
    {
        $file = UploadedFile::fake()->create('large_sermon.mp3', 11 * 1024); // 11MB

        $result = $this->service->processSermon($file);
        
        $this->assertFalse($result->success);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
        $this->assertStringContainsString('File size exceeds maximum limit', $result->message);
    }

    #[Test]
    public function it_validates_audio_mime_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $result = $this->service->processSermon($file);

        $this->assertFalse($result->success);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
        $this->assertStringContainsString('Invalid file type', $result->message);
    }

    #[Test]
    public function it_validates_audio_extension(): void
    {
        // Even with correct mime, if extension matches denied list (or not in allowed)
        $file = UploadedFile::fake()->create('sermon.txt', 1024, 'audio/mpeg');

        $result = $this->service->processSermon($file);

        $this->assertFalse($result->success);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
        $this->assertStringContainsString('Invalid file extension', $result->message);
    }

    #[Test]
    public function it_processes_sermon_successfully(): void
    {
        Bus::fake();
        
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');
        
        // Mock metadata extraction
        $this->metadataService->expects($this->once())
            ->method('extractId3Metadata')
            ->willReturn(['title' => 'Test Sermon', 'artist' => 'Test Preacher']);

        // Mock pipeline building
        $this->pipelineBuilder->expects($this->once())
            ->method('buildAudioPipeline')
            ->willReturn([new DummyJob()]);

        $result = $this->service->processSermon($file);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->processingId);
        
        // Check log creation
        $log = MediaProcessingLog::where('processing_id', $result->processingId)->first();
        $this->assertNotNull($log);
        $this->assertEquals('audio', $log->processing_type);
        $this->assertEquals('sermon.mp3', $log->original_filename);
        $this->assertEquals(ProcessingStatus::PENDING, $log->status);
        
        // Check file storage
        Storage::disk('public')->assertExists($log->source_file_path);
        
        // Check job dispatch
        Bus::assertDispatched(DummyJob::class);
    }

    #[Test]
    public function it_accepts_client_file_date(): void
    {
        Bus::fake();
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');
        
        $this->pipelineBuilder->method('buildAudioPipeline')->willReturn([new DummyJob()]);

        // We can't easily check internal logging of client date without a log spy, 
        // but we can ensure it doesn't crash and returns success
        $result = $this->service->processSermon($file, '2023-01-01');

        $this->assertTrue($result->success);
    }
    
    #[Test]
    public function it_processes_livestream_audio_successfully(): void
    {
        Bus::fake();
        
        $file = UploadedFile::fake()->create('livestream.mp3', 1024, 'audio/mpeg');
        $metadata = [
            'source_type' => 'livestream',
            'livestream_processing_id' => 'ls-123'
        ];
        
        $this->pipelineBuilder->expects($this->once())
            ->method('buildAudioPipeline')
            ->willReturn([new DummyJob()]);

        $result = $this->service->processSermonAudio($file, $metadata);
        
        $this->assertTrue($result['success']);
        $this->assertNotNull($result['processing_id']);
        
        $log = MediaProcessingLog::where('processing_id', $result['processing_id'])->first();
        $this->assertNotNull($log);
        $this->assertEquals('initiated_from_livestream:ls-123', $log->current_step);
        
        // Check transcript placeholder
        $this->assertArrayHasKey('transcript_path', $result);
        Storage::disk('local')->assertExists($result['transcript_path']);
    }

    #[Test]
    public function it_handles_processing_failures_during_initiation(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');
        
        // Force an exception during storage or metadata extraction
        $this->metadataService->expects($this->once())
            ->method('extractId3Metadata')
            ->willThrowException(new \RuntimeException('Metadata extraction failed'));

        $result = $this->service->processSermon($file);
        
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Metadata extraction failed', $result->message);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
    }
}

class DummyJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable, \Illuminate\Queue\InteractsWithQueue, \Illuminate\Bus\Queueable;
    
    public function handle(): void {}
}
