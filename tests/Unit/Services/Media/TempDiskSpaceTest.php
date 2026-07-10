<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Services\Media\TempDiskSpace;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TempDiskSpaceTest extends TestCase
{
    #[Test]
    public function path_resolves_from_configured_disk_root(): void
    {
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('filesystems.disks.local.root', '/tmp/custom-root');

        $this->assertSame('/tmp/custom-root', TempDiskSpace::path());
    }

    #[Test]
    public function path_falls_back_to_storage_path_when_root_is_missing(): void
    {
        Config::set('media-processing.storage.temp_disk', 'non-existent');

        $this->assertSame(storage_path('app'), TempDiskSpace::path());
    }

    #[Test]
    public function path_falls_back_to_storage_path_when_root_is_empty(): void
    {
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('filesystems.disks.local.root', '');

        $this->assertSame(storage_path('app'), TempDiskSpace::path());
    }

    #[Test]
    public function min_free_bytes_calculates_correctly_from_gb_config(): void
    {
        Config::set('media-processing.storage.temp_disk_min_free_gb', 10);
        $expected = 10 * 1024 ** 3;

        $this->assertSame($expected, TempDiskSpace::minFreeBytes());
    }

    #[Test]
    public function has_space_for_returns_true_when_free_space_exceeds_both_floor_and_required(): void
    {
        // Set floor to 0 so any measurable space is enough
        Config::set('media-processing.storage.temp_disk_min_free_gb', 0);

        $this->assertTrue(TempDiskSpace::hasSpaceFor(1024));
    }

    #[Test]
    public function has_space_for_returns_false_when_free_space_is_below_floor(): void
    {
        // Set floor to 1 petabyte to guarantee it exceeds actual free space
        Config::set('media-processing.storage.temp_disk_min_free_gb', 1024 * 1024);

        $this->assertFalse(TempDiskSpace::hasSpaceFor(1024));
    }

    #[Test]
    public function has_space_for_returns_false_when_required_bytes_exceeds_free_space(): void
    {
        // Set floor to 0
        Config::set('media-processing.storage.temp_disk_min_free_gb', 0);

        // Require 1 petabyte
        $this->assertFalse(TempDiskSpace::hasSpaceFor(1024 ** 5));
    }

    #[Test]
    public function has_space_for_returns_true_when_disk_space_cannot_be_measured(): void
    {
        // Set path to a non-existent directory to make disk_free_space return false
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('filesystems.disks.local.root', '/non-existent-directory-'.uniqid());

        $this->assertTrue(TempDiskSpace::hasSpaceFor(1024));
    }
}
