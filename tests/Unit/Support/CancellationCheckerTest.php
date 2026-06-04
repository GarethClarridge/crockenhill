<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Support\CancellationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancellationCheckerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_false_when_nothing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        $this->assertFalse(CancellationChecker::isCancelled($log->processing_id));
    }

    #[Test]
    public function it_detects_log_level_cancellation(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Cancelled,
        ]);

        $this->assertTrue(CancellationChecker::isCancelled($log->processing_id));
    }

    #[Test]
    public function it_detects_step_level_cancellation_even_when_log_is_not_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
        ]);

        SermonProcessingStep::factory()->cancelled()->create([
            'processing_id' => $log->processing_id,
        ]);

        $this->assertTrue(CancellationChecker::isCancelled($log->processing_id));
    }

    #[Test]
    public function it_returns_false_for_an_unknown_processing_id(): void
    {
        $this->assertFalse(CancellationChecker::isCancelled('non-existent-id'));
    }
}
