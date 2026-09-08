<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\MediaDiskReachability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaDiskReachabilityTest extends TestCase
{
    #[Test]
    public function a_mounted_local_disk_is_reachable(): void
    {
        config(['filesystems.disks.mounted_volume' => [
            'driver' => 'local',
            'root' => sys_get_temp_dir(),
        ]]);

        $this->assertTrue((new MediaDiskReachability)->isReachable('mounted_volume'));
        $this->assertNull((new MediaDiskReachability)->unreachableReason('mounted_volume'));
    }

    #[Test]
    public function a_local_disk_whose_root_is_absent_is_unreachable(): void
    {
        config(['filesystems.disks.detached_volume' => [
            'driver' => 'local',
            'root' => '/nonexistent/detached-volume',
        ]]);

        $reason = (new MediaDiskReachability)->unreachableReason('detached_volume');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('not mounted', $reason);
    }

    #[Test]
    public function an_unconfigured_disk_is_unreachable_rather_than_assumed_present(): void
    {
        $reason = (new MediaDiskReachability)->unreachableReason('no_such_disk');

        $this->assertSame('Disk [no_such_disk] has no configuration.', $reason);
    }

    #[Test]
    public function a_remote_disk_is_left_to_report_its_own_per_operation_failures(): void
    {
        config(['filesystems.disks.remote_bucket' => [
            'driver' => 's3',
            'bucket' => 'sermons',
        ]]);

        $this->assertTrue((new MediaDiskReachability)->isReachable('remote_bucket'));
    }

    #[Test]
    public function a_local_disk_without_a_root_is_unreachable(): void
    {
        config(['filesystems.disks.rootless_volume' => ['driver' => 'local']]);

        $this->assertSame(
            'Disk [rootless_volume] has no root path.',
            (new MediaDiskReachability)->unreachableReason('rootless_volume'),
        );
    }
}
