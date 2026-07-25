<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\HistoricVideoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportHistoricVideoBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-video-import-test-'.uniqid();
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    #[Test]
    public function it_fails_when_directory_does_not_exist(): void
    {
        $this->artisan('sermons:import-historic-videos', ['--dir' => '/nonexistent/path/abc123'])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not exist');
    }

    #[Test]
    public function it_aborts_when_sermon_disk_is_local_without_flag(): void
    {
        config(['media-processing.storage.sermon_disk' => 'local']);

        $this->artisan('sermons:import-historic-videos', ['--dir' => $this->temporaryDirectory])
            ->assertExitCode(1)
            ->expectsOutputToContain('SERMON_STORAGE_DISK');
    }

    #[Test]
    public function it_allows_local_disk_with_flag(): void
    {
        config(['media-processing.storage.sermon_disk' => 'local']);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_any_local_driver_without_the_override(): void
    {
        config([
            'media-processing.storage.sermon_disk' => 'public',
            'filesystems.disks.public.driver' => 'local',
        ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--dry-run' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('uses the local filesystem driver');
    }

    #[Test]
    public function it_rejects_an_unknown_sermon_disk_even_with_the_local_override(): void
    {
        config(['media-processing.storage.sermon_disk' => 'missing-disk']);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('is not configured');
    }

    #[Test]
    public function dry_run_outputs_plan_without_dispatching(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 18-38-15.mkv');

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run enabled')
            ->expectsOutputToContain('dry-run');

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function it_skips_completed_livestream_for_same_date_and_service(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-exists]');

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_skips_in_flight_livestream_for_same_date_and_service(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Processing,
        ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-inflight]');
    }

    #[Test]
    public function it_skips_pending_manual_review_for_same_date_and_service(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'no_qualifying_speech_block',
                ],
            ],
        ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-pending-review]');
    }

    #[Test]
    public function force_flag_bypasses_skip_checks(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        // Mock the importer to not actually dispatch, but to not skip
        $this->mock(HistoricVideoImporter::class)
            ->shouldReceive('import')
            ->once()
            ->andReturn([
                'dispatched' => 1, 'concatenated' => 0, 'concatenated_reencoded' => 0,
                'enriched' => 0, 'skipped_exists' => 0, 'skipped_inflight' => 0,
                'skipped_pending_review' => 0, 'skipped_small' => 0, 'skipped_audio_dup' => 0,
                'skipped_no_date' => 0, 'skipped_unclassified' => 0, 'skipped_low_disk' => 0,
                'errors' => 0, 'bytes_processed' => 1024, 'bytes_skipped' => 0,
            ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--force' => true,
        ])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_small_files(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        // Create a very small file (less than 30MB default)
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        file_put_contents($path, 'tiny content');

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-small]');
    }

    #[Test]
    public function it_skips_files_with_unparseable_dates(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/YouTubeDownloads/26 April_ Sermon.mp4');

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-no-date]');
    }

    #[Test]
    public function default_year_flag_allows_month_day_only_youtube_filenames(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->createFakeVideo($this->temporaryDirectory.'/YouTubeDownloads/12 April Sermon.mp4');

        $this->mock(HistoricVideoImporter::class)
            ->shouldReceive('import')
            ->once()
            ->andReturn([
                'dispatched' => 1, 'concatenated' => 0, 'concatenated_reencoded' => 0,
                'enriched' => 0, 'skipped_exists' => 0, 'skipped_inflight' => 0,
                'skipped_pending_review' => 0, 'skipped_small' => 0, 'skipped_audio_dup' => 0,
                'skipped_no_date' => 0, 'skipped_unclassified' => 0, 'skipped_low_disk' => 0,
                'errors' => 0, 'bytes_processed' => 1024, 'bytes_skipped' => 0,
            ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--default-year' => '2021',
        ])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_shows_summary_table_on_completion(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dispatched')
            ->expectsOutputToContain('Skipped (total)')
            ->expectsOutputToContain('Errors');
    }

    #[Test]
    public function it_exits_with_failure_code_when_errors_occur(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);

        $this->mock(HistoricVideoImporter::class)
            ->shouldReceive('import')
            ->once()
            ->andReturn([
                'dispatched' => 0, 'concatenated' => 0, 'concatenated_reencoded' => 0,
                'enriched' => 0, 'skipped_exists' => 0, 'skipped_inflight' => 0,
                'skipped_pending_review' => 0, 'skipped_small' => 0, 'skipped_audio_dup' => 0,
                'skipped_no_date' => 0, 'skipped_unclassified' => 0, 'skipped_low_disk' => 0,
                'errors' => 2, 'bytes_processed' => 0, 'bytes_skipped' => 0,
            ]);

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
        ])
            ->assertExitCode(1);
    }

    private function createFakeVideo(string $path, int $sizeBytes = 35 * 1024 * 1024): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write just enough to pass the size check
        $handle = fopen($path, 'wb');

        if ($handle !== false) {
            fseek($handle, $sizeBytes - 1);
            fwrite($handle, "\0");
            fclose($handle);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
