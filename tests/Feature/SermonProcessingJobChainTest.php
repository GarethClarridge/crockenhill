<?php

namespace Tests\Feature;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Services\AudioTranscriptionService;
use App\Services\SermonAnalysisService;
use App\Services\SermonProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonProcessingJobChainTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    // Set up storage and configuration
    Storage::fake('local');
    Storage::fake('public');

    config([
      'sermon-processing.transcription.openai_api_key' => 'test-key',
      'sermon-processing.analysis.openai_api_key' => 'test-key',
      'sermon-processing.processing.queue' => 'default',
    ]);
  }

  /** @test */
  public function it_creates_sermon_record_successfully(): void
  {
    $processingId = 'test-processing-id';
    $metadata = SermonMetadata::create(
      date: Carbon::parse('2024-01-15'),
      service: SermonService::MORNING,
      filename: 'stored-file.mp3',
      originalName: '2024-01-15_morning_sermon.mp3',
      duration: 3600.0,
      bitrate: 128000,
      format: 'MP3',
      filesize: 50000000
    );
    $storedFilePath = 'sermons/2024/01/test-file.mp3';

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => $metadata->originalName,
      'status' => ProcessingStatus::PENDING,
      'current_step' => 'initiated',
    ]);

    // Create the job
    $job = new CreateSermonRecord($processingId, $metadata, $storedFilePath);

    // Execute the job
    $job->handle();

    // Assert sermon record was created
    $this->assertDatabaseHas('sermons', [
      'filename' => $storedFilePath,
      'date' => '2024-01-15',
      'service' => SermonService::MORNING->value,
      'preacher' => 'Mark Drury',
    ]);

    // Assert processing log was updated
    $processingLog->refresh();
    $this->assertEquals('sermon_record_created', $processingLog->current_step);
    $this->assertNotNull($processingLog->sermon_id);

    // Assert sermon has proper initial values
    $sermon = Sermon::find($processingLog->sermon_id);
    $this->assertNotNull($sermon);
    $this->assertNotEmpty($sermon->title);
    $this->assertNotEmpty($sermon->slug);
    $this->assertNull($sermon->series);
    $this->assertNull($sermon->reference);
    $this->assertNull($sermon->transcript_path);
  }

  /** @test */
  public function it_handles_create_sermon_record_failure(): void
  {
    $processingId = 'test-processing-id';
    $metadata = SermonMetadata::create(
      date: Carbon::parse('2024-01-15'),
      service: SermonService::MORNING,
      filename: 'stored-file.mp3',
      originalName: '2024-01-15_morning_sermon.mp3',
      duration: 3600.0,
      bitrate: 128000,
      format: 'MP3',
      filesize: 50000000
    );
    $storedFilePath = 'sermons/2024/01/test-file.mp3';

    // Don't create processing log to trigger failure
    $job = new CreateSermonRecord($processingId, $metadata, $storedFilePath);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Processing log not found');

    $job->handle();
  }

  /** @test */
  public function it_transcribes_audio_successfully(): void
  {
    // Create sermon record
    $sermon = Sermon::factory()->create([
      'filename' => 'test-audio.mp3',
      'transcript_path' => null,
    ]);

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'sermon_record_created',
      'sermon_id' => $sermon->id,
    ]);

    // Create a fake audio file
    Storage::put('test-audio.mp3', 'fake audio content');

    // Mock the transcription service
    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
    $mockTranscriptionService->expects($this->once())
      ->method('transcribe')
      ->with('test-audio.mp3')
      ->willReturn('# Sermon Transcript\n\nThis is a test transcript.');

    $mockTranscriptionService->expects($this->once())
      ->method('storeTranscript')
      ->with($sermon->id, '# Sermon Transcript\n\nThis is a test transcript.')
      ->willReturn('transcripts/sermon_' . $sermon->id . '.md');

    $this->app->instance(AudioTranscriptionService::class, $mockTranscriptionService);

    // Create and execute the job
    $job = new TranscribeAudio($sermon->id);
    $job->handle($mockTranscriptionService);

    // Assert sermon was updated with transcript path
    $sermon->refresh();
    $this->assertEquals('transcripts/sermon_' . $sermon->id . '.md', $sermon->transcript_path);

    // Assert processing log was updated
    $processingLog->refresh();
    $this->assertEquals('transcription_completed', $processingLog->current_step);
  }

  /** @test */
  public function it_handles_transcription_failure_with_cleanup(): void
  {
    // Create sermon record
    $sermon = Sermon::factory()->create([
      'filename' => 'test-audio.mp3',
    ]);

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'sermon_record_created',
      'sermon_id' => $sermon->id,
    ]);

    // Mock the transcription service to throw exception
    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
    $mockTranscriptionService->expects($this->once())
      ->method('transcribe')
      ->willThrowException(new \Exception('Transcription failed'));

    $mockTranscriptionService->expects($this->once())
      ->method('cleanupOnFailure')
      ->with($sermon->id);

    $this->app->instance(AudioTranscriptionService::class, $mockTranscriptionService);

    // Create and execute the job
    $job = new TranscribeAudio($sermon->id);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Transcription failed');

    $job->handle($mockTranscriptionService);

    // Assert processing log was marked as failed
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::FAILED, $processingLog->status);
    $this->assertEquals('transcribing_audio', $processingLog->current_step);
    $this->assertStringContainsString('Transcription failed', $processingLog->error_message);
  }

  /** @test */
  public function it_processes_transcript_with_ai_successfully(): void
  {
    // Create sermon with transcript
    $sermon = Sermon::factory()->create([
      'transcript_path' => 'transcripts/sermon_1.md',
    ]);

    // Store transcript content
    Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript about God\'s love and grace.');

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcription_completed',
      'sermon_id' => $sermon->id,
    ]);

    // Mock the analysis service
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->expects($this->once())
      ->method('analyzeSermon')
      ->willReturn($this->createMockSermonAnalysis());

    $this->app->instance(SermonAnalysisService::class, $mockAnalysisService);

    // Create and execute the job
    $job = new ProcessTranscriptWithAI($sermon->id);
    $job->handle($mockAnalysisService);

    // Assert processing log was updated
    $processingLog->refresh();
    $this->assertEquals('ai_analysis_completed', $processingLog->current_step);
  }

  /** @test */
  public function it_handles_ai_processing_failure_with_fallback(): void
  {
    // Create sermon with transcript
    $sermon = Sermon::factory()->create([
      'transcript_path' => 'transcripts/sermon_1.md',
      'title' => 'Initial Title',
    ]);

    // Store transcript content
    Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript about God\'s love and grace.');

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcription_completed',
      'sermon_id' => $sermon->id,
    ]);

    // Mock the analysis service to throw exception
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->expects($this->once())
      ->method('analyzeSermon')
      ->willThrowException(new \Exception('AI service unavailable'));

    $this->app->instance(SermonAnalysisService::class, $mockAnalysisService);

    // Create and execute the job
    $job = new ProcessTranscriptWithAI($sermon->id);
    $job->handle($mockAnalysisService);

    // Assert processing log shows fallback was used
    $processingLog->refresh();
    $this->assertEquals('ai_analysis_fallback', $processingLog->current_step);
  }

  /** @test */
  public function it_updates_sermon_record_successfully(): void
  {
    // Create sermon record
    $sermon = Sermon::factory()->create([
      'title' => 'Initial Title',
      'slug' => 'initial-title',
      'transcript_path' => 'transcripts/sermon_1.md',
    ]);

    // Store transcript content
    Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript about God\'s love and grace.');

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'ai_analysis_completed',
      'sermon_id' => $sermon->id,
    ]);

    // Mock the analysis service
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->expects($this->once())
      ->method('analyzeSermon')
      ->willReturn($this->createMockSermonAnalysis());

    $this->app->instance(SermonAnalysisService::class, $mockAnalysisService);

    // Create and execute the job
    $job = new UpdateSermonRecord($sermon->id);
    $job->handle($mockAnalysisService);

    // Assert sermon was updated
    $sermon->refresh();
    $this->assertEquals('God\'s Amazing Love', $sermon->title);
    $this->assertEquals('gods-amazing-love', $sermon->slug);
    $this->assertEquals('John Study', $sermon->series);
    $this->assertEquals('John 3:16-21', $sermon->reference);
    $this->assertIsArray($sermon->points);
    $this->assertCount(3, $sermon->points);

    // Assert processing log was marked as completed
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::COMPLETED, $processingLog->status);
  }

  /** @test */
  public function it_sends_completion_notification_successfully(): void
  {
    // Create completed sermon
    $sermon = Sermon::factory()->create([
      'title' => 'Completed Sermon',
      'slug' => 'completed-sermon',
      'series' => 'Test Series',
      'reference' => 'John 3:16',
      'points' => ['Point 1', 'Point 2'],
    ]);

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'updating_sermon_record',
      'sermon_id' => $sermon->id,
    ]);

    // Create and execute the job
    $job = new SendCompletionNotification($sermon->id);
    $job->handle();

    // Assert processing log was updated
    $processingLog->refresh();
    $this->assertEquals('notification_sent', $processingLog->current_step);
  }

  /** @test */
  public function it_handles_notification_failure_gracefully(): void
  {
    // Create completed sermon
    $sermon = Sermon::factory()->create([
      'title' => 'Completed Sermon',
    ]);

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'updating_sermon_record',
      'sermon_id' => $sermon->id,
    ]);

    // Mock to simulate failure in notification preparation
    $job = new class($sermon->id) extends SendCompletionNotification {
      protected function prepareNotificationData($sermon, $processingLog): array
      {
        throw new \Exception('Notification preparation failed');
      }
    };

    // Execute the job - should not throw exception
    $job->handle();

    // Assert processing log shows notification failed but processing continues
    $processingLog->refresh();
    $this->assertEquals('notification_failed', $processingLog->current_step);
    $this->assertStringContainsString('Notification failed', $processingLog->error_message);
  }

  /** @test */
  public function it_processes_complete_job_chain_integration(): void
  {
    Queue::fake();

    // Create initial data
    $processingId = 'integration-test-id';
    $metadata = SermonMetadata::create(
      date: Carbon::parse('2024-01-15'),
      service: SermonService::MORNING,
      filename: 'stored-file.mp3',
      originalName: '2024-01-15_morning_sermon.mp3',
      duration: 3600.0,
      bitrate: 128000,
      format: 'MP3',
      filesize: 50000000
    );
    $storedFilePath = 'sermons/2024/01/test-file.mp3';

    // Create processing log
    SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => $metadata->originalName,
      'status' => ProcessingStatus::PENDING,
      'current_step' => 'initiated',
    ]);

    // Execute first job
    $createJob = new CreateSermonRecord($processingId, $metadata, $storedFilePath);
    $createJob->handle();

    // Verify sermon was created
    $sermon = Sermon::where('filename', $storedFilePath)->first();
    $this->assertNotNull($sermon);

    // Verify next job would be dispatched
    Queue::assertPushed(TranscribeAudio::class, function ($job) use ($sermon) {
      return $job->sermonId === $sermon->id;
    });
  }

  /** @test */
  public function it_handles_job_chain_failure_and_recovery(): void
  {
    // Create sermon in failed state
    $sermon = Sermon::factory()->create([
      'filename' => 'test-audio.mp3',
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'failed-test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'transcribing_audio_failed',
      'sermon_id' => $sermon->id,
      'error_message' => 'Transcription service unavailable',
    ]);

    // Test recovery by retrying from failed step
    $service = new SermonProcessingService();
    $result = $service->retryProcessing('failed-test-id');

    $this->assertTrue($result->success);
    $this->assertEquals('failed-test-id', $result->processingId);

    // Verify processing log was reset
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::PENDING, $processingLog->status);
    $this->assertEquals('retry_initiated', $processingLog->current_step);
    $this->assertNull($processingLog->error_message);
  }

  /** @test */
  public function it_validates_file_storage_and_cleanup(): void
  {
    $processingId = 'storage-test-id';
    $metadata = SermonMetadata::create(
      date: Carbon::parse('2024-01-15'),
      service: SermonService::MORNING,
      filename: 'stored-file.mp3',
      originalName: '2024-01-15_morning_sermon.mp3',
      duration: 3600.0,
      bitrate: 128000,
      format: 'MP3',
      filesize: 50000000
    );

    // Create a test file
    $file = UploadedFile::fake()->create('test-sermon.mp3', 1024, 'audio/mpeg');

    // Process the file
    $service = new SermonProcessingService();
    $result = $service->processSermon($file);

    $this->assertTrue($result->success);
    $this->assertNotEmpty($result->processingId);

    // Verify processing log was created
    $processingLog = SermonProcessingLog::where('processing_id', $result->processingId)->first();
    $this->assertNotNull($processingLog);
    $this->assertEquals('test-sermon.mp3', $processingLog->original_filename);
    $this->assertEquals(ProcessingStatus::PENDING, $processingLog->status);
  }

  /** @test */
  public function it_handles_database_record_creation_and_updates(): void
  {
    // Test complete database operations through job chain
    $processingId = 'db-test-id';
    $metadata = SermonMetadata::create(
      date: Carbon::parse('2024-01-15'),
      service: SermonService::EVENING,
      filename: 'stored-file.mp3',
      originalName: '2024-01-15_evening_sermon.mp3',
      duration: 3600.0,
      bitrate: 128000,
      format: 'MP3',
      filesize: 50000000
    );
    $storedFilePath = 'sermons/2024/01/test-file.mp3';

    // Create processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => $metadata->originalName,
      'status' => ProcessingStatus::PENDING,
      'current_step' => 'initiated',
    ]);

    // Execute create sermon job
    $createJob = new CreateSermonRecord($processingId, $metadata, $storedFilePath);
    $createJob->handle();

    // Verify database state
    $this->assertDatabaseHas('sermons', [
      'filename' => $storedFilePath,
      'date' => '2024-01-15',
      'service' => SermonService::EVENING->value,
      'preacher' => 'Mark Drury',
    ]);

    $this->assertDatabaseHas('sermon_processing_logs', [
      'processing_id' => $processingId,
      'status' => ProcessingStatus::PROCESSING->value,
      'current_step' => 'sermon_record_created',
    ]);

    // Get created sermon
    $sermon = Sermon::where('filename', $storedFilePath)->first();
    $this->assertNotNull($sermon);

    // Test update job
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->method('analyzeSermon')
      ->willReturn($this->createMockSermonAnalysis());

    $updateJob = new UpdateSermonRecord($sermon->id);
    $updateJob->handle($mockAnalysisService);

    // Verify sermon was updated
    $sermon->refresh();
    $this->assertEquals('God\'s Amazing Love', $sermon->title);
    $this->assertNotNull($sermon->series);
    $this->assertNotNull($sermon->reference);
    $this->assertIsArray($sermon->points);

    // Verify processing log completion
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::COMPLETED, $processingLog->status);
  }

  /**
   * Create a mock SermonAnalysis object for testing
   */
  private function createMockSermonAnalysis()
  {
    return new class {
      public $title = 'God\'s Amazing Love';
      public $series = 'John Study';
      public $reference = 'John 3:16-21';
      public $points = ['First Point', 'Second Point', 'Third Point'];
      public $transcript = 'Sample transcript content';

      public function hasValidTranscript(): bool
      {
        return true;
      }

      public function getSummary(): array
      {
        return [
          'title' => $this->title,
          'series' => $this->series,
          'reference' => $this->reference,
          'points_count' => count($this->points),
        ];
      }
    };
  }
}
