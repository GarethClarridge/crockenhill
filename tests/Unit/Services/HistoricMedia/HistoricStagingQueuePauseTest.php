<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\HistoricMedia\HistoricStagingQueuePause;
use App\Services\HistoricMedia\HistoricStagingReachability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricStagingQueuePauseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        HistoricStagingQueuePause::resetForTesting();
        HistoricStagingReachability::resetForTesting();
        HistoricStagingGuard::resetBaselineForTesting();

        $this->root = storage_path('app/testing-staging-pause-'.bin2hex(random_bytes(4)));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        HistoricStagingQueuePause::resetForTesting();
        HistoricStagingReachability::resetForTesting();

        if (is_dir($this->root)) {
            foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $entry) {
                @unlink($this->root.'/'.$entry);
            }

            @rmdir($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_holds_a_historic_worker_while_the_volume_is_away(): void
    {
        $pause = $this->pause(reachable: false);

        $this->assertTrue($pause->shouldPause($this->historicQueue()));
    }

    #[Test]
    public function it_lets_a_historic_worker_run_while_the_volume_is_present(): void
    {
        $pause = $this->pause(reachable: true);

        $this->assertFalse($pause->shouldPause($this->historicQueue()));
    }

    /**
     * The blast radius that matters: a historic-only outage must not stop the
     * queues that serve the public site.
     */
    #[Test]
    public function it_never_holds_a_worker_that_serves_no_historic_queue(): void
    {
        $pause = $this->pause(reachable: false);

        $this->assertFalse($pause->shouldPause('default'));
        $this->assertFalse($pause->shouldPause('default,livestream-processing'));
    }

    /**
     * A worker given several queues in priority order could take the historic
     * one next, so serving any historic queue is enough to hold it.
     */
    #[Test]
    public function it_holds_a_worker_serving_a_historic_queue_among_others(): void
    {
        $pause = $this->pause(reachable: false);

        $this->assertTrue($pause->shouldPause('default,'.$this->historicQueue()));
    }

    #[Test]
    public function it_resumes_once_the_volume_returns(): void
    {
        $queue = $this->historicQueue();

        $this->assertTrue($this->pause(reachable: false)->shouldPause($queue));
        $this->assertFalse($this->pause(reachable: true)->shouldPause($queue));
    }

    private function historicQueue(): string
    {
        $queues = array_values(app(HistoricProcessingThroughput::class)->configuredQueues());

        $this->assertNotSame([], $queues, 'The historic pipeline must configure at least one queue.');

        return (string) $queues[0];
    }

    /**
     * Driven through the real probe rather than a double: the collaboration
     * being tested is "an absent volume holds the worker", and a stubbed
     * reachability would assert only that this class forwards a boolean.
     */
    private function pause(bool $reachable): HistoricStagingQueuePause
    {
        config([
            'filesystems.disks.probe_staging' => [
                'driver' => 'local',
                'root' => $reachable ? $this->root : $this->root.'/gone',
            ],
            'media-processing.storage.historic_staging_disk' => 'probe_staging',
        ]);

        HistoricStagingReachability::resetForTesting();

        return new HistoricStagingQueuePause(
            app(HistoricStagingReachability::class),
            app(HistoricProcessingThroughput::class),
        );
    }
}
