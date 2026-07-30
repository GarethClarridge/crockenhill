<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ProcessingResult;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Video\HistoricVideoImporter;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricVideoImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-importer-test-'.uniqid();
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // File classification (root-level)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_skips_mp3_files_as_audio_duplicates(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mp3');

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['skipped_audio_dup']);
        $this->assertSame(0, $metrics['dispatched']);
    }

    #[Test]
    public function it_skips_files_below_min_size(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        file_put_contents($path, 'tiny');

        $metrics = $this->runImport(minSizeMb: 30);

        $this->assertSame(1, $metrics['skipped_small']);
    }

    #[Test]
    public function it_skips_root_files_with_unparseable_dates(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/UnnamedRecording.mkv');

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['skipped_no_date']);
    }

    #[Test]
    public function it_classifies_root_file_with_full_timestamp_as_morning_service(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(0, $metrics['errors']);
    }

    #[Test]
    public function it_classifies_root_file_with_evening_hour_correctly(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 18-38-15.mkv');

        $capturedClientDate = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate) use (&$capturedClientDate): bool {
                $capturedClientDate = $clientFileDate;

                return true;
            })
            ->andReturn(ProcessingResult::success(
                processingId: 'test-processing-'.uniqid(),
                message: 'ok',
                statusUrl: 'http://localhost/status/test',
            ));

        $this->runImportWithProcessor($processor);

        $this->assertTrue(str_contains($capturedClientDate ?? '', '18:38'));
    }

    #[Test]
    public function it_attaches_historic_source_provenance_before_dispatch(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 18-38-15.mkv';
        $this->createFakeVideo($path);
        $capturedMetadata = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$capturedMetadata): bool {
                $capturedMetadata = $options['processing_metadata']['historic_import'] ?? null;

                return $type === 'livestream' && $clientFileDate !== null;
            })
            ->andReturnUsing(function (): ProcessingResult {
                $processingId = 'historic-'.uniqid();
                MediaProcessingLog::factory()->livestream()->completed()->create([
                    'processing_id' => $processingId,
                ]);

                return ProcessingResult::success($processingId, 'ok');
            });

        $this->runImportWithProcessor($processor);

        $this->assertIsArray($capturedMetadata);
        $this->assertSame('livestream', $capturedMetadata['tag']);
        $this->assertSame($path, $capturedMetadata['sources'][0]['path']);
        $this->assertSame(hash_file('sha256', $path), $capturedMetadata['sources'][0]['sha256']);
        $this->assertArrayHasKey('codec_fingerprint', $capturedMetadata);
        $this->assertArrayHasKey('imported_at', $capturedMetadata);
    }

    #[Test]
    public function it_dispatches_the_approved_service_identity_as_explicit_overrides(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 18-38-15.mkv';
        $this->createFakeVideo($path);
        $capturedService = null;
        $capturedDate = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (
                string $type,
                mixed $file,
                ?string $clientFileDate,
                array $options,
                ?SermonService $serviceOverride,
                ?string $serviceDateOverride,
            ) use (&$capturedService, &$capturedDate): bool {
                $capturedService = $serviceOverride;
                $capturedDate = $serviceDateOverride;

                return $type === 'livestream'
                    && $clientFileDate !== null
                    && isset($options['processing_metadata']['historic_import']);
            })
            ->andReturnUsing(function (): ProcessingResult {
                $processingId = 'historic-'.uniqid();
                MediaProcessingLog::factory()->livestream()->completed()->create([
                    'processing_id' => $processingId,
                ]);

                return ProcessingResult::success($processingId, 'ok');
            });

        $this->runImportWithProcessor($processor);

        $this->assertSame(SermonService::Evening, $capturedService);
        $this->assertSame('2022-01-16', $capturedDate);
    }

    #[Test]
    public function historic_staging_has_no_public_url_and_uses_a_private_root(): void
    {
        $configuration = config('filesystems.disks.historic_staging');

        $this->assertIsArray($configuration);
        $this->assertArrayNotHasKey('url', $configuration);
        $this->assertSame('private', $configuration['visibility']);
        $this->assertStringContainsString('/private/historic-staging', $configuration['root']);
        $this->assertSame('historic_staging', config('media-processing.storage.historic_staging_disk'));
    }

    #[Test]
    public function it_counts_a_failed_pipeline_as_an_import_error(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (): ProcessingResult {
                $processingId = 'failed-'.uniqid();
                MediaProcessingLog::factory()->livestream()->create([
                    'processing_id' => $processingId,
                    'status' => ProcessingStatus::Failed,
                    'error_message' => 'Transcription failed',
                ]);

                return ProcessingResult::success($processingId, 'dispatched');
            });

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['errors']);
        $this->assertSame(1, $metrics['terminal_failed']);
    }

    #[Test]
    public function it_counts_a_pipeline_timeout_as_an_import_error(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (): ProcessingResult {
                $processingId = 'timeout-'.uniqid();
                MediaProcessingLog::factory()->livestream()->processing()->create([
                    'processing_id' => $processingId,
                ]);

                return ProcessingResult::success($processingId, 'dispatched');
            });

        $metrics = $this->runImportWithProcessor(
            $processor,
            perFileTimeoutSeconds: 0,
            pollIntervalSeconds: 0,
        );

        $this->assertSame(1, $metrics['errors']);
        $this->assertSame(1, $metrics['timed_out']);
    }

    #[Test]
    public function it_writes_a_private_json_report_covering_skipped_items(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/UnnamedRecording.mkv');
        $reportPath = $this->temporaryDirectory.'/reports/historic-import.json';

        $this->runImportWithProcessor(
            $this->mockProcessorSuccess(),
            dryRun: true,
            reportPath: $reportPath,
        );

        $this->assertFileExists($reportPath);
        $report = json_decode(File::get($reportPath), true);

        $this->assertSame('crockenhill.historic-import-report', $report['format']);
        $this->assertSame('skip-no-date', $report['items'][0]['decision']);
        $this->assertSame('UnnamedRecording.mkv', $report['items'][0]['label']);
        $this->assertSame(0600, fileperms($reportPath) & 0777);
    }

    // -------------------------------------------------------------------------
    // Dated subdirectory grouping
    // -------------------------------------------------------------------------

    #[Test]
    public function it_groups_subdirectory_files_into_morning_and_evening_services(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);

        $this->createFakeVideo("{$dir}/10-23.mkv");
        $this->createFakeVideo("{$dir}/10-44.mkv");
        $this->createFakeVideo("{$dir}/18-05.mkv");

        $processor = $this->mockProcessorSuccess();

        // noConcat: true dispatches every segment individually — both morning files + the evening file
        $metrics = $this->runImportWithProcessor($processor, noConcat: true);

        // Three dispatches: two morning segments + one evening segment
        $this->assertSame(3, $metrics['dispatched']);
    }

    #[Test]
    public function it_does_not_group_mp3_files_in_subdirectories(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);

        $this->createFakeVideo("{$dir}/10-23.mkv");
        file_put_contents("{$dir}/10-23.mp3", 'audio duplicate');

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor, noConcat: true);

        // Only one dispatch for the mkv; mp3 skipped silently
        $this->assertSame(1, $metrics['dispatched']);
    }

    #[Test]
    public function it_skips_unclassified_files_in_subdirectory_by_default(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);

        $this->createFakeVideo("{$dir}/recording.mkv"); // No recognisable HH-MM prefix

        $metrics = $this->runImport(includeUnclassified: false);

        $this->assertSame(1, $metrics['skipped_unclassified']);
    }

    #[Test]
    public function it_processes_unclassified_files_when_flag_is_set(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);

        $this->createFakeVideo("{$dir}/recording.mkv");

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor, includeUnclassified: true);

        $this->assertSame(1, $metrics['dispatched']);
    }

    // -------------------------------------------------------------------------
    // YouTube filename parsing
    // -------------------------------------------------------------------------

    #[Test]
    public function it_parses_youtube_filename_with_full_date(): void
    {
        $dir = $this->temporaryDirectory.'/YouTubeDownloads';
        mkdir($dir);
        $this->createFakeVideo("{$dir}/Carols By Candlelight - 20 December 2020.mp4");

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(0, $metrics['skipped_no_date']);
    }

    #[Test]
    public function it_skips_youtube_file_with_day_month_only_and_no_default_year(): void
    {
        $dir = $this->temporaryDirectory.'/YouTubeDownloads';
        mkdir($dir);
        $this->createFakeVideo("{$dir}/26 April_ Sermon.mp4");

        $metrics = $this->runImport(defaultYear: null);

        $this->assertSame(1, $metrics['skipped_no_date']);
        $this->assertSame(0, $metrics['dispatched']);
    }

    #[Test]
    public function it_parses_youtube_file_with_day_month_only_when_default_year_set(): void
    {
        $dir = $this->temporaryDirectory.'/YouTubeDownloads';
        mkdir($dir);
        $this->createFakeVideo("{$dir}/12 April Sermon.mp4");

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor, defaultYear: 2021);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(0, $metrics['skipped_no_date']);
    }

    // -------------------------------------------------------------------------
    // Existence checks (date+service dedup)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_skips_when_completed_livestream_exists_for_date_and_service(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['skipped_exists']);
        $this->assertSame(0, $metrics['dispatched']);
    }

    #[Test]
    public function it_skips_when_inflight_livestream_exists_for_date_and_service(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Pending,
        ]);

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['skipped_inflight']);
    }

    #[Test]
    public function it_skips_when_manual_review_pending_for_date_and_service(): void
    {
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

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['skipped_pending_review']);
    }

    #[Test]
    public function it_proceeds_when_only_audio_sermon_exists_for_date_and_service(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        // An audio-only MediaProcessingLog that is completed — NOT a livestream
        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Audio,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(0, $metrics['skipped_exists']);
    }

    #[Test]
    public function force_flag_bypasses_existence_checks(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor, force: true);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(0, $metrics['skipped_exists']);
    }

    // -------------------------------------------------------------------------
    // Limit and dry-run
    // -------------------------------------------------------------------------

    #[Test]
    public function it_processes_newer_videos_first_within_the_same_priority_group(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');
        $this->createFakeVideo($this->temporaryDirectory.'/2024-01-21 10-15-00.mkv');

        $processedClientDates = [];
        $processor = $this->mockProcessorCapturingClientDates($processedClientDates);

        $metrics = $this->runImportWithProcessor($processor, limit: 1);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(['2024-01-21 10:15:00'], $processedClientDates);
    }

    #[Test]
    public function it_prioritises_services_that_do_not_yet_have_a_sermon(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2023-01-15 10-30-00.mkv');
        $this->createFakeVideo($this->temporaryDirectory.'/2024-01-14 10-30-00.mkv');

        Sermon::factory()->create([
            'date' => '2024-01-14',
            'service' => SermonService::Morning,
        ]);

        $processedClientDates = [];
        $processor = $this->mockProcessorCapturingClientDates($processedClientDates);

        $metrics = $this->runImportWithProcessor($processor, limit: 1);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertSame(['2023-01-15 10:30:00'], $processedClientDates);
    }

    #[Test]
    public function limit_restricts_number_of_dispatched_files(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-23 10-15-00.mkv');

        $processor = $this->mockProcessorSuccess();

        $metrics = $this->runImportWithProcessor($processor, limit: 1);

        $this->assertSame(1, $metrics['dispatched']);
    }

    #[Test]
    public function dry_run_reports_would_dispatch_without_dispatching(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');

        $metrics = $this->runImportWithProcessor($processor, dryRun: true);

        $this->assertSame(1, $metrics['dispatched']);
        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run import with a mocked processor that succeeds on every call.
     *
     * @return MockInterface&UnifiedMediaProcessor
     */
    private function mockProcessorSuccess(): MockInterface
    {
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->andReturn(ProcessingResult::success(
                processingId: 'test-processing-'.uniqid(),
                message: 'ok',
                statusUrl: 'http://localhost/status/test',
            ));

        return $processor;
    }

    /**
     * @param  list<string|null>  $processedClientDates
     * @return MockInterface&UnifiedMediaProcessor
     */
    private function mockProcessorCapturingClientDates(array &$processedClientDates): MockInterface
    {
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->andReturnUsing(function (string $type, mixed $file, ?string $clientFileDate) use (&$processedClientDates): ProcessingResult {
                $processedClientDates[] = $clientFileDate;

                return ProcessingResult::success(
                    processingId: 'test-processing-'.uniqid(),
                    message: 'ok',
                    statusUrl: 'http://localhost/status/test',
                );
            });

        return $processor;
    }

    /**
     * @return array{dispatched: int, concatenated: int, concatenated_reencoded: int, enriched: int, skipped_exists: int, skipped_inflight: int, skipped_pending_review: int, skipped_small: int, skipped_audio_dup: int, skipped_no_date: int, skipped_unclassified: int, skipped_low_disk: int, errors: int, bytes_processed: int, bytes_skipped: int}
     */
    private function runImport(
        int $minSizeMb = 1,
        bool $includeUnclassified = false,
        ?int $defaultYear = null,
        bool $force = false,
        int $limit = 0,
    ): array {
        $processor = $this->mockProcessorSuccess();

        return $this->runImportWithProcessor($processor, minSizeMb: $minSizeMb, includeUnclassified: $includeUnclassified, defaultYear: $defaultYear, force: $force, limit: $limit);
    }

    /**
     * @return array{dispatched: int, concatenated: int, concatenated_reencoded: int, enriched: int, skipped_exists: int, skipped_inflight: int, skipped_pending_review: int, skipped_small: int, skipped_audio_dup: int, skipped_no_date: int, skipped_unclassified: int, skipped_low_disk: int, errors: int, bytes_processed: int, bytes_skipped: int}
     */
    private function runImportWithProcessor(
        mixed $processor,
        int $minSizeMb = 1,
        bool $includeUnclassified = false,
        ?int $defaultYear = null,
        bool $noConcat = true,
        bool $reEncodeMismatched = false,
        int $tempDiskMinFreeGb = 1,
        int $parallel = 1,
        int $pollIntervalSeconds = 5,
        int $perFileTimeoutSeconds = 60,
        int $limit = 0,
        bool $dryRun = false,
        bool $force = false,
        ?string $reportPath = null,
    ): array {
        $importer = new HistoricVideoImporter($processor);

        return $importer->import(
            directory: $this->temporaryDirectory,
            dryRun: $dryRun,
            delay: 0,
            force: $force,
            minSizeMb: $minSizeMb,
            includeUnclassified: $includeUnclassified,
            defaultYear: $defaultYear,
            noConcat: $noConcat,
            reEncodeMismatched: $reEncodeMismatched,
            tempDiskMinFreeGb: $tempDiskMinFreeGb,
            parallel: $parallel,
            pollIntervalSeconds: $pollIntervalSeconds,
            perFileTimeoutSeconds: $perFileTimeoutSeconds,
            limit: $limit,
            reportPath: $reportPath,
        );
    }

    private function createFakeVideo(string $path, int $sizeBytes = 2 * 1024 * 1024): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

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
