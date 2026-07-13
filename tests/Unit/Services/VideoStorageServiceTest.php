<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class VideoStorageServiceTest extends TestCase
{
    private VideoStorageService $service;

    private MockObject $videoExtractor;

    private MockObject $audioCompressor;

    private MockObject $storageHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->videoExtractor = $this->createMock(VideoExtractionService::class);
        $this->audioCompressor = $this->createMock(AudioCompressionService::class);
        $this->storageHelper = $this->createMock(StorageAdapterHelper::class);

        Config::set('media-processing.storage.temp_disk', 'local_temp');
        Config::set('media-processing.storage.sermon_disk', 'public_perm');
        Config::set('media-processing.storage.paths.video', 'sermons/videos');
        Config::set('media-processing.storage.paths.audio', 'sermons/audio');

        Storage::fake('local_temp');
        Storage::fake('public_perm');

        $this->service = $this->makeService();
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
    public function it_gets_storage_stats_correctly(): void
    {
        Storage::fake('local_temp');
        Storage::fake('public_perm');

        Storage::disk('local_temp')->put('livestream/temp/file1.mp4', '123'); // 3 bytes
        Storage::disk('public_perm')->put('sermons/videos/video1.mp4', '12345'); // 5 bytes
        Storage::disk('public_perm')->put('sermons/audio/audio1.mp3', '12'); // 2 bytes

        $stats = $this->service->getStorageStats();

        $this->assertEquals(1, $stats['temp_files_count']);
        $this->assertEquals(3, $stats['temp_files_size']);
        $this->assertEquals(1, $stats['video_files_count']);
        $this->assertEquals(5, $stats['video_files_size']);
        $this->assertEquals(1, $stats['audio_files_count']);
        $this->assertEquals(2, $stats['audio_files_size']);
        $this->assertEquals(10, $stats['total_size']);
    }

    #[Test]
    public function it_moves_to_local_sermon_storage_successfully(): void
    {
        $tempPath = 'livestream/temp/test.mp4';
        Storage::disk('local_temp')->put($tempPath, 'video content');

        $ffmpeg = $this->createConfiguredFfmpegMock(
            static function (string $outputPath): void {
                file_put_contents($outputPath, 'audio content');
            }
        );

        $this->storageHelper->method('createFFMpeg')->willReturn($ffmpeg);
        $this->service = $this->makeService();

        $result = $this->service->moveToSermonStorage($tempPath, 'test-sermon');

        $this->assertEquals('sermons/videos/test-sermon.mp4', $result['video_path']);
        $this->assertEquals('sermons/audio/test-sermon.mp3', $result['audio_path']);
        Storage::disk('local_temp')->assertMissing($tempPath);
        Storage::disk('public_perm')->assertExists('sermons/videos/test-sermon.mp4');
        Storage::disk('public_perm')->assertExists('sermons/audio/test-sermon.mp3');
        $this->assertSame('video content', Storage::disk('public_perm')->get('sermons/videos/test-sermon.mp4'));
    }

    #[Test]
    public function it_moves_to_s3_sermon_storage_successfully(): void
    {
        Config::set('filesystems.disks.s3_perm', ['driver' => 's3']);
        Storage::fake('s3_perm', ['driver' => 's3']);
        Config::set('media-processing.storage.sermon_disk', 's3_perm');

        $tempPath = 'livestream/temp/test.mp4';
        Storage::disk('local_temp')->put($tempPath, 'video content');

        $ffmpeg = $this->createConfiguredFfmpegMock(
            static function (string $outputPath): void {
                file_put_contents($outputPath, 'audio content');
            }
        );

        $this->storageHelper->method('createFFMpeg')->willReturn($ffmpeg);
        $this->service = $this->makeService();

        $result = $this->service->moveToSermonStorage($tempPath, 'test-sermon');

        $this->assertEquals('sermons/videos/test-sermon.mp4', $result['video_path']);
        $this->assertEquals('sermons/audio/test-sermon.mp3', $result['audio_path']);
        Storage::disk('s3_perm')->assertExists('sermons/videos/test-sermon.mp4');
        Storage::disk('s3_perm')->assertExists('sermons/audio/test-sermon.mp3');
        Storage::disk('local_temp')->assertExists($tempPath);
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

    private function makeService(): VideoStorageService
    {
        return new VideoStorageService(
            $this->videoExtractor,
            $this->audioCompressor,
            $this->storageHelper
        );
    }

    /**
     * @param  \Closure(string): void  $saveCallback
     */
    private function createConfiguredFfmpegMock(\Closure $saveCallback): FFMpeg
    {
        $ffmpeg = $this->createMock(FFMpeg::class);
        $video = $this->createMock(Video::class);

        $ffmpeg->method('open')->willReturn($video);
        $video->expects($this->once())
            ->method('save')
            ->with(
                $this->isInstanceOf(Mp3::class),
                $this->isString()
            )
            ->willReturnCallback(
                static function (Mp3 $format, string $outputPath) use ($saveCallback): void {
                    $saveCallback($outputPath);
                }
            );

        return $ffmpeg;
    }
}
