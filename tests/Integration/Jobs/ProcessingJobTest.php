<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessingJob;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Support\CancellationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function step_logging_does_not_fail_the_job_when_the_parent_processing_log_is_missing(): void
    {
        $job = new class extends ProcessingJob
        {
            public function writeStepLogs(): void
            {
                $this->initializeStepLogging('missing-processing-log-id');
                $this->logStepStart('transcribing', 'Starting transcription');
                $this->logStepComplete('transcribing', 'Transcription complete');
                $this->logStepFailed('transcribing', 'Transcription failed');
                $this->logStepSkipped('transcribing', 'Transcription skipped');
            }
        };

        $this->expectNotToPerformAssertions();

        $job->writeStepLogs();
    }

    #[Test]
    public function processing_job_is_cancelled_when_log_status_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Cancelled,
        ]);

        $job = new class extends ProcessingJob
        {
            public function checkCancelled(): bool
            {
                return $this->isCancelled();
            }
        };
        $job->setProcessingId($log->processing_id);

        $this->assertTrue($job->checkCancelled());
    }

    #[Test]
    public function processing_job_is_cancelled_when_a_processing_step_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        SermonProcessingStep::factory()->cancelled()->create([
            'processing_id' => $log->processing_id,
        ]);

        $job = new class extends ProcessingJob
        {
            public function checkCancelled(): bool
            {
                return $this->isCancelled();
            }
        };
        $job->setProcessingId($log->processing_id);

        $this->assertTrue($job->checkCancelled());
    }

    #[Test]
    public function processing_job_is_not_cancelled_when_log_is_active(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        $job = new class extends ProcessingJob
        {
            public function checkCancelled(): bool
            {
                return $this->isCancelled();
            }
        };
        $job->setProcessingId($log->processing_id);

        $this->assertFalse($job->checkCancelled());
    }

    /**
     * Pinning test: both cancellation consumers must delegate to CancellationChecker.
     * If a future job re-introduces a hand-rolled check, this test will fail.
     */
    #[Test]
    public function cancellation_check_uses_canonical_helper_in_both_consumers(): void
    {
        $processingJobSource = file_get_contents(app_path('Jobs/ProcessingJob.php'));
        $traitSource = file_get_contents(app_path('Traits/ChecksCancellation.php'));

        $this->assertStringContainsString(
            CancellationChecker::class,
            (string) $processingJobSource,
            'ProcessingJob::isCancelled() must delegate to CancellationChecker',
        );

        $this->assertStringContainsString(
            CancellationChecker::class,
            (string) $traitSource,
            'ChecksCancellation::abortIfCancelled() must delegate to CancellationChecker',
        );
    }
}
