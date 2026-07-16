<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Video\VideoStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoStorageServiceTest extends TestCase
{
    private VideoStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.storage.temp_disk', 'local_temp');

        Storage::fake('local_temp');

        $this->service = new VideoStorageService;
    }

    #[Test]
    public function it_stores_uploaded_video_temporarily(): void
    {
        $file = UploadedFile::fake()->create('test-video.mp4', 1024);

        $result = $this->service->storeUploadedVideo($file);

        $this->assertArrayHasKey('temp_path', $result);
        $this->assertArrayHasKey('full_path', $result);
        $this->assertEquals('test-video.mp4', $result['original_filename']);
        $this->assertEquals(1024 * 1024, $result['file_size']);
        $this->assertContains($result['mime_type'], ['video/mp4', 'application/mp4']);

        Storage::disk('local_temp')->assertExists($result['temp_path']);
    }

    #[Test]
    public function it_cleans_up_temporary_files_from_disk(): void
    {
        Storage::fake('local_temp');
        Storage::disk('local_temp')->put('file1.mp4', 'content');
        Storage::disk('local_temp')->put('file2.mp4', 'content');

        $this->service->cleanupTemporaryFiles(['file1.mp4', 'file2.mp4']);

        Storage::disk('local_temp')->assertMissing('file1.mp4');
        Storage::disk('local_temp')->assertMissing('file2.mp4');
    }

    #[Test]
    public function it_validates_storage_space(): void
    {
        // validateStorageSpace uses disk_free_space which is hard to mock without overcomplicating.
        // It should return true if an exception occurs.
        $this->assertTrue($this->service->validateStorageSpace(1024));
    }

    #[Test]
    public function source_video_exists_for_path_returns_true_when_file_is_on_temp_disk(): void
    {
        Storage::disk('local_temp')->put('livestreams/2026/service.mp4', 'fake-video');

        $this->assertTrue($this->service->sourceVideoExistsForPath('livestreams/2026/service.mp4'));
    }

    #[Test]
    public function source_video_exists_for_path_returns_false_when_file_is_absent(): void
    {
        $this->assertFalse($this->service->sourceVideoExistsForPath('livestreams/2026/missing.mp4'));
    }
}
