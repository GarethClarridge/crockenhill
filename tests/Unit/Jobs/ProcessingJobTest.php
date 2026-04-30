<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessingJob;
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

        $job->writeStepLogs();

        $this->assertTrue(true);
    }
}
