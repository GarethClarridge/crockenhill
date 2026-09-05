<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricStagingQueuePause;
use App\Services\HistoricMedia\HistoricStagingReachability;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /**
     * The 2026-09-05 loss: a five-second mains outage took the staging volume
     * off the bus, and the workers went on consuming for 35 minutes, failing
     * 371 runs against a mount that was no longer there. Returning false from
     * `Looping` is the only hook that stops a job being taken at all — by
     * `Queue::before` it is already reserved, and the worker fires it whatever
     * the listener does.
     */
    #[Test]
    public function it_holds_a_historic_worker_when_the_staging_volume_is_unreachable(): void
    {
        $this->useStagingRoot(storage_path('app/testing-no-such-staging-volume'));

        $this->assertFalse(
            Event::until(new Looping('redis', $this->historicQueue())),
            'A historic worker must not fetch a job while the staging volume is away.',
        );
    }

    #[Test]
    public function it_lets_a_historic_worker_run_when_the_staging_volume_is_present(): void
    {
        $this->useStagingRoot($this->makeStagingRoot());

        $this->assertNotFalse(Event::until(new Looping('redis', $this->historicQueue())));
    }

    /**
     * A historic-only outage must not become a site-wide one.
     */
    #[Test]
    public function it_never_holds_a_worker_that_serves_no_historic_queue(): void
    {
        $this->useStagingRoot(storage_path('app/testing-no-such-staging-volume'));

        $this->assertNotFalse(Event::until(new Looping('redis', 'default')));
    }

    #[Test]
    public function it_holds_a_historic_worker_when_the_pause_guard_itself_fails(): void
    {
        $this->app->bind(
            HistoricStagingQueuePause::class,
            static fn (): never => throw new RuntimeException('pause guard failed'),
        );

        $this->assertFalse(Event::until(new Looping('redis', $this->historicQueue())));
    }

    #[Test]
    public function it_does_not_hold_a_non_historic_worker_when_the_pause_guard_itself_fails(): void
    {
        $this->app->bind(
            HistoricStagingQueuePause::class,
            static fn (): never => throw new RuntimeException('pause guard failed'),
        );

        $this->assertNotFalse(Event::until(new Looping('redis', 'default')));
    }

    private function historicQueue(): string
    {
        $queues = array_values(app(HistoricProcessingThroughput::class)->configuredQueues());

        return (string) $queues[0];
    }

    private function useStagingRoot(string $root): void
    {
        config([
            'filesystems.disks.probe_staging' => ['driver' => 'local', 'root' => $root],
            'media-processing.storage.historic_staging_disk' => 'probe_staging',
        ]);

        HistoricStagingReachability::resetForTesting();
        HistoricStagingQueuePause::resetForTesting();
    }

    private function makeStagingRoot(): string
    {
        $root = storage_path('app/testing-staging-looping-'.bin2hex(random_bytes(4)));
        mkdir($root, 0755, true);

        $this->beforeApplicationDestroyed(static function () use ($root): void {
            foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
                @unlink($root.'/'.$entry);
            }

            @rmdir($root);
        });

        return $root;
    }
}
