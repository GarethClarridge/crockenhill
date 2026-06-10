<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Media\TempDiskSpace;
use App\Services\Monitoring\Checks\TempDiskSpaceCheck;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

class TempDiskSpaceCheckTest extends TestCase
{
    #[Test]
    public function it_reports_ok_when_free_space_is_comfortably_above_the_floor(): void
    {
        // A zero floor makes any measurable free space comfortably sufficient.
        config(['media-processing.storage.temp_disk_min_free_gb' => 0]);

        $result = TempDiskSpaceCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertStringContainsString('GB free', $result->shortSummary);
    }

    #[Test]
    public function it_fails_when_free_space_is_below_the_floor(): void
    {
        // A floor far beyond any real disk guarantees free space sits below it.
        config(['media-processing.storage.temp_disk_min_free_gb' => 1_000_000]);

        $result = TempDiskSpaceCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('media uploads are being rejected', $result->notificationMessage);
    }

    #[Test]
    public function it_warns_when_free_space_approaches_the_floor(): void
    {
        $freeBytes = disk_free_space(TempDiskSpace::path());

        if ($freeBytes === false || $freeBytes < 4 * 1024 ** 3) {
            $this->markTestSkipped('Not enough measurable free disk space to construct the warning band.');
        }

        // Pick a floor so that floor <= free < 2 * floor: the warning band.
        $floorGb = (int) ceil($freeBytes / 1024 ** 3 * 0.75);
        config(['media-processing.storage.temp_disk_min_free_gb' => $floorGb]);

        $result = TempDiskSpaceCheck::new()->run();

        $this->assertSame(Status::warning(), $result->status);
        $this->assertStringContainsString('approaching', $result->notificationMessage);
    }
}
