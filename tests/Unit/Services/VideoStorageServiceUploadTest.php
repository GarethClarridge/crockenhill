<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoStorageServiceUploadTest extends TestCase
{
    private VideoStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $extractionService = $this->createStub(VideoExtractionService::class);
        $compressionService = $this->createStub(AudioCompressionService::class);
        $this->service = app(VideoStorageService::class, [
            'videoExtractor' => $extractionService,
            'audioCompressor' => $compressionService,
        ]);
    }

    public function test_upload_to_permanent_storage_uploads_file_and_returns_path(): void
    {
        Storage::fake('public');
        config()->set('media-processing.storage.sermon_disk', 'public');
        config()->set('media-processing.s3_processing.retry_attempts', 1);
        config()->set('media-processing.s3_processing.retry_delay', 0);

        $localFilePath = storage_path('app/temp/upload-source.mp3');
        $directory = dirname($localFilePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($localFilePath, 'test-audio-data');
        $permanentPath = 'sermons/audio/uploaded-test.mp3';

        $result = $this->service->uploadToPermanentStorage($localFilePath, $permanentPath);

        $this->assertSame($permanentPath, $result);
        $this->assertTrue(Storage::disk('public')->exists($permanentPath));

        unlink($localFilePath);
    }

    public function test_upload_to_permanent_storage_throws_for_missing_local_file(): void
    {
        Storage::fake('public');
        config()->set('media-processing.storage.sermon_disk', 'public');
        config()->set('media-processing.s3_processing.retry_attempts', 1);
        config()->set('media-processing.s3_processing.retry_delay', 0);

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Failed to upload file after 1 attempts');

        $this->service->uploadToPermanentStorage(
            storage_path('app/temp/does-not-exist.mp3'),
            'sermons/audio/should-not-upload.mp3'
        );
    }
}
