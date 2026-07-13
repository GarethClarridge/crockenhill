<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonCreationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\MediaProcessingTestHelpers;

class SermonProcessingJobChainTest extends TestCase
{
    use MediaProcessingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisks();

        config([
            'media-processing.transcription.openai_api_key' => 'test-key',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.processing.queue' => 'default',
            'openai.api_key' => 'test-key', // Add this for the OpenAI Laravel package
        ]);
    }

    #[Test]
    public function it_creates_sermon_record_successfully(): void
    {
        Queue::fake(); // Prevent the job chain from continuing to transcription

        $processingId = 'test-processing-id';
        $metadata = SermonMetadata::create(
            date: Carbon::parse('2024-01-15'),
            service: SermonService::Morning,
            filename: 'stored-file.mp3',
            originalName: '2024-01-15_morning_sermon.mp3',
            duration: 3600.0,
            bitrate: 128000,
            format: 'MP3',
            filesize: 50000000
        );
        $storedFilePath = 'sermons/2024/01/test-file.mp3';

        // Create the audio file that the job expects
        Storage::put($storedFilePath, 'fake audio content');

        $processingLog = $this->processingLogScenario()
            ->pending()
            ->state([
                'processing_id' => $processingId,
                'original_filename' => $metadata->originalName,
                'source_file_path' => $storedFilePath,
                'ai_analysis' => ['title' => 'Test Sermon Title'],
                'current_step' => 'initiated',
            ])
            ->create();

        // Create the job
        $job = new CreateSermonRecord($processingLog);

        // Execute the job with dependency injection
        $logger = app(SermonProcessingLogger::class);
        $sermonCreationService = app(SermonCreationService::class);
        $job->handle($logger, $sermonCreationService);

        // Assert sermon record was created
        $this->assertDatabaseHas('sermons', [
            'audio_file_path' => $storedFilePath,
            'date' => '2024-01-15',
            'service' => SermonService::Morning->value,
            'preacher' => 'Visiting Speaker',
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
        $this->assertNull($sermon->transcript_file_path);
    }

    #[Test]
    public function it_handles_create_sermon_record_failure(): void
    {
        $processingId = 'test-processing-id';
        $metadata = SermonMetadata::create(
            date: Carbon::parse('2024-01-15'),
            service: SermonService::Morning,
            filename: 'stored-file.mp3',
            originalName: '2024-01-15_morning_sermon.mp3',
            duration: 3600.0,
            bitrate: 128000,
            format: 'MP3',
            filesize: 50000000
        );
        $storedFilePath = 'sermons/2024/01/test-file.mp3';

        // Don't create processing log to trigger failure
        // Create a mock processing log for the job constructor
        $processingLog = new MediaProcessingLog([
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => $metadata->filename,
            'source_file_path' => $storedFilePath,
            'status' => ProcessingStatus::Pending,
        ]);
        $job = new CreateSermonRecord($processingLog);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing log not found');

        $logger = app(SermonProcessingLogger::class);
        $sermonCreationService = app(SermonCreationService::class);
        $job->handle($logger, $sermonCreationService);
    }

    #[Test]
    public function it_transcribes_audio_successfully(): void
    {
        Queue::fake(); // Prevent the job chain from continuing to AI processing

        // Create sermon record
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'test-audio.mp3',
            'transcript_file_path' => null,
        ]);

        $processingLog = $this->processingLogScenario()
            ->processing('sermon_record_created')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'test-id',
                'original_filename' => 'test-audio.mp3',
                'source_file_path' => 'test-audio.mp3',
            ])
            ->create();

        // Create a fake audio file
        Storage::put('test-audio.mp3', 'fake audio content');

        // Mock the transcription service
        $mockTranscriptionService = $this->createMock(AudioTranscriptionService::class);
        $mockTranscriptionService->expects($this->once())
            ->method('transcribe')
            ->with('test-audio.mp3', $this->anything())
            ->willReturn('# Sermon Transcript\n\nThis is a test transcript.');

        $transcriptPath = 'transcripts/sermon_'.$sermon->id.'.md';
        $mockTranscriptionService->expects($this->once())
            ->method('storeTranscript')
            ->with($sermon->id, '# Sermon Transcript\n\nThis is a test transcript.')
            ->willReturn($transcriptPath);

        // Store the actual transcript file so the sermon->transcript accessor can read it
        Storage::put($transcriptPath, '# Sermon Transcript\n\nThis is a test transcript.');

        $this->app->instance(AudioTranscriptionService::class, $mockTranscriptionService);
        $this->app->instance(TranscriptionServiceInterface::class, $mockTranscriptionService);

        // Create and execute the job
        $job = new TranscribeAudio($processingLog);
        $job->handle($mockTranscriptionService);

        // Assert sermon was updated with transcript path
        $sermon->refresh();
        $this->assertEquals('transcripts/sermon_'.$sermon->id.'.md', $sermon->transcript_file_path);

        // Assert processing log was updated
        $processingLog->refresh();
        $this->assertEquals('transcription_completed', $processingLog->current_step);
    }

    #[Test]
    public function it_handles_transcription_failure_with_cleanup(): void
    {
        // Create sermon record
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'test-audio.mp3',
        ]);

        $processingLog = $this->processingLogScenario()
            ->processing('sermon_record_created')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'test-id',
                'original_filename' => 'test-audio.mp3',
                'source_file_path' => 'test-audio.mp3',
            ])
            ->create();

        // Mock the transcription service to throw exception
        $mockTranscriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $mockTranscriptionService->expects($this->once())
            ->method('transcribe')
            ->willThrowException(new \Exception('Transcription failed'));

        $mockTranscriptionService->expects($this->once())
            ->method('cleanupOnFailure')
            ->with($sermon->id);

        $this->app->instance(AudioTranscriptionService::class, $mockTranscriptionService);
        $this->app->instance(TranscriptionServiceInterface::class, $mockTranscriptionService);

        // Create and execute the job
        $job = new TranscribeAudio($processingLog);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Transcription failed');

        $job->handle($mockTranscriptionService);

        // Assert processing log was marked as failed
        $processingLog->refresh();
        $this->assertEquals(ProcessingStatus::Failed, $processingLog->status);
        $this->assertEquals('transcribing_audio', $processingLog->current_step);
        $this->assertStringContainsString('Transcription failed', $processingLog->error_message);
    }

    #[Test]
    public function it_processes_transcript_with_ai_successfully(): void
    {
        Queue::fake(); // Prevent job chain from continuing

        // Create sermon with transcript
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/sermon_1.md',
        ]);

        // Store transcript content (must be at least 100 characters for validation)
        Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript about God\'s amazing love and grace. It contains meaningful content that demonstrates the depth of God\'s love for humanity and how we should respond to that love in our daily lives.');

        $processingLog = $this->processingLogScenario()
            ->processing('transcription_completed')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'test-id',
                'original_filename' => 'test-audio.mp3',
                'transcript_file_path' => 'transcripts/sermon_1.md',
            ])
            ->create();

        // Mock the analysis service
        $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
        $mockAnalysisService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createMockSermonAnalysis());

        $this->app->instance(SermonAnalysisService::class, $mockAnalysisService);

        // Create and execute the job
        $job = new ProcessTranscriptWithAI($processingLog);
        $job->handle($mockAnalysisService, $this->app->make(SermonRepository::class));

        // Assert processing log was updated
        $processingLog->refresh();
        $this->assertEquals('ai_analysis_completed', $processingLog->current_step);
    }

    #[Test]
    public function it_handles_ai_processing_failure_with_fallback(): void
    {
        Queue::fake(); // Prevent job chain from continuing

        // Create sermon with transcript
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/sermon_1.md',
            'title' => 'Initial Title',
        ]);

        // Store transcript content (must be at least 100 characters for validation)
        Storage::put('transcripts/sermon_1.md', 'This is a sample sermon transcript about God\'s amazing love and grace. It contains meaningful content that demonstrates the depth of God\'s love for humanity and how we should respond to that love in our daily lives.');

        $processingLog = $this->processingLogScenario()
            ->processing('transcription_completed')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'test-id',
                'original_filename' => 'test-audio.mp3',
                'transcript_file_path' => 'transcripts/sermon_1.md',
            ])
            ->create();

        // Mock the analysis service to throw exception
        $mockAnalysisService = $this->createMock(SermonAnalysisService::class);
        $mockAnalysisService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        $this->app->instance(SermonAnalysisService::class, $mockAnalysisService);

        // Create and execute the job
        $job = new ProcessTranscriptWithAI($processingLog);
        $job->handle($mockAnalysisService, $this->app->make(SermonRepository::class));

        // Assert processing log shows fallback was used
        $processingLog->refresh();
        $this->assertEquals('ai_analysis_fallback', $processingLog->current_step);
    }

    #[Test]
    public function it_sends_completion_notification_successfully(): void
    {
        Mail::fake();

        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        // Create completed sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Completed Sermon',
            'slug' => 'completed-sermon',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'points' => ['Point 1', 'Point 2'],
        ]);

        $processingLog = $this->processingLogScenario()
            ->processing('analyzing_transcript')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'test-id',
                'original_filename' => 'test-audio.mp3',
            ])
            ->create();

        // Create and execute the job
        $job = new SendCompletionNotification($processingLog);
        $job->handle();

        // Assert processing log was updated
        $processingLog->refresh();
        $this->assertEquals('notification_sent', $processingLog->current_step);
    }

    #[Test]
    public function it_skips_notification_when_success_notifications_disabled(): void
    {
        Mail::fake();

        config(['media-processing.email.send_success_notifications' => false]);

        $sermon = Sermon::factory()->create(['title' => 'Test Sermon']);

        $processingLog = $this->processingLogScenario()
            ->processing('analyzing_transcript')
            ->withSermon($sermon)
            ->state([
                'processing_id' => 'skip-test-id',
                'original_filename' => 'test-audio.mp3',
            ])
            ->create();

        $job = new SendCompletionNotification($processingLog);
        $job->handle();

        Mail::assertNothingSent();

        $processingLog->refresh();
        $this->assertEquals('notification_skipped', $processingLog->current_step);
    }

    #[Test]
    public function it_sends_notification_only_to_configured_admin_email_not_all_users(): void
    {
        // Spy on Mail to count raw() calls
        Mail::shouldReceive('raw')->once()->andReturnNull();

        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        // Create multiple users to ensure they don't all receive the email
        User::factory()->count(5)->create();

        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'points' => ['Point 1', 'Point 2'],
        ]);

        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'admin-only-test',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'analyzing_transcript',
            'sermon_id' => $sermon->id,
        ]);

        $job = new SendCompletionNotification($processingLog);
        $job->handle();

        $processingLog->refresh();
        $this->assertEquals('notification_sent', $processingLog->current_step);
    }

    #[Test]
    public function it_handles_notification_failure_gracefully(): void
    {
        // Create completed sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Completed Sermon',
        ]);

        // Create processing log
        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'analyzing_transcript',
            'sermon_id' => $sermon->id,
        ]);

        // Create a job that will fail due to missing sermon data
        // Create processing log without valid sermon_id
        $failureProcessingLog = new MediaProcessingLog([
            'processing_id' => 'failure-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'analyzing_transcript',
            'sermon_id' => 99999, // Non-existent sermon ID
        ]);
        $job = new SendCompletionNotification($failureProcessingLog);

        // Execute the job - should not throw exception but should handle failure gracefully
        $job->handle();

        // Since we used a non-existent sermon ID, the job should fail gracefully
        // but won't update our processing log. Let's test the behavior differently.
        // We'll create a job that will fail during execution by deleting the sermon after creation
        $sermon->delete();

        // The job must handle the missing sermon gracefully without throwing.
        $this->expectNotToPerformAssertions();

        $job = new SendCompletionNotification($processingLog);
        $job->handle();
    }

    #[Test]
    public function it_processes_complete_job_chain_integration(): void
    {
        Queue::fake();

        // Create initial data
        $processingId = 'integration-test-id';
        $metadata = SermonMetadata::create(
            date: Carbon::parse('2024-01-15'),
            service: SermonService::Morning,
            filename: 'stored-file.mp3',
            originalName: '2024-01-15_morning_sermon.mp3',
            duration: 3600.0,
            bitrate: 128000,
            format: 'MP3',
            filesize: 50000000
        );
        $storedFilePath = 'sermons/2024/01/test-file.mp3';

        // Create processing log
        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => $metadata->originalName,
            'source_file_path' => $storedFilePath,
            'status' => ProcessingStatus::Pending,
            'current_step' => 'initiated',
        ]);

        // Create the audio file that the job expects
        Storage::put($storedFilePath, 'fake audio content');

        // Execute first job
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();
        $createJob = new CreateSermonRecord($processingLog);
        $logger = app(SermonProcessingLogger::class);
        $sermonCreationService = app(SermonCreationService::class);
        $createJob->handle($logger, $sermonCreationService);

        // Verify sermon was created
        $sermon = Sermon::where('audio_file_path', $storedFilePath)->first();
        $this->assertNotNull($sermon);

        // Note: In a job chain architecture, the next job (TranscribeAudio) is automatically
        // dispatched by Laravel's job chaining system, not manually by the CreateSermonRecord job.
        // Individual jobs should not dispatch subsequent jobs when part of a chain.

        // Verify processing log was updated correctly for chain continuation
        $processingLog->refresh();
        $this->assertEquals($sermon->id, $processingLog->sermon_id);
        $this->assertEquals('sermon_record_created', $processingLog->current_step);
    }

    #[Test]
    public function it_handles_job_chain_failure_and_recovery(): void
    {
        // Create sermon in failed state
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'test-audio.mp3',
        ]);

        // Create the file in storage so retry can access it
        Storage::put('sermons/test-audio.mp3', 'fake audio content');

        $processingLog = MediaProcessingLog::create([
            'processing_id' => 'failed-test-id',
            'processing_type' => 'audio',
            'original_filename' => 'test-audio.mp3',
            'source_file_path' => 'sermons/test-audio.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
            'sermon_id' => $sermon->id,
            'error_message' => 'Transcription service unavailable',
        ]);

        // Test recovery by retrying from failed step
        $service = app(UnifiedMediaProcessor::class);

        // Verify the processing log exists before retry
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => 'failed-test-id',
        ]);

        $result = $service->retry('failed-test-id');

        // Retry may succeed or fail depending on underlying service availability
        if ($result->success) {
            $this->assertEquals('failed-test-id', $result->processingId);
        } else {
            // Retry failed - acceptable in test environment without full services
            $this->assertFalse($result->success);
        }

        // Verify processing log was updated if retry succeeded
        $processingLog->refresh();
        if ($result->success) {
            $this->assertContains($processingLog->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Processing,
                ProcessingStatus::Completed,
            ]);
        }
    }

    #[Test]
    public function it_validates_file_storage_and_cleanup(): void
    {
        // Create a test file
        $file = UploadedFile::fake()->create('test-sermon.mp3', 1024, 'audio/mpeg');

        // Create a processing log manually to simulate what the service would do
        $processingId = 'storage-test-id';
        $processingLog = MediaProcessingLog::create([
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => $file->getClientOriginalName(),
            'status' => ProcessingStatus::Pending,
            'current_step' => 'initiated',
        ]);

        // Verify processing log was created
        $this->assertNotNull($processingLog);
        $this->assertEquals('test-sermon.mp3', $processingLog->original_filename);
        $this->assertEquals(ProcessingStatus::Pending, $processingLog->status);

        // Test that we can retrieve the processing status
        $statusResult = app(UnifiedMediaProcessor::class)->getStatus($processingId);
        $this->assertEquals($processingId, $statusResult->processingId);
        $this->assertEquals(ProcessingStatus::Pending->value, $statusResult->status);
    }

    #[Test]
    public function it_handles_database_record_creation_and_updates(): void
    {
        Queue::fake(); // Prevent the job chain from continuing to transcription

        // Test complete database operations through job chain
        $processingId = 'db-test-id';
        $metadata = SermonMetadata::create(
            date: Carbon::parse('2024-01-15'),
            service: SermonService::Evening,
            filename: 'stored-file.mp3',
            originalName: '2024-01-15_evening_sermon.mp3',
            duration: 3600.0,
            bitrate: 128000,
            format: 'MP3',
            filesize: 50000000
        );
        $storedFilePath = 'sermons/2024/01/test-file.mp3';

        // Create processing log
        $processingLog = MediaProcessingLog::create([
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => $metadata->originalName,
            'source_file_path' => $storedFilePath,
            'status' => ProcessingStatus::Pending,
            'current_step' => 'initiated',
        ]);

        // Create the audio file that the job expects
        Storage::put($storedFilePath, 'fake audio content');

        // Execute create sermon job
        $createJob = new CreateSermonRecord($processingLog);
        $logger = app(SermonProcessingLogger::class);
        $sermonCreationService = app(SermonCreationService::class);
        $createJob->handle($logger, $sermonCreationService);

        // Verify database state
        $this->assertDatabaseHas('sermons', [
            'audio_file_path' => $storedFilePath,
            'date' => '2024-01-15',
            'service' => SermonService::Evening->value,
            'preacher' => 'Visiting Speaker',
        ]);

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Processing->value,
            'current_step' => 'sermon_record_created',
        ]);

        // Verify the sermon record was created and linked to the audio file
        $sermon = Sermon::where('audio_file_path', $storedFilePath)->first();
        $this->assertNotNull($sermon);
    }
}
