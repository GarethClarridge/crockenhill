<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\HistoricMedia\HistoricStagingReachability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricStagingReachabilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        HistoricStagingReachability::resetForTesting();
        HistoricStagingGuard::resetBaselineForTesting();

        /**
         * Under `storage/app` deliberately: that path is the bind mount in this
         * project's containers, and a directory made anywhere else is not a
         * faithful stand-in for the staging volume.
         */
        $this->root = storage_path('app/testing-staging-reachability-'.bin2hex(random_bytes(4)));
        mkdir($this->root, 0755, true);

        $this->useStagingRoot($this->root);
    }

    protected function tearDown(): void
    {
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
    public function it_reports_a_mounted_and_writable_root_as_reachable(): void
    {
        $reachability = $this->reachability();

        $this->assertTrue($reachability->isReachable());
        $this->assertNull($reachability->unreachableReason());
    }

    /**
     * The 2026-09-05 failure exactly: the mount point survives inside the
     * container as an empty, unstattable path once the volume has gone.
     */
    #[Test]
    public function it_reports_a_missing_root_as_unreachable(): void
    {
        $this->useStagingRoot($this->root.'/gone');
        HistoricStagingReachability::resetForTesting();

        $reachability = $this->reachability();

        $this->assertFalse($reachability->isReachable());
        $this->assertStringContainsString('is not a directory', (string) $reachability->unreachableReason());
    }

    #[Test]
    public function it_reports_an_unconfigured_staging_disk_as_unreachable(): void
    {
        config(['media-processing.storage.historic_staging_disk' => '']);
        HistoricStagingReachability::resetForTesting();

        $reachability = $this->reachability();

        $this->assertFalse($reachability->isReachable());
        $this->assertStringContainsString('No historic staging disk is configured', (string) $reachability->unreachableReason());
    }

    /**
     * A remote driver has no mount to lose, and blocking the queue on one would
     * be this guard inventing an outage rather than reporting one.
     */
    #[Test]
    public function it_treats_a_non_local_staging_disk_as_reachable(): void
    {
        config(['filesystems.disks.probe_staging' => ['driver' => 's3', 'bucket' => 'archive']]);
        HistoricStagingReachability::resetForTesting();

        $this->assertTrue($this->reachability()->isReachable());
    }

    #[Test]
    public function it_leaves_no_probe_file_behind(): void
    {
        $this->assertTrue($this->reachability()->isReachable());

        $left = array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));

        $this->assertSame([], $left, 'The write probe must clean up after itself.');
    }

    /**
     * Six workers looping once a second must not each stat the volume every
     * iteration, so a reading stands briefly. The volume disappearing inside
     * that window is the one failed job the guard already accepts as
     * unavoidable.
     */
    #[Test]
    public function it_serves_a_cached_reading_within_the_probe_window(): void
    {
        $this->assertTrue($this->reachability()->isReachable());

        $this->useStagingRoot($this->root.'/gone');

        $this->assertTrue(
            $this->reachability()->isReachable(),
            'A reading taken moments ago should stand rather than re-probing.',
        );

        HistoricStagingReachability::resetForTesting();

        $this->assertFalse($this->reachability()->isReachable());
    }

    private function useStagingRoot(string $root): void
    {
        config([
            'filesystems.disks.probe_staging' => ['driver' => 'local', 'root' => $root],
            'media-processing.storage.historic_staging_disk' => 'probe_staging',
        ]);
    }

    private function reachability(): HistoricStagingReachability
    {
        return new HistoricStagingReachability(new HistoricStagingGuard);
    }
}
