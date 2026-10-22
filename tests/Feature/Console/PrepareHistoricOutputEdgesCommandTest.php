<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Jobs\TranscribeOutputEdges;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\PrepareOutputEdgeEvidence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrepareHistoricOutputEdgesCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The job's evidence steps, run in place: the run keeps its status and nothing is cut, because
     * {@see TranscribeOutputEdges} marks the run processing and only a re-extraction returns it.
     */
    #[Test]
    public function it_prepares_each_runs_edge_evidence_without_changing_its_status_or_cutting(): void
    {
        Queue::fake();
        $first = MediaProcessingLog::factory()->livestream()->completed()->create();
        $second = MediaProcessingLog::factory()->livestream()->completed()->create();
        $prepared = [];
        $this->mock(PrepareOutputEdgeEvidence::class, function (MockInterface $mock) use (&$prepared): void {
            $mock->shouldReceive('prepare')->twice()->andReturnUsing(function (MediaProcessingLog $run) use (&$prepared): array {
                $prepared[] = $run->id;

                return ['windows' => 3, 'decoded' => 2];
            });
        });

        $this->artisan('historic-import:prepare-output-edges', ['runs' => [$first->id, $second->id]])
            ->expectsOutputToContain(sprintf('run #%d: prepared', $first->id))
            ->assertSuccessful();

        $this->assertSame([$first->id, $second->id], $prepared);
        $this->assertSame(ProcessingStatus::Completed, $first->fresh()?->status);
        $this->assertSame(ProcessingStatus::Completed, $second->fresh()?->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_refuses_every_run_when_one_is_unknown(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $this->mock(PrepareOutputEdgeEvidence::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('prepare');
        });

        $this->artisan('historic-import:prepare-output-edges', ['runs' => [$run->id, 999999999]])
            ->expectsOutputToContain('999999999')
            ->assertFailed();
    }

    #[Test]
    public function a_dry_run_prepares_nothing(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $this->mock(PrepareOutputEdgeEvidence::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('prepare');
        });

        $this->artisan('historic-import:prepare-output-edges', ['runs' => [$run->id], '--dry-run' => true])
            ->expectsOutputToContain(sprintf('run #%d: would prepare', $run->id))
            ->assertSuccessful();
    }

    /** A run that fails is reported and the rest still run, so one bad window cannot stop a pass. */
    #[Test]
    public function it_reports_a_failed_run_and_carries_on(): void
    {
        $first = MediaProcessingLog::factory()->livestream()->completed()->create();
        $second = MediaProcessingLog::factory()->livestream()->completed()->create();
        $this->mock(PrepareOutputEdgeEvidence::class, function (MockInterface $mock) use ($first): void {
            $mock->shouldReceive('prepare')->twice()->andReturnUsing(function (MediaProcessingLog $run) use ($first): array {
                if ($run->id === $first->id) {
                    throw new \RuntimeException('whisper went away');
                }

                return ['windows' => 1];
            });
        });

        $this->artisan('historic-import:prepare-output-edges', ['runs' => [$first->id, $second->id]])
            ->expectsOutputToContain(sprintf('run #%d: failed — whisper went away', $first->id))
            ->expectsOutputToContain(sprintf('run #%d: prepared', $second->id))
            ->assertFailed();
    }
}
