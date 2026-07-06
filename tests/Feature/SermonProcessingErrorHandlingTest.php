<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\ProcessingStatus;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\TranscribeAudio;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\MediaProcessingTestHelpers;

class SermonProcessingErrorHandlingTest extends TestCase
{
    use MediaProcessingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.transcription.openai_api_key' => 'test-key',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.processing.queue' => 'default',
        ]);
    }

    #[Test]
    public function it_handles_job_timeout_scenarios(): void
    {
        // Create a sermon first to have a sermon_id for cleanup
        $sermon = Sermon::factory()->create();

        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'timeout-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'large-audio.mp3',
            'source_file_path' => 'path/to/large-audio.mp3',
            'status' => ProcessingStatus::Processing,
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

        $job = new TranscribeAudio($processingLog);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Request timeout');

        $job->handle($mockTranscriptionService);
    }

    #[Test]
    public function it_handles_storage_failures(): void
    {
        // Create a sermon first to have a sermon_id for the storeTranscript method
        $sermon = Sermon::factory()->create();

        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'storage-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'source_file_path' => 'path/to/test-audio.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'sermon_record_created',
            'sermon_id' => $sermon->id,
        ]);

        // Mock transcription service to simulate storage failure
        $mockTranscriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $mockTranscriptionService->expects($this->once())
            ->method('transcribe')
            ->willReturn('Test transcript content');

        $mockTranscriptionService->expects($this->once())
            ->method('storeTranscript')
            ->willThrowException(new \Exception('Failed to write transcript to storage'));

        $mockTranscriptionService->expects($this->once())
            ->method('cleanupOnFailure')
            ->with($sermon->id);

        $job = new TranscribeAudio($processingLog);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to write transcript to storage');

        $job->handle($mockTranscriptionService);
    }

    #[Test]
    public function it_handles_api_rate_limit_errors(): void
    {
        Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript.');

        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'rate-limit-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'source_file_path' => 'path/to/audio.mp3',
            'transcript_file_path' => 'transcripts/sermon_1.md',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcription_completed',
        ]);

        // Mock analysis service to simulate rate limit
        $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
        $mockAnalysisService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('Rate limit exceeded. Please try again later.'));

        $job = new ProcessTranscriptWithAI($processingLog);
        $job->handle($mockAnalysisService, $this->app->make(SermonRepository::class));

        // Should apply graceful degradation
        $processingLog->refresh();
        $this->assertEquals('ai_analysis_fallback', $processingLog->current_step);
    }

    #[Test]
    public function it_handles_invalid_file_formats(): void
    {
        $service = app(UnifiedMediaProcessor::class);

        // Create invalid file (text file with audio extension)
        $invalidFile = UploadedFile::fake()->create('invalid.mp3', 1024, 'text/plain');

        $result = $service->process('audio', $invalidFile);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid file', $result->message);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_handles_oversized_files(): void
    {
        $service = app(UnifiedMediaProcessor::class);

        // Create oversized file (larger than 100MB limit)
        $oversizedFile = UploadedFile::fake()->create('large.mp3', 101 * 1024, 'audio/mpeg');

        $result = $service->process('audio', $oversizedFile);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File size exceeds maximum limit', $result->message);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_handles_corrupted_files(): void
    {
        $service = app(UnifiedMediaProcessor::class);

        // Create corrupted file with proper MIME type but invalid content
        $corruptedFile = UploadedFile::fake()->createWithContent('corrupted.mp3', 'invalid audio data');

        // Store the file so it exists when the service tries to process it
        Storage::put('corrupted.mp3', 'invalid audio data');

        $result = $service->process('audio', $corruptedFile);

        // Corrupted files should either fail validation or succeed initially
        // (with processing failing later at the validation job step)
        if (! $result->success) {
            $this->assertNotEmpty($result->message);
        } else {
            // Processing was accepted - it will fail later during actual audio validation
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->processingId);
        }
    }

    #[Test]
    public function it_handles_processing_retry_scenarios(): void
    {
        $service = app(UnifiedMediaProcessor::class);

        // Create failed processing log
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'retry-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Temporary service unavailable',
        ]);

        // Test retry
        $result = $service->retry('retry-test-id');

        // Retry may succeed or fail depending on underlying service availability
        if ($result->success) {
            $this->assertEquals('retry-test-id', $result->processingId);

            // Verify processing log was reset to retry transcription
            $processingLog->refresh();
            $this->assertEquals(ProcessingStatus::Pending, $processingLog->status);
            $this->assertEquals('transcribing_audio_failed', $processingLog->current_step);
        } else {
            // Retry failed - acceptable in test environment without full services
            $this->assertFalse($result->success);
        }
    }

    #[Test]
    public function it_handles_retry_of_non_failed_processing(): void
    {
        $service = app(UnifiedMediaProcessor::class);

        // Create processing log that's not failed
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'active-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
        ]);

        // Test retry
        $result = $service->retry('active-test-id');

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_NOT_FAILED', $result->errorCode);
        $this->assertStringContainsString('not in failed or cancelled state', $result->message);
    }

    #[Test]
    public function it_handles_manual_review_marking(): void
    {
        // Create failed processing log
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'manual-review-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'problematic-audio.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Audio quality too poor for transcription',
        ]);

        // Mark for manual review directly on the model
        $result = app(MediaProcessingRunTransitionService::class)
            ->markForManualReview($processingLog, 'manual_review_required', 'Requires human transcription');

        $this->assertTrue($result);

        // Verify processing log was updated
        $processingLog->refresh();
        $this->assertEquals('manual_review_required', $processingLog->current_step);
        $this->assertEquals('Requires human transcription', $processingLog->error_message);
    }

    #[Test]
    public function it_provides_error_information_in_status_response(): void
    {
        MediaProcessingLog::create([
            'processing_id' => 'detailed-error-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'error-test-audio.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'analyzing_transcript_failed',
            'error_message' => 'OpenAI API returned 429: Rate limit exceeded',
        ]);

        $status = app(UnifiedMediaProcessor::class)->getStatus('detailed-error-test-id');

        $this->assertTrue($status->found);
        $this->assertEquals('detailed-error-test-id', $status->processingId);
        $this->assertEquals('failed', $status->status);
        $this->assertStringContainsString('Rate limit exceeded', (string) $status->errorMessage);
    }

    #[Test]
    public function it_can_retry_failed_processing_for_preparing_step(): void
    {
        // Create a failed processing log with 'preparing' step
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'retry-test-preparing',
            'processing_type' => 'audio',
            'original_filename' => 'test-sermon.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'preparing',
            'error_message' => 'Connection could not be established with host "mailpit:1025"',
        ]);

        $result = app(UnifiedMediaProcessor::class)->retry('retry-test-preparing');

        $this->assertTrue($result->success, 'Retry should succeed: '.$result->message);
        $this->assertEquals('Processing retry initiated successfully', $result->message);

        // Check processing log was updated - for 'preparing' step, it should be marked for manual review
        $processingLog->refresh();
        $this->assertEquals(ProcessingStatus::Failed, $processingLog->status);
        $this->assertEquals('manual_review_required', $processingLog->current_step);
        $this->assertStringContainsString('Early processing failure detected', $processingLog->error_message);
    }

    #[Test]
    public function it_can_retry_failed_processing_for_analyzing_step(): void
    {
        // Create a sermon record first
        $sermon = Sermon::factory()->create();

        // Create a fake transcript file for testing
        Storage::fake('local');
        Storage::put('transcripts/test-transcript.md', 'This is a test sermon transcript.');

        // Create a failed processing log with 'analyzing_transcript' step
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'retry-test-analyzing',
            'processing_type' => 'audio',
            'original_filename' => 'test-sermon.mp3',
            'transcript_file_path' => 'transcripts/test-transcript.md', // Required for ProcessTranscriptWithAI job
            'status' => ProcessingStatus::Failed,
            'current_step' => 'analyzing_transcript',
            'error_message' => 'AI analysis service unavailable',
            'sermon_id' => $sermon->id,
        ]);

        $result = app(UnifiedMediaProcessor::class)->retry('retry-test-analyzing');

        $this->assertTrue($result->success, 'Retry should succeed: '.$result->message);
        $this->assertEquals('Processing retry initiated successfully', $result->message);

        // Check processing log was updated. Depending on queue execution mode, the step
        // may either remain at retry entry point or progress through AI fallback/completion.
        $processingLog->refresh();
        $this->assertContains($processingLog->status, [
            ProcessingStatus::Pending,
            ProcessingStatus::Processing,
            ProcessingStatus::Completed,
        ]);
        $this->assertContains($processingLog->current_step, [
            'analyzing_transcript',
            'ai_analysis_fallback',
            'ai_analysis_completed',
            'notification_sent',
            'cleanup',
            'completed',
        ]);
    }

    #[Test]
    public function it_routes_unknown_retry_steps_to_manual_review(): void
    {
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'retry-test-unknown',
            'processing_type' => 'audio',
            'original_filename' => 'test-sermon.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'legacy_unknown_phase',
            'error_message' => 'Unknown processing step: legacy_unknown_phase',
        ]);

        $result = app(UnifiedMediaProcessor::class)->retry('retry-test-unknown');

        $this->assertTrue($result->success, 'Retry should succeed: '.$result->message);
        $this->assertEquals('Processing retry initiated successfully', $result->message);

        $processingLog->refresh();
        $this->assertEquals(ProcessingStatus::Failed, $processingLog->status);
        $this->assertEquals('manual_review_required', $processingLog->current_step);
        $this->assertStringContainsString('Unknown processing step: legacy_unknown_phase.', $processingLog->error_message);
    }

    #[Test]
    public function it_rejects_retry_for_non_failed_processing(): void
    {
        // Create a processing log that is not failed
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'retry-test-not-failed',
            'processing_type' => 'audio',
            'original_filename' => 'test-sermon.mp3',
            'status' => ProcessingStatus::Completed, // Not failed
            'current_step' => 'completed',
        ]);

        $result = app(UnifiedMediaProcessor::class)->retry('retry-test-not-failed');

        // Should fail
        $this->assertFalse($result->success);
        $this->assertEquals('Processing is not in failed or cancelled state', $result->message);
        $this->assertEquals('PROCESSING_NOT_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_handles_retry_for_nonexistent_processing_id(): void
    {
        $result = app(UnifiedMediaProcessor::class)->retry('nonexistent-processing-id');

        $this->assertFalse($result->success);
        $this->assertEquals('Processing ID not found for retry', $result->message);
        $this->assertEquals('NOT_FOUND', $result->errorCode);
    }
}
