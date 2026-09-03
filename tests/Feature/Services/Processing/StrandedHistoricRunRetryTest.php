<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Processing;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * A run whose first job fails inside the `Queue::before` staging activation is
 * never marked, so it sits `pending`/`processing` with nothing queued. Before
 * 2026-09-03 `isRetryable()` rejected both states and the only recovery was to
 * force the row by hand.
 */
class StrandedHistoricRunRetryTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_lets_the_retry_path_reach_a_historic_run_stranded_with_nothing_in_flight(): void
    {
        $this->fakeQueueDepth(0);
        $run = $this->historicRun(ProcessingStatus::Processing);

        $result = app(ProcessingRunOrchestrator::class)->retry($run);

        $this->assertNotSame(
            'PROCESSING_NOT_FAILED',
            $result->errorCode,
            'A stranded historic run must not be rejected as "not in failed or cancelled state".'
        );
    }

    /**
     * The conservative half: a run really mid-flight holds a reserved job, so a
     * non-zero queue depth must still refuse the retry rather than dispatching a
     * second chain over live work.
     */
    #[Test]
    public function it_still_refuses_a_run_while_historic_work_is_in_flight(): void
    {
        $this->fakeQueueDepth(1);
        $run = $this->historicRun(ProcessingStatus::Processing);

        $result = app(ProcessingRunOrchestrator::class)->retry($run);

        $this->assertSame('PROCESSING_NOT_FAILED', $result->errorCode);
    }

    #[Test]
    public function it_still_refuses_a_non_historic_run_that_is_merely_processing(): void
    {
        $this->fakeQueueDepth(0);
        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => null,
            'status' => ProcessingStatus::Processing,
        ]);

        $result = app(ProcessingRunOrchestrator::class)->retry($run);

        $this->assertSame('PROCESSING_NOT_FAILED', $result->errorCode);
    }

    private function historicRun(ProcessingStatus $status): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'status' => $status,
        ]);
    }

    private function fakeQueueDepth(int $depth): void
    {
        $connection = $this->createStub(QueueContract::class);
        $connection->method('size')->willReturn($depth);

        $factory = $this->createStub(QueueFactory::class);
        $factory->method('connection')->willReturn($connection);

        $this->app->instance(QueueFactory::class, $factory);
    }
}
