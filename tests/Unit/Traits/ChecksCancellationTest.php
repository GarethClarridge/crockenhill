<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Traits\ChecksCancellation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChecksCancellationTest extends TestCase
{
    use RefreshDatabase;

    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();

        $this->subject = new class
        {
            use ChecksCancellation;

            public MediaProcessingLog $processingLog;

            public function checkAbortIfCancelled(string $jobClass): bool
            {
                return $this->abortIfCancelled($jobClass);
            }
        };
    }

    #[Test]
    public function it_returns_true_if_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Cancelled,
        ]);

        $this->subject->processingLog = $log;

        $this->assertTrue($this->subject->checkAbortIfCancelled('TestJob'));
        $this->assertTrue($this->subject->processingLog->isCancelled());

        // Security: We assert that a log entry is created for audit/visibility,
        // but avoid exact string matching to prevent test fragility.
        Log::shouldHaveReceived('info')->once();
    }

    #[Test]
    public function it_returns_false_if_processing_is_not_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        $this->subject->processingLog = $log;

        $this->assertFalse($this->subject->checkAbortIfCancelled('TestJob'));
        $this->assertSame($log->id, $this->subject->processingLog->id);
        $this->assertFalse($this->subject->processingLog->isCancelled());

        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function it_returns_true_if_processing_log_no_longer_exists(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $this->subject->processingLog = $log;

        // Delete the log from DB so fresh() returns null
        $log->delete();

        $this->assertTrue($this->subject->checkAbortIfCancelled('TestJob'));
        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function it_refreshes_the_processing_log_instance(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        $this->subject->processingLog = $log;

        // Update in DB behind the scenes
        $log->update(['status' => ProcessingStatus::Cancelled]);

        $this->assertTrue($this->subject->checkAbortIfCancelled('TestJob'));
        $this->assertTrue($this->subject->processingLog->isCancelled());

        // Security: We assert that a log entry is created for audit/visibility,
        // but avoid exact string matching to prevent test fragility.
        Log::shouldHaveReceived('info')->once();
    }

    #[Test]
    public function it_returns_true_when_a_processing_step_is_cancelled_and_log_is_active(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        SermonProcessingStep::factory()->cancelled()->create([
            'processing_id' => $log->processing_id,
        ]);

        $this->subject->processingLog = $log;

        $this->assertTrue($this->subject->checkAbortIfCancelled('TestJob'));

        // Security: We assert that a log entry is created for audit/visibility,
        // but avoid exact string matching to prevent test fragility.
        Log::shouldHaveReceived('info')->once();
    }
}
