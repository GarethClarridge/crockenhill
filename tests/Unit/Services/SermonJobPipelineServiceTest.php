<?php

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingPipelineBuilder;
use App\Services\SermonJobPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonJobPipelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonJobPipelineService $service;

    private ProcessingPipelineBuilder $pipelineBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);
        $this->service = new SermonJobPipelineService($this->pipelineBuilder);
    }

    // --- dispatchProcessingJobs() ---

    #[Test]
    public function it_dispatches_job_chain_on_default_queue(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->audio()->pending()->create();
        $jobs = [new \App\Jobs\TestJob];

        $this->service->dispatchProcessingJobs($jobs, $log);

        Bus::assertChained([
            \App\Jobs\TestJob::class,
        ]);
    }

    #[Test]
    public function it_dispatches_on_livestream_audio_queue_for_livestream_source(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->audio()->pending()->create();
        $jobs = [new \App\Jobs\TestJob];
        $metadata = ['source_type' => 'livestream'];

        $this->service->dispatchProcessingJobs($jobs, $log, $metadata);

        Bus::assertChained([
            \App\Jobs\TestJob::class,
        ]);
    }

    #[Test]
    public function it_dispatches_on_livestream_audio_queue_for_video_upload_source(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->audio()->pending()->create();
        $jobs = [new \App\Jobs\TestJob];
        $metadata = ['source_type' => 'video_upload'];

        $this->service->dispatchProcessingJobs($jobs, $log, $metadata);

        Bus::assertChained([
            \App\Jobs\TestJob::class,
        ]);
    }

    // --- createProcessingLogWithLivestreamContext() ---

    #[Test]
    public function it_creates_processing_log_with_livestream_context(): void
    {
        $processingId = 'test-123';
        $filename = 'livestream-2026-01-15.mp3';
        $metadata = ['livestream_processing_id' => 'ls-456'];

        $log = $this->service->createProcessingLogWithLivestreamContext(
            $processingId,
            $filename,
            $metadata
        );

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => 'test-123',
            'original_filename' => 'livestream-2026-01-15.mp3',
            'status' => ProcessingStatus::PENDING->value,
        ]);
        $this->assertStringContainsString('ls-456', $log->current_step);
    }

    #[Test]
    public function it_creates_processing_log_without_livestream_id(): void
    {
        $log = $this->service->createProcessingLogWithLivestreamContext(
            'test-789',
            'audio.mp3',
            []
        );

        $this->assertEquals('initiated_from_livestream', $log->current_step);
    }

    // --- canRetryProcessing() ---

    #[Test]
    public function it_allows_retry_for_recent_failed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'analyzing_transcript',
            'error_message' => 'API timeout',
        ]);

        $this->assertTrue($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_disallows_retry_for_manual_review_items(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'manual_review_required',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_disallows_retry_for_old_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'created_at' => now()->subDays(10),
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_disallows_retry_for_file_not_found_errors(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Error: file_not_found in storage',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_disallows_retry_for_invalid_file_format_errors(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Invalid_file_format detected',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_disallows_retry_for_storage_failure_errors(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Storage_failure on disk',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    // --- requiresManualReview() ---

    #[Test]
    public function it_requires_manual_review_for_items_already_marked(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'manual_review_required',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_requires_manual_review_for_sermon_creation_failures(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'creating_sermon_record',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_requires_manual_review_for_transcription_failures(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'transcribing_audio',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_requires_manual_review_for_file_not_found_errors(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'some_step',
            'error_message' => 'File not found at expected path',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_requires_manual_review_for_storage_failure_errors(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'some_step',
            'error_message' => 'Storage failure occurred',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_does_not_require_manual_review_for_regular_failures(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'analyzing_transcript',
            'error_message' => 'API returned unexpected response',
        ]);

        $this->assertFalse($this->service->requiresManualReview($log));
    }

    // --- retryProcessing() ---

    #[Test]
    public function it_returns_failure_when_processing_log_not_found_for_retry(): void
    {
        $result = $this->service->retryProcessing('nonexistent-id');

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_LOG_NOT_FOUND', $result->errorCode);
    }

    #[Test]
    public function it_returns_failure_when_processing_is_not_in_failed_state(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $result = $this->service->retryProcessing($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_NOT_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_returns_failure_when_completed_processing_retry_attempted(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $result = $this->service->retryProcessing($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_NOT_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_resets_failed_processing_to_pending_on_retry(): void
    {
        Bus::fake();

        $sermon = \App\Models\Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'analyzing_transcript',
            'error_message' => 'API timeout',
            'sermon_id' => $sermon->id,
        ]);

        $result = $this->service->retryProcessing($log->processing_id);

        $this->assertTrue($result->success);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::PENDING, $log->status);
        $this->assertNull($log->error_message);
    }

    // --- buildSermonProcessingPipeline() ---

    #[Test]
    public function it_delegates_pipeline_building_to_builder(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();
        $expectedJobs = [new \App\Jobs\TestJob];

        $this->pipelineBuilder
            ->method('buildAudioPipeline')
            ->with($log)
            ->willReturn($expectedJobs);

        $jobs = $this->service->buildSermonProcessingPipeline($log, '/path/to/file.mp3');

        $this->assertCount(1, $jobs);
    }
}
