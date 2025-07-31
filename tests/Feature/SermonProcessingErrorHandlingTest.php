<?php

namespace Tests\Feature;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Services\AudioTranscriptionService;
use App\Services\SermonAnalysisService;
use App\Services\SermonProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingErrorHandlingTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    Storage::fake('local');
    Storage::fake('public');

    config([
      'sermon-processing.transcription.openai_api_key' => 'test-key',
      'sermon-processing.analysis.openai_api_key' => 'test-key',
      'sermon-processing.processing.queue' => 'default',
    ]);
  }

  #[Test]
  public function it_handles_missing_processing_log_gracefully(): void
  {
    $processingId = 'nonexistent-id';
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

    $job = new CreateSermonRecord($processingId, $metadata, $storedFilePath);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Processing log not found');

    $logger = app(\App\Services\SermonProcessingLogger::class);
    $job->handle($logger);
  }

  #[Test]
  public function it_handles_missing_sermon_record_in_transcription(): void
  {
    $nonexistentSermonId = 999;

    $job = new TranscribeAudio($nonexistentSermonId);

    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Sermon not found');

    $job->handle($mockTranscriptionService);
  }

  #[Test]
  public function it_handles_missing_audio_file_in_transcription(): void
  {
    // Create sermon without actual audio file
    $sermon = Sermon::factory()->create([
      'filename' => 'nonexistent-audio.mp3',
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'nonexistent-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'sermon_record_created',
      'sermon_id' => $sermon->id,
    ]);

    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
    $mockTranscriptionService->expects($this->once())
      ->method('transcribe')
      ->willThrowException(new \Exception('Audio file not found'));

    $mockTranscriptionService->expects($this->once())
      ->method('cleanupOnFailure')
      ->with($sermon->id);

    $job = new TranscribeAudio($sermon->id);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Audio file not found');

    $job->handle($mockTranscriptionService);

    // Verify processing log was updated with error
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::FAILED, $processingLog->status);
    $this->assertStringContainsString('Audio file not found', $processingLog->error_message);
  }

  #[Test]
  public function it_handles_empty_transcript_in_ai_processing(): void
  {
    // Create sermon without transcript
    $sermon = Sermon::factory()->create([
      'transcript_path' => null,
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcription_completed',
      'sermon_id' => $sermon->id,
    ]);

    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);

    $job = new ProcessTranscriptWithAI($sermon->id);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('No transcript available');

    $job->handle($mockAnalysisService);
  }

  #[Test]
  public function it_applies_graceful_degradation_on_ai_failure(): void
  {
    // Create sermon with transcript
    $sermon = Sermon::factory()->create([
      'title' => 'Initial Title',
      'transcript_path' => 'transcripts/sermon_1.md',
    ]);

    Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript.');

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcription_completed',
      'sermon_id' => $sermon->id,
    ]);

    // Mock analysis service to fail
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->expects($this->once())
      ->method('analyzeSermon')
      ->willThrowException(new \Exception('AI service unavailable'));

    $job = new ProcessTranscriptWithAI($sermon->id);
    $job->handle($mockAnalysisService);

    // Should not throw exception due to graceful degradation
    $processingLog->refresh();
    $this->assertEquals('notification_sent', $processingLog->current_step);
  }

  #[Test]
  public function it_handles_database_constraint_violations(): void
  {
    // Create sermon with existing slug
    $existingSermon = Sermon::factory()->create([
      'slug' => 'test-sermon',
    ]);

    $sermon = Sermon::factory()->create([
      'title' => 'Test Sermon', // Will generate same slug
      'transcript_path' => 'transcripts/sermon_2.md',
    ]);

    Storage::put('transcripts/sermon_2.md', 'This is a sample sermon transcript.');

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'ai_analysis_completed',
      'sermon_id' => $sermon->id,
    ]);

    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->method('analyzeSermon')
      ->willReturn($this->createMockSermonAnalysis('Test Sermon'));

    $job = new UpdateSermonRecord($sermon->id);
    $job->handle($mockAnalysisService);

    // Should handle slug conflict by generating unique slug
    $sermon->refresh();
    $this->assertNotEquals('test-sermon', $sermon->slug);
    $this->assertStringStartsWith('test-sermon-', $sermon->slug);
  }

  #[Test]
  public function it_handles_job_timeout_scenarios(): void
  {
    $sermon = Sermon::factory()->create([
      'filename' => 'large-audio.mp3',
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'timeout-test-id',
      'original_filename' => 'large-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'sermon_record_created',
      'sermon_id' => $sermon->id,
    ]);

    // Mock transcription service to simulate timeout
    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
    $mockTranscriptionService->expects($this->once())
      ->method('transcribe')
      ->willThrowException(new \Exception('Request timeout after 30 minutes'));

    $mockTranscriptionService->expects($this->once())
      ->method('cleanupOnFailure')
      ->with($sermon->id);

    $job = new TranscribeAudio($sermon->id);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Request timeout');

    $job->handle($mockTranscriptionService);
  }

  #[Test]
  public function it_handles_storage_failures(): void
  {
    $sermon = Sermon::factory()->create([
      'filename' => 'test-audio.mp3',
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'storage-test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'sermon_record_created',
      'sermon_id' => $sermon->id,
    ]);

    // Mock transcription service to simulate storage failure
    $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
    $mockTranscriptionService->expects($this->once())
      ->method('transcribe')
      ->willReturn('Test transcript content');

    $mockTranscriptionService->expects($this->once())
      ->method('storeTranscript')
      ->willThrowException(new \Exception('Failed to write transcript to storage'));

    $mockTranscriptionService->expects($this->once())
      ->method('cleanupOnFailure')
      ->with($sermon->id);

    $job = new TranscribeAudio($sermon->id);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Failed to write transcript to storage');

    $job->handle($mockTranscriptionService);
  }

  #[Test]
  public function it_handles_api_rate_limit_errors(): void
  {
    $sermon = Sermon::factory()->create([
      'transcript_path' => 'transcripts/sermon_1.md',
    ]);

    Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript.');

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'rate-limit-test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcription_completed',
      'sermon_id' => $sermon->id,
    ]);

    // Mock analysis service to simulate rate limit
    $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
    $mockAnalysisService->expects($this->once())
      ->method('analyzeSermon')
      ->willThrowException(new \Exception('Rate limit exceeded. Please try again later.'));

    $job = new ProcessTranscriptWithAI($sermon->id);
    $job->handle($mockAnalysisService);

    // Should apply graceful degradation
    $processingLog->refresh();
    $this->assertEquals('notification_sent', $processingLog->current_step);
  }

  #[Test]
  public function it_handles_invalid_file_formats(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create invalid file (text file with audio extension)
    $invalidFile = \Illuminate\Http\UploadedFile::fake()->create('invalid.mp3', 1024, 'text/plain');

    $result = $service->processSermon($invalidFile);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Invalid file type', $result->message);
    $this->assertEquals('PROCESSING_INITIATION_FAILED', $result->errorCode);
  }

  #[Test]
  public function it_handles_oversized_files(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create oversized file (larger than 100MB limit)
    $oversizedFile = \Illuminate\Http\UploadedFile::fake()->create('large.mp3', 101 * 1024, 'audio/mpeg');

    $result = $service->processSermon($oversizedFile);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('File size exceeds maximum limit', $result->message);
    $this->assertEquals('PROCESSING_INITIATION_FAILED', $result->errorCode);
  }

  #[Test]
  public function it_handles_corrupted_files(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create corrupted file with proper MIME type but invalid content
    $corruptedFile = \Illuminate\Http\UploadedFile::fake()->createWithContent('corrupted.mp3', 'invalid audio data');

    // Store the file so it exists when the service tries to process it
    Storage::put('corrupted.mp3', 'invalid audio data');

    $result = $service->processSermon($corruptedFile);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Audio file not found', $result->message);
  }

  #[Test]
  public function it_handles_processing_retry_scenarios(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create failed processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'retry-test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'transcribing_audio_failed',
      'error_message' => 'Temporary service unavailable',
    ]);

    // Test retry
    $result = $service->retryProcessing('retry-test-id');

    $this->assertTrue($result->success);
    $this->assertEquals('retry-test-id', $result->processingId);

    // Verify processing log was reset
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::PENDING, $processingLog->status);
    $this->assertEquals('manual_review_required', $processingLog->current_step);
    $this->assertStringContainsString('Unknown processing step: retry_initiated', $processingLog->error_message);
  }

  #[Test]
  public function it_handles_retry_of_non_failed_processing(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create processing log that's not failed
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'active-test-id',
      'original_filename' => 'test-audio.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcribing_audio',
    ]);

    // Test retry
    $result = $service->retryProcessing('active-test-id');

    $this->assertFalse($result->success);
    $this->assertEquals('PROCESSING_NOT_FAILED', $result->errorCode);
    $this->assertStringContainsString('not in failed state', $result->message);
  }

  #[Test]
  public function it_handles_graceful_degradation_application(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create sermon with failed processing
    $sermon = Sermon::factory()->create([
      'title' => 'Untitled Sermon',
      'slug' => 'untitled-sermon',
    ]);

    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'degradation-test-id',
      'original_filename' => '2024-01-15_morning_service.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'analyzing_transcript_failed',
      'sermon_id' => $sermon->id,
      'error_message' => 'AI service permanently unavailable',
    ]);

    // Apply graceful degradation
    $result = $service->applyGracefulDegradation('degradation-test-id');

    $this->assertTrue($result->success);
    $this->assertEquals('degradation-test-id', $result->processingId);

    // Verify sermon was updated with fallback data
    $sermon->refresh();
    $this->assertNotEquals('Untitled Sermon', $sermon->title);
    $this->assertIsArray($sermon->points);
    $this->assertEquals(['Main Message'], $sermon->points);

    // Verify processing log was marked as completed with degradation
    $processingLog->refresh();
    $this->assertEquals(ProcessingStatus::COMPLETED, $processingLog->status);
    $this->assertEquals('completed_with_degradation', $processingLog->current_step);
    $this->assertStringContainsString('Graceful degradation applied', $processingLog->error_message);
  }

  #[Test]
  public function it_handles_manual_review_marking(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create failed processing log
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'manual-review-test-id',
      'original_filename' => 'problematic-audio.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'transcribing_audio_failed',
      'error_message' => 'Audio quality too poor for transcription',
    ]);

    // Mark for manual review
    $result = $service->markForManualReview('manual-review-test-id', 'Requires human transcription');

    $this->assertTrue($result);

    // Verify processing log was updated
    $processingLog->refresh();
    $this->assertEquals('manual_review_required', $processingLog->current_step);
    $this->assertStringContainsString('Manual Review Note: Requires human transcription', $processingLog->error_message);
  }

  #[Test]
  public function it_provides_detailed_error_information(): void
  {
    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new \App\Services\SermonProcessingService($logger);

    // Create processing log with detailed error
    $sermon = Sermon::factory()->create();
    $processingLog = SermonProcessingLog::create([
      'processing_id' => 'detailed-error-test-id',
      'original_filename' => 'error-test-audio.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'analyzing_transcript_failed',
      'sermon_id' => $sermon->id,
      'error_message' => 'OpenAI API returned 429: Rate limit exceeded',
    ]);

    // Get detailed logs
    $details = $service->getDetailedProcessingLogs('detailed-error-test-id');

    $this->assertTrue($details['found']);
    $this->assertEquals('detailed-error-test-id', $details['processing_log']['processing_id']);
    $this->assertEquals('failed', $details['processing_log']['status']);
    $this->assertStringContainsString('Rate limit exceeded', $details['processing_log']['error_message']);
    $this->assertArrayHasKey('troubleshooting', $details);
    $this->assertArrayHasKey('common_issues', $details['troubleshooting']);
    $this->assertArrayHasKey('suggested_actions', $details['troubleshooting']);
    $this->assertArrayHasKey('recovery_options', $details['troubleshooting']);
  }

  /**
   * Create a mock SermonAnalysis object for testing
   */
  private function createMockSermonAnalysis(string $title = 'God\'s Amazing Love')
  {
    return \App\Data\SermonAnalysis::create(
      title: $title,
      series: 'John Study',
      reference: 'John 3:16-21',
      points: ['First Point', 'Second Point', 'Third Point'],
      summary: 'A sermon about God\'s amazing love for humanity.',
      transcript: 'Sample transcript content'
    );
  }
}
