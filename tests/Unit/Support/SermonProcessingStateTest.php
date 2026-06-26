<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Support\SermonProcessingState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingStateTest extends TestCase
{
    private function stateForStatus(ProcessingStatus $status): SermonProcessingState
    {
        $log = new MediaProcessingLog;
        $log->status = $status;

        return new SermonProcessingState($log);
    }

    #[Test]
    public function it_exposes_the_underlying_log_and_status(): void
    {
        $log = new MediaProcessingLog;
        $log->status = ProcessingStatus::Processing;

        $state = new SermonProcessingState($log);

        $this->assertSame($log, $state->log());
        $this->assertSame(ProcessingStatus::Processing, $state->status());
    }

    #[Test]
    public function it_reports_no_state_when_there_is_no_log(): void
    {
        $state = new SermonProcessingState;

        $this->assertNull($state->log());
        $this->assertNull($state->status());
        $this->assertFalse($state->isComplete());
        $this->assertFalse($state->isFailed());
        $this->assertFalse($state->isInProgress());
    }

    #[Test]
    public function it_reports_completion(): void
    {
        $state = $this->stateForStatus(ProcessingStatus::Completed);

        $this->assertTrue($state->isComplete());
        $this->assertFalse($state->isFailed());
        $this->assertFalse($state->isInProgress());
    }

    #[Test]
    public function it_reports_failure(): void
    {
        $state = $this->stateForStatus(ProcessingStatus::Failed);

        $this->assertTrue($state->isFailed());
        $this->assertFalse($state->isComplete());
        $this->assertFalse($state->isInProgress());
    }

    #[Test]
    public function it_reports_in_progress(): void
    {
        $state = $this->stateForStatus(ProcessingStatus::Processing);

        $this->assertTrue($state->isInProgress());
        $this->assertFalse($state->isComplete());
        $this->assertFalse($state->isFailed());
    }
}
