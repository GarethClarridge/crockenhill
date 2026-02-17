<?php

namespace Tests\Unit\Traits;

use App\Traits\DetectsStorageType;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectsStorageTypeTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an anonymous class that uses the trait so we can call protected methods
        $this->subject = new class
        {
            use DetectsStorageType;

            public function checkIsS3Disk(string $diskName): bool
            {
                return $this->isS3Disk($diskName);
            }

            public function checkIsS3Path(string $path): bool
            {
                return $this->isS3Path($path);
            }
        };
    }

    // ---- isS3Disk ----

    #[Test]
    public function it_returns_true_for_s3_driver_disk(): void
    {
        Config::set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'fake-key',
            'secret' => 'fake-secret',
            'bucket' => 'fake-bucket',
        ]);

        $this->assertTrue($this->subject->checkIsS3Disk('spaces'));
    }

    #[Test]
    public function it_returns_false_for_local_driver_disk(): void
    {
        Config::set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);

        $this->assertFalse($this->subject->checkIsS3Disk('local'));
    }

    #[Test]
    public function it_returns_false_for_public_driver_disk(): void
    {
        Config::set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
        ]);

        $this->assertFalse($this->subject->checkIsS3Disk('public'));
    }

    #[Test]
    public function it_returns_false_for_unconfigured_disk(): void
    {
        $this->assertFalse($this->subject->checkIsS3Disk('nonexistent-disk'));
    }

    #[Test]
    public function it_returns_false_when_disk_has_no_driver(): void
    {
        Config::set('filesystems.disks.broken', [
            'bucket' => 'some-bucket',
        ]);

        $this->assertFalse($this->subject->checkIsS3Disk('broken'));
    }

    // ---- isS3Path ----

    #[Test]
    public function it_returns_true_for_https_url(): void
    {
        $this->assertTrue($this->subject->checkIsS3Path('https://fra1.digitaloceanspaces.com/my-bucket/sermons/audio/sermon.mp3'));
    }

    #[Test]
    public function it_returns_true_for_http_url(): void
    {
        $this->assertTrue($this->subject->checkIsS3Path('http://s3.amazonaws.com/my-bucket/file.mp3'));
    }

    #[Test]
    public function it_returns_false_for_absolute_local_path(): void
    {
        $this->assertFalse($this->subject->checkIsS3Path('/var/www/storage/app/sermons/audio/sermon.mp3'));
    }

    #[Test]
    public function it_returns_false_for_relative_path(): void
    {
        $this->assertFalse($this->subject->checkIsS3Path('sermons/audio/sermon.mp3'));
    }
}
