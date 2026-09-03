<?php

declare(strict_types=1);

namespace Tests\Feature\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricPassInFlightProbe;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricPassInFlightProbeTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_reports_a_wedge_when_runs_are_open_but_no_historic_queue_holds_work(): void
    {
        $this->fakeQueueDepths(['historic-ffmpeg' => 0, 'historic-whisper' => 0, 'historic-llm' => 0, 'historic-orchestration' => 0]);
        $this->historicRun(ProcessingStatus::Processing);

        $probe = app(HistoricPassInFlightProbe::class);

        $this->assertSame(0, $probe->inFlightCount());
        $this->assertSame(1, $probe->openRunCount());
        $this->assertTrue($probe->isWedged());
    }

    #[Test]
    public function it_reports_no_wedge_while_any_historic_queue_still_holds_work(): void
    {
        $this->fakeQueueDepths(['historic-ffmpeg' => 1, 'historic-whisper' => 0, 'historic-llm' => 0, 'historic-orchestration' => 0]);
        $this->historicRun(ProcessingStatus::Processing);

        $probe = app(HistoricPassInFlightProbe::class);

        $this->assertSame(1, $probe->inFlightCount());
        $this->assertFalse($probe->isWedged());
    }

    /**
     * An empty queue with nothing open is a finished pass, not a wedge. Alarming
     * on queue depth alone would cry wolf after every successful pass.
     */
    #[Test]
    public function it_reports_no_wedge_when_nothing_is_open(): void
    {
        $this->fakeQueueDepths(['historic-ffmpeg' => 0, 'historic-whisper' => 0, 'historic-llm' => 0, 'historic-orchestration' => 0]);
        $this->historicRun(ProcessingStatus::Completed);

        $this->assertFalse(app(HistoricPassInFlightProbe::class)->isWedged());
    }

    #[Test]
    public function it_scopes_the_open_run_count_to_the_named_pass(): void
    {
        $this->fakeQueueDepths(['historic-ffmpeg' => 0, 'historic-whisper' => 0, 'historic-llm' => 0, 'historic-orchestration' => 0]);
        $mine = $this->historicRun(ProcessingStatus::Processing);
        $this->historicRun(ProcessingStatus::Processing);

        $probe = app(HistoricPassInFlightProbe::class);

        $this->assertSame(2, $probe->openRunCount());
        $this->assertSame(1, $probe->openRunCount([$mine->id]));
        $this->assertTrue($probe->isWedged([$mine->id]));
    }

    private function historicRun(ProcessingStatus $status): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'status' => $status,
        ]);
    }

    /** @param  array<string, int>  $depths */
    private function fakeQueueDepths(array $depths): void
    {
        $connection = $this->createStub(QueueContract::class);
        $connection->method('size')->willReturnCallback(
            static fn (?string $queue = null): int => $depths[$queue] ?? 0
        );

        $factory = $this->createStub(QueueFactory::class);
        $factory->method('connection')->willReturn($connection);

        $this->app->instance(QueueFactory::class, $factory);
    }
}
