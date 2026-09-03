<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricStagingQueueContextTest extends TestCase
{
    /**
     * A staging activation that throws is raised from the `Queue::before`
     * listener, which runs before the job fires — so the job's own `failed()`
     * handler never runs and the run it owns is never marked. On 2026-09-03 that
     * stranded five historic runs reading `processing` with every queue empty and
     * nothing in `failed_jobs`, a state no retry path accepts. The listener must
     * fail the job so its `failed()` handler marks the run instead.
     */
    #[Test]
    public function it_fails_the_job_when_the_historic_staging_context_cannot_be_activated(): void
    {
        $failedWith = null;

        $job = $this->createMock(QueueJobContract::class);
        $job->method('payload')->willReturn([
            'historic_staging_context' => [
                'manifest_hash' => str_repeat('a', 64),
                'plan_hash' => str_repeat('b', 64),
                'staging_disk' => 'historic_staging',
                'batch_root' => 'historic-batches/'.str_repeat('b', 64),
                'storage_identity' => [
                    'driver' => 'local',
                    'bucket' => null,
                    'root_fingerprint' => str_repeat('c', 64),
                    'prefix_fingerprint' => str_repeat('d', 64),
                ],
            ],
        ]);
        $job->expects($this->once())
            ->method('fail')
            ->willReturnCallback(function (\Throwable $exception) use (&$failedWith): void {
                $failedWith = $exception;
            });

        Event::dispatch(new JobProcessing('redis', $job));

        $this->assertInstanceOf(\Throwable::class, $failedWith);
        $this->assertStringContainsString('storage identity', $failedWith->getMessage());
    }

    #[Test]
    public function it_leaves_a_job_carrying_no_historic_context_untouched(): void
    {
        $job = $this->createMock(QueueJobContract::class);
        $job->method('payload')->willReturn(['displayName' => 'App\\Jobs\\Whatever']);
        $job->expects($this->never())->method('fail');

        Event::dispatch(new JobProcessing('redis', $job));

        $this->addToAssertionCount(1);
    }
}
