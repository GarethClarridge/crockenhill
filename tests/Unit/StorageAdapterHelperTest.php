<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\VideoProcessingException;
use App\Services\StorageAdapterHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorageAdapterHelperTest extends TestCase
{
    private StorageAdapterHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->helper = new StorageAdapterHelper;
    }

    // ---- isS3CompatibleDisk ----

    #[Test]
    public function it_returns_false_for_local_disk(): void
    {
        $disk = Storage::disk('local');

        $this->assertFalse($this->helper->isS3CompatibleDisk($disk));
    }

    #[Test]
    public function it_returns_false_for_public_disk(): void
    {
        $disk = Storage::disk('public');

        $this->assertFalse($this->helper->isS3CompatibleDisk($disk));
    }

    // ---- cleanupTempFile ----

    #[Test]
    public function it_deletes_existing_temp_file(): void
    {
        Config::set('media-processing.log_s3_operations', false);

        $path = tempnam(sys_get_temp_dir(), 'test_cleanup');
        file_put_contents($path, 'content');

        $this->assertFileExists($path);

        $this->helper->cleanupTempFile($path);

        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function it_does_nothing_for_nonexistent_file(): void
    {
        $this->expectNotToPerformAssertions();

        $this->helper->cleanupTempFile('/nonexistent/path/to/file.mp3');
    }

    #[Test]
    public function it_does_nothing_for_empty_path(): void
    {
        $this->expectNotToPerformAssertions();

        $this->helper->cleanupTempFile('');
    }

    // ---- uploadWithRetry ----

    #[Test]
    public function it_uploads_file_to_disk_and_returns_path(): void
    {
        Config::set('media-processing.s3_processing.retry_attempts', 1);
        Config::set('media-processing.s3_processing.retry_delay', 0);
        Config::set('media-processing.log_s3_operations', false);

        $localFile = tempnam(sys_get_temp_dir(), 'upload_test');
        file_put_contents($localFile, 'audio data');

        $permanentPath = 'sermons/audio/test.mp3';

        $result = $this->helper->uploadWithRetry($localFile, $permanentPath, 'public');

        $this->assertSame($permanentPath, $result);
        $this->assertTrue(Storage::disk('public')->exists($permanentPath));

        unlink($localFile);
    }

    #[Test]
    public function it_throws_after_exhausting_retries_for_missing_file(): void
    {
        Config::set('media-processing.s3_processing.retry_attempts', 1);
        Config::set('media-processing.s3_processing.retry_delay', 0);

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Failed to upload file after 1 attempts');

        $this->helper->uploadWithRetry('/nonexistent/file.mp3', 'sermons/test.mp3', 'public');
    }

    // ---- downloadToTemp ----

    #[Test]
    public function it_returns_disk_path_for_local_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('videos/test.mp4', 'video content');

        $result = $this->helper->downloadToTemp('videos/test.mp4', 'public', 'local', 'temp/processing');

        // For a non-S3 disk, returns the disk path directly
        $this->assertStringContainsString('videos/test.mp4', $result);
    }

    // ---- getProcessingOutputPath ----

    #[Test]
    public function it_returns_local_processing_path_for_non_s3_disk(): void
    {
        Config::set('filesystems.disks.public.driver', 'local');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $result = $this->helper->getProcessingOutputPath(
            'sermon.mp3',
            'sermons/audio',
            'public',
            'local',
            'temp/audio_extraction'
        );

        $this->assertFalse($result['use_temp_processing']);
        $this->assertSame('sermons/audio/sermon.mp3', $result['permanent_path']);
        $this->assertStringContainsString('sermon.mp3', $result['processing_path']);
    }

    #[Test]
    public function it_returns_temp_processing_path_for_s3_disk(): void
    {
        Config::set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'fake',
            'secret' => 'fake',
            'bucket' => 'fake',
            'region' => 'us-east-1',
        ]);

        $result = $this->helper->getProcessingOutputPath(
            'sermon.mp3',
            'sermons/audio',
            'spaces',
            'local',
            'temp/audio_extraction'
        );

        $this->assertTrue($result['use_temp_processing']);
        $this->assertSame('sermons/audio/sermon.mp3', $result['permanent_path']);
        $this->assertStringContainsString('sermon.mp3', $result['processing_path']);
        $this->assertStringContainsString('temp/audio_extraction', $result['processing_path']);
    }
}
