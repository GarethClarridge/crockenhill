<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\HistoricSourceMountException;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
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

        $this->service = new VideoStorageService(app(HistoricStagingContextRegistry::class));
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
    public function it_verifies_an_approved_historic_copy_by_destination_size(): void
    {
        $file = UploadedFile::fake()->createWithContent('historic-video.mp4', str_repeat('x', 1024));

        $result = $this->service->storeUploadedVideo($file, 1024);

        $this->assertSame(1024, $result['file_size']);
        Storage::disk('local_temp')->assertExists($result['temp_path']);
    }

    #[Test]
    public function an_approved_copy_with_the_wrong_size_is_a_stale_mount_and_is_removed(): void
    {
        $file = UploadedFile::fake()->createWithContent('historic-video.mp4', str_repeat('x', 1024));

        $this->expectException(HistoricSourceMountException::class);

        try {
            $this->service->storeUploadedVideo($file, 1025);
        } finally {
            $this->assertSame([], Storage::disk('local_temp')->allFiles('livestream/temp'));
        }
    }

    #[Test]
    public function it_adopts_a_verified_historic_derivative_without_copying_it_again(): void
    {
        Config::set([
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
        Storage::fake('historic_staging');

        $registry = app(HistoricStagingContextRegistry::class);
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
        $stagedPath = 'temp/historic-import/derivative.mkv';
        $dedupKey = str_repeat('c', 64);

        $registry->within($context, function () use ($context, $stagedPath, $dedupKey): void {
            Storage::disk('historic_staging')->put($stagedPath, 'derivative');
            $fullPath = Storage::disk('historic_staging')->path($stagedPath);
            $file = new UploadedFile($fullPath, 'derivative.mkv', 'video/x-matroska', null, true);
            $historicImport = [
                'operation_id' => 'historic-operation',
                'manifest_item_key' => 'item-1',
                'job_key' => $dedupKey,
                'sha256_basis' => 'approved_manifest_not_reverified_at_dispatch',
                'sources' => [[
                    'path' => 'source.mkv',
                    'size' => 10,
                    'mtime' => null,
                    'sha256' => str_repeat('d', 64),
                ]],
                'staging_context' => $context->toArray(),
            ];

            $result = $this->service->adoptHistoricStagedVideo(
                $file,
                [
                    'path' => $stagedPath,
                    'size' => 10,
                    'manifest_item_key' => 'item-1',
                    'dedup_key' => $dedupKey,
                    'operation_id' => 'historic-operation',
                ],
                $historicImport,
                $dedupKey,
            );

            $this->assertSame($stagedPath, $result['temp_path']);
            $this->assertSame($fullPath, $result['full_path']);
            $this->assertSame(10, $result['file_size']);
            Storage::disk('historic_staging')->assertExists($stagedPath);
        });
    }

    #[Test]
    public function ordinary_uploads_cannot_adopt_a_historic_derivative(): void
    {
        $file = UploadedFile::fake()->create('video.mp4', 1, 'video/mp4');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Historic derivative adoption is not bound');

        $this->service->adoptHistoricStagedVideo(
            $file,
            [],
            [],
            'dedup-key',
        );
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
    public function it_cleans_up_working_copies_from_the_historic_staging_disk(): void
    {
        Storage::fake('historic_staging');
        Config::set('media-processing.storage.historic_staging_disk', 'historic_staging');
        Config::set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        Storage::disk('historic_staging')->put('temp/extracted.wav', 'content');

        $this->service->cleanupTemporaryFiles(['temp/extracted.wav']);

        Storage::disk('historic_staging')->assertMissing('temp/extracted.wav');
    }

    /**
     * Quarantine holds promoted durable output. Processing cleanup must not be
     * able to reach it even when handed a path that exists there.
     */
    #[Test]
    public function it_never_deletes_from_the_quarantine_disk(): void
    {
        Storage::fake('historic_quarantine');
        Config::set('media-processing.storage.historic_staging_disk', 'historic_staging');
        Config::set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        Storage::disk('historic_quarantine')->put('sermons/video/promoted.mp4', 'durable');

        $this->service->cleanupTemporaryFiles(['sermons/video/promoted.mp4']);

        Storage::disk('historic_quarantine')->assertExists('sermons/video/promoted.mp4');
    }

    /**
     * An absolute path used to reach `unlink()` directly, which on a historic
     * pass means the read-only source corpus was protected by the mount option
     * rather than by anything in the code.
     */
    #[Test]
    public function it_refuses_absolute_and_traversing_paths(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'cleanup-guard');
        $this->assertIsString($outside);
        file_put_contents($outside, 'irreplaceable');

        $this->service->cleanupTemporaryFiles([$outside, '../../etc/passwd']);

        $this->assertFileExists($outside);

        unlink($outside);
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
