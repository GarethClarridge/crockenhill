<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ProcessingResult;
use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\TempDiskSpace;
use App\Services\Media\Video\HistoricVideoCurationManifest;
use App\Services\Media\Video\HistoricVideoImporter;
use App\Services\Media\Video\HistoricVideoReencodeConcatenator;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricVideoImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    /** The shipped staging root, captured before setUp redirects it into the test directory. */
    private string $configuredStagingRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-importer-test-'.uniqid();
        mkdir($this->temporaryDirectory, 0755, true);

        // A historic batch may only run with media output isolated on the private
        // staging disk; every dispatch case below assumes that configuration.
        // Keep staging output inside the test's own directory. The real
        // HISTORIC_STAGING_ROOT is the mounted archive drive, and the guard
        // fingerprints this root, so it must exist before any context is built.
        $this->configuredStagingRoot = (string) config('filesystems.disks.historic_staging.root');
        $stagingRoot = $this->temporaryDirectory.'/staging';
        mkdir($stagingRoot, 0755, true);

        config([
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
            'filesystems.disks.historic_staging.root' => $stagingRoot,
        ]);

        Storage::forgetDisk('historic_staging');
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
        $capturedDedupKey = null;
        $capturedFingerprint = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$capturedMetadata, &$capturedDedupKey, &$capturedFingerprint): bool {
                $capturedMetadata = $options['processing_metadata']['historic_import'] ?? null;
                $capturedDedupKey = $options['dedup_key'] ?? null;
                $capturedFingerprint = $options['processing_metadata']['processing_fingerprint'] ?? null;

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
        $this->assertSame(hash('sha256', $this->temporaryDirectory), $capturedMetadata['manifest_hash']);
        $this->assertSame(hash('sha256', $this->temporaryDirectory.'|plan'), $capturedMetadata['plan_hash']);
        $this->assertArrayHasKey('staging_context', $capturedMetadata);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $capturedMetadata['job_key']);
        $this->assertSame($capturedMetadata['job_key'], $capturedDedupKey);
        $this->assertIsArray($capturedFingerprint);
        $this->assertSame($capturedMetadata['manifest_hash'], $capturedFingerprint['source_manifest_hash']);
    }

    #[Test]
    public function an_approved_source_changed_before_dispatch_creates_no_processing_state(): void
    {
        $relativePath = '2022-01-16 18-38-15.mkv';
        $path = $this->temporaryDirectory.'/'.$relativePath;
        $this->createFakeVideo($path);
        $approvedHash = hash_file('sha256', $path);
        $approvedSize = filesize($path);
        $this->assertIsString($approvedHash);
        $this->assertIsInt($approvedSize);
        file_put_contents($path, 'changed', FILE_APPEND);

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');
        $reportPath = $this->temporaryDirectory.'/source-integrity-report.json';
        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            hash('sha256', $this->temporaryDirectory),
            hash('sha256', $this->temporaryDirectory.'|plan'),
        );
        $metrics = (new HistoricVideoImporter(
            $processor,
            app(HistoricStagingContextRegistry::class),
            app(HistoricProcessingFingerprint::class),
            app(HistoricVideoReencodeConcatenator::class),
        ))->import(
            directory: $this->temporaryDirectory,
            dryRun: false,
            delay: 0,
            force: false,
            minSizeMb: 1,
            includeUnclassified: false,
            defaultYear: null,
            noConcat: true,
            reEncodeMismatched: false,
            tempDiskMinFreeGb: 0,
            parallel: 1,
            pollIntervalSeconds: 1,
            perFileTimeoutSeconds: 1,
            limit: 0,
            reportPath: $reportPath,
            approvedWorkItems: [[
                'manifest_item_key' => 'video-1',
                'tag' => 'livestream',
                'label' => $relativePath,
                'files' => [$path],
                'source_files' => [[
                    'relative_path' => $relativePath,
                    'sha256' => $approvedHash,
                    'byte_size' => $approvedSize,
                ]],
                'date' => Carbon::parse('2022-01-16'),
                'service' => SermonService::Evening,
                'client_file_date' => '2022-01-16 18:38:15',
                'bytes' => $approvedSize,
                'manifest_concatenation' => 'separate',
            ]],
            stagingContext: $stagingContext,
        );

        $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $metrics['errors']);
        $this->assertSame(0, $metrics['dispatched']);
        $this->assertSame('source_integrity_failed', $report['items'][0]['decision']);
        $this->assertDatabaseCount('media_processing_logs', 0);
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
    public function it_refuses_to_dispatch_when_media_output_would_land_on_the_production_disk(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 Morning Service.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic processing would write sermon_disk to the 'do_spaces' disk.");

        $this->runImportWithProcessor($processor);
    }

    #[Test]
    public function a_dry_run_inventory_still_works_without_isolated_storage(): void
    {
        config(['media-processing.storage.sermon_disk' => 'do_spaces']);
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 Morning Service.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');

        $metrics = $this->runImportWithProcessor($processor, dryRun: true);

        $this->assertSame(0, $metrics['errors']);
    }

    #[Test]
    public function historic_staging_has_no_public_url_and_uses_a_private_root(): void
    {
        $configuration = config('filesystems.disks.historic_staging');

        $this->assertIsArray($configuration);
        $this->assertArrayNotHasKey('url', $configuration);
        $this->assertSame('private', $configuration['visibility']);
        $this->assertStringContainsString('/private/historic-staging', $this->configuredStagingRoot);
        $this->assertSame('historic_staging', config('media-processing.storage.historic_staging_disk'));
    }

    #[Test]
    public function it_does_not_poll_or_classify_terminal_pipeline_state_after_enqueueing(): void
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

        $this->assertSame(0, $metrics['errors']);
        $this->assertArrayNotHasKey('terminal_failed', $metrics);
    }

    #[Test]
    public function it_exits_without_waiting_for_an_inflight_pipeline(): void
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

        $this->assertSame(0, $metrics['errors']);
        $this->assertArrayNotHasKey('timed_out', $metrics);
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

    /**
     * The two morning segments become two runs, so they must not share a dedup
     * key — the duplicate lookup would otherwise return the first run's id for
     * the second file and report a dispatch that never happened.
     */
    #[Test]
    public function it_gives_every_individually_dispatched_segment_its_own_dedup_key(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);

        $this->createFakeVideo("{$dir}/10-23.mkv");
        $this->createFakeVideo("{$dir}/10-44.mkv");

        $dedupKeys = [];
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$dedupKeys): bool {
                $dedupKeys[] = $options['dedup_key'] ?? null;

                return true;
            })
            ->andReturn(ProcessingResult::success('processing-id', 'queued'));

        $this->runImportWithProcessor($processor, noConcat: true);

        $this->assertCount(2, $dedupKeys);
        $this->assertNotSame($dedupKeys[0], $dedupKeys[1]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $dedupKeys[0]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $dedupKeys[1]);
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

    /**
     * IC2 §0.1 slice 2: a stopped pass must recognise its own completed work when it restarts.
     *
     * The date/service existence check cannot tell this run's finished item from a service some
     * other source produced, so a resumed full-manifest pass reported its entire completed prefix
     * as `skipped_exists` — the tag that means "something else already covers this", which is what
     * an operator investigates. The manifest job key is the exact identity and is already the
     * durable lock, so it decides first.
     */
    #[Test]
    public function it_reports_its_own_completed_manifest_work_as_resumed_not_as_an_existing_service(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        $this->createFakeVideo($path);

        $jobKey = $this->manifestJobKey([$path], $path);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => $jobKey,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => $jobKey,
                ],
            ],
        ]);
        app(MediaProcessingRunTransitionService::class)->markAsCompleted($log);

        $metrics = $this->runImport();

        $this->assertSame(1, $metrics['resumed_completed']);
        $this->assertSame(0, $metrics['skipped_exists']);
        $this->assertSame(0, $metrics['dispatched']);
    }

    #[Test]
    public function it_retries_its_exact_failed_manifest_run_without_redispatching_the_source(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        $this->createFakeVideo($path);
        $jobKey = $this->manifestJobKey([$path], $path);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => $jobKey,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => $jobKey,
                ],
            ],
        ]);
        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            'llm_structure_validation_failed',
            'Detected sections overlap.',
        );

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');
        $processor->shouldReceive('retry')
            ->once()
            ->with($log->processing_id)
            ->andReturnUsing(function () use ($log): ProcessingResult {
                $log->update(['status' => ProcessingStatus::Completed]);

                return ProcessingResult::success($log->processing_id, 'retried');
            });

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['retried_failed']);
        $this->assertSame(0, $metrics['dispatched']);
        $this->assertSame(0, $metrics['errors']);
    }

    #[Test]
    public function it_readopts_its_exact_inflight_manifest_run_after_the_dispatcher_restarts(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        $this->createFakeVideo($path);
        $jobKey = $this->manifestJobKey([$path], $path);
        MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => $jobKey,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => $jobKey,
                ],
            ],
        ]);

        $processCalls = 0;
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->andReturnUsing(function () use (&$processCalls): ProcessingResult {
                $processCalls++;

                return ProcessingResult::success('unexpected-redispatch', 'redispatched');
            });
        $processor->shouldNotReceive('retry');

        $metrics = $this->runImportWithProcessor($processor, perFileTimeoutSeconds: 0);

        $this->assertSame(0, $processCalls);
        $this->assertSame(1, $metrics['resumed_inflight']);
        $this->assertArrayNotHasKey('timed_out', $metrics);
        $this->assertSame(0, $metrics['dispatched']);
    }

    #[Test]
    public function a_partially_inflight_item_dispatches_its_missing_segment(): void
    {
        $directory = $this->temporaryDirectory.'/2023-12-10';
        mkdir($directory);
        $first = "{$directory}/10-23.mkv";
        $second = "{$directory}/10-44.mkv";
        $this->createFakeVideo($first);
        $this->createFakeVideo($second);
        $jobKey = $this->manifestJobKey([$first, $second], $first);

        MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2023-12-10',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => $jobKey,
            'processing_metadata' => ['historic_import' => ['job_key' => $jobKey]],
        ]);

        $dedupKeys = [];
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('retry');
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$dedupKeys): ProcessingResult {
                $dedupKeys[] = $options['dedup_key'];

                return ProcessingResult::success('processing-'.count($dedupKeys), 'queued');
            });

        $metrics = $this->runImportWithProcessor($processor, noConcat: true, perFileTimeoutSeconds: 0);

        $this->assertSame(0, $metrics['skipped_inflight']);
        $this->assertContains($this->manifestJobKey([$first, $second], $second), $dedupKeys);
    }

    #[Test]
    public function a_completed_legacy_duplicate_wins_over_a_failed_run_with_the_same_job_key(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        $this->createFakeVideo($path);
        $jobKey = $this->manifestJobKey([$path], $path);
        $metadata = ['historic_import' => ['job_key' => $jobKey]];

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => null,
            'processing_metadata' => $metadata,
        ]);
        MediaProcessingLog::factory()->livestream()->failed()->create([
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'dedup_key' => null,
            'processing_metadata' => $metadata,
        ]);

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');
        $processor->shouldNotReceive('retry');

        $metrics = $this->runImportWithProcessor($processor);

        $this->assertSame(1, $metrics['resumed_completed']);
        $this->assertSame(0, $metrics['retried_failed']);
    }

    #[Test]
    public function failed_run_retries_obey_the_import_limit(): void
    {
        $paths = [
            $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv',
            $this->temporaryDirectory.'/2023-01-15 10-38-15.mkv',
        ];

        foreach ($paths as $path) {
            $this->createFakeVideo($path);
            $jobKey = $this->manifestJobKey([$path], $path);
            MediaProcessingLog::factory()->livestream()->failed()->create([
                'extracted_date' => substr(basename($path), 0, 10),
                'extracted_service' => SermonService::Morning,
                'current_step' => 'detect_service_structure',
                'dedup_key' => $jobKey,
                'processing_metadata' => ['historic_import' => ['job_key' => $jobKey]],
            ]);
        }

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');
        $processor->shouldReceive('retry')
            ->once()
            ->andReturnUsing(function (string $processingId): ProcessingResult {
                MediaProcessingLog::query()->where('processing_id', $processingId)->update(['status' => ProcessingStatus::Completed]);

                return ProcessingResult::success($processingId, 'retried');
            });

        $metrics = $this->runImportWithProcessor($processor, limit: 1);

        $this->assertSame(1, $metrics['retried_failed']);
    }

    /**
     * The converse, and the reason the distinction is worth drawing: a completed livestream this
     * manifest did not produce is still a corpus skip, not resumed progress.
     */
    #[Test]
    public function a_completed_livestream_from_another_source_is_still_reported_as_an_existing_service(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
            'dedup_key' => 'some-other-run-'.hash('sha256', 'foreign'),
        ]);

        $metrics = $this->runImport();

        $this->assertSame(0, $metrics['resumed_completed']);
        $this->assertSame(1, $metrics['skipped_exists']);
        $this->assertSame(0, $metrics['dispatched']);
    }

    /**
     * A half-finished multi-segment item dispatches its remainder instead of stalling.
     *
     * Its own completed segment puts a completed livestream at the item's date and slot, so the
     * date/service check would report `skip-exists` and the second segment would never be
     * dispatched — the resumed pass would stop exactly where it stopped before, permanently.
     */
    #[Test]
    public function a_partially_completed_item_dispatches_only_its_unfinished_segments(): void
    {
        $dir = $this->temporaryDirectory.'/2023-12-10';
        mkdir($dir);
        $first = "{$dir}/10-23.mkv";
        $second = "{$dir}/10-44.mkv";
        $this->createFakeVideo($first);
        $this->createFakeVideo($second);

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2023-12-10',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
            'dedup_key' => $this->manifestJobKey([$first, $second], $first),
        ]);

        $dedupKeys = [];
        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$dedupKeys): bool {
                $dedupKeys[] = $options['dedup_key'] ?? null;

                return true;
            })
            ->andReturn(ProcessingResult::success('processing-id', 'queued'));

        $metrics = $this->runImportWithProcessor($processor, noConcat: true, tempDiskMinFreeGb: 0);

        $this->assertSame(0, $metrics['resumed_completed'], 'A partial item is not a resumed one.');
        $this->assertSame(0, $metrics['skipped_exists'], 'Its own completed segment is not a corpus collision.');
        $this->assertContains($this->manifestJobKey([$first, $second], $second), $dedupKeys);
    }

    /**
     * §0.1 slice 3, the first link: the approved grade travels with the recording it describes.
     *
     * The manifest already graded every recording, but the grade stopped there — nothing carried it
     * into the pipeline, so a sermon-only clip and a complete service arrived downstream
     * indistinguishable. It is recorded rather than re-measured because the operator approved it
     * and `manifest_hash` covers it.
     */
    #[Test]
    public function it_carries_the_approved_corroboration_grade_into_processing_metadata(): void
    {
        $relativePath = '2022-01-16 18-38-15.mkv';
        $path = $this->temporaryDirectory.'/'.$relativePath;
        $this->createFakeVideo($path);
        $captured = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$captured): bool {
                $captured = $options['processing_metadata']['historic_import'] ?? null;

                return true;
            })
            ->andReturn(ProcessingResult::success('historic-graded', 'ok'));

        $this->runImportWithApprovedItem($processor, $path, $relativePath, HistoricVideoCorroborationGrade::ShortPartial);

        $this->assertIsArray($captured);
        $this->assertSame('short_partial', $captured['corroboration_grade']);
    }

    /**
     * A work item with no grade records null, never a default.
     *
     * The downstream rule treats an ungraded historic recording as unproven, so the honest value
     * here is the absence itself. Substituting a grade — even `unknown` — would be inventing
     * evidence about how much of a service was captured.
     */
    #[Test]
    public function an_ungraded_work_item_records_a_null_corroboration_grade(): void
    {
        $relativePath = '2022-01-16 18-38-15.mkv';
        $path = $this->temporaryDirectory.'/'.$relativePath;
        $this->createFakeVideo($path);
        $captured = null;

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (string $type, mixed $file, ?string $clientFileDate, array $options) use (&$captured): bool {
                $captured = $options['processing_metadata']['historic_import'] ?? null;

                return true;
            })
            ->andReturn(ProcessingResult::success('historic-ungraded', 'ok'));

        $this->runImportWithApprovedItem($processor, $path, $relativePath, null);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('corroboration_grade', $captured);
        $this->assertNull($captured['corroboration_grade']);
    }

    /**
     * The fail-closed temp-disk floor still stops dispatch, and says which metric it used.
     *
     * Pinned explicitly because the shared helper now passes a floor of 0 — without this the
     * importer could stop guarding disk space entirely and every test here would still pass.
     */
    #[Test]
    public function a_full_temp_disk_stops_dispatch(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');

        $processor = $this->mock(UnifiedMediaProcessor::class);
        $processor->shouldNotReceive('process');

        // A floor no real volume can satisfy, so the refusal is the floor's and not the host's.
        $metrics = $this->runImportWithProcessor($processor, tempDiskMinFreeGb: 1024 * 1024);

        $this->assertSame(1, $metrics['skipped_low_disk']);
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

    /** @return array<string, int> */
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

    /** @return array<string, int> */
    /**
     * The temp-disk floor defaults to 0 so these tests measure classification, not the host.
     *
     * It was 1 GB, which made every dispatching test in this file depend on the free space of
     * whatever volume backs `storage/app`. On a developer machine that is the bind-mounted project
     * directory, so once the host disk filled, 17 of these tests failed together with
     * `skipped_low_disk` — the importer fails closed by design, before it ever classifies anything.
     * Note that the container's `/` is a different filesystem with plenty of room, so `df -h /`
     * reports healthy while the mount that actually matters is full; measure
     * {@see TempDiskSpace::path()} itself.
     *
     * The floor is not untested as a result: `a_full_temp_disk_stops_dispatch` below sets it
     * explicitly, which is the honest place for that behaviour to be pinned.
     */
    private function runImportWithProcessor(
        mixed $processor,
        int $minSizeMb = 1,
        bool $includeUnclassified = false,
        ?int $defaultYear = null,
        bool $noConcat = true,
        bool $reEncodeMismatched = false,
        int $tempDiskMinFreeGb = 0,
        int $parallel = 1,
        int $pollIntervalSeconds = 5,
        int $perFileTimeoutSeconds = 60,
        int $limit = 0,
        bool $dryRun = false,
        bool $force = false,
        ?string $reportPath = null,
        ?array $approvedWorkItems = null,
        bool $failSourceContentRead = false,
    ): array {
        $stagingGuard = app(HistoricStagingGuard::class);
        $stagingContext = $dryRun
            ? null
            : $stagingGuard->contextForApprovedPlan(
                hash('sha256', $this->temporaryDirectory),
                hash('sha256', $this->temporaryDirectory.'|plan'),
            );
        $importer = $failSourceContentRead
            ? new class($processor, app(HistoricStagingContextRegistry::class), app(HistoricProcessingFingerprint::class), app(HistoricVideoReencodeConcatenator::class)) extends HistoricVideoImporter
            {
                protected function sourceFileSha256(string $path): ?string
                {
                    return null;
                }
            }
        : new HistoricVideoImporter(
            $processor,
            app(HistoricStagingContextRegistry::class),
            app(HistoricProcessingFingerprint::class),
            app(HistoricVideoReencodeConcatenator::class),
        );

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
            approvedWorkItems: $approvedWorkItems,
            stagingContext: $stagingContext,
        );
    }

    /**
     * The source drive went stale twice during the pilot. Every remaining item
     * then reads as a missing source, so a pass that carried on would turn one
     * mount problem into as many permanent failures as there are items left.
     */
    #[Test]
    public function it_stops_dispatching_when_the_source_drive_stops_being_readable(): void
    {
        $present = $this->temporaryDirectory.'/2022-01-23 - Evening Service.mp4';
        $this->createFakeVideo($present);
        $vanished = $this->temporaryDirectory.'/never-mounted/2022-01-16 - Evening Service.mp4';
        $presentSize = filesize($present);
        $presentHash = hash_file('sha256', $present);
        $this->assertIsInt($presentSize);
        $this->assertIsString($presentHash);

        $metrics = $this->runImportWithProcessor(
            $this->mockProcessorSuccess(),
            approvedWorkItems: [
                [
                    'manifest_item_key' => 'item-present',
                    'tag' => 'livestream',
                    'label' => 'item-present',
                    'files' => [$present],
                    'source_files' => [[
                        'relative_path' => '2022-01-23 - Evening Service.mp4',
                        'sha256' => $presentHash,
                        'byte_size' => $presentSize,
                    ]],
                    'date' => Carbon::parse('2022-01-23'),
                    'service' => SermonService::Evening,
                    'client_file_date' => '2022-01-23 18:38:15',
                    'bytes' => $presentSize,
                    'manifest_concatenation' => 'separate',
                ],
                [
                    'manifest_item_key' => 'item-vanished',
                    'tag' => 'livestream',
                    'label' => 'item-vanished',
                    'files' => [$vanished],
                    'source_files' => [[
                        'relative_path' => '2022-01-16 - Evening Service.mp4',
                        'sha256' => str_repeat('a', 64),
                        'byte_size' => 1024,
                    ]],
                    'date' => Carbon::parse('2022-01-16'),
                    'service' => SermonService::Evening,
                    'client_file_date' => '2022-01-16 18:38:15',
                    'bytes' => 1024,
                    'manifest_concatenation' => 'separate',
                ],
            ],
        );

        $this->assertTrue($metrics['aborted_stale_mount']);
        $this->assertSame(
            1,
            $metrics['dispatched'],
            'The item read before the drive went stale should still have been dispatched.',
        );
        $this->assertSame(0, $metrics['errors'], 'A stale mount is not a per-item failure.');
    }

    #[Test]
    public function it_stops_dispatching_when_a_present_source_fails_while_its_contents_are_read(): void
    {
        $path = $this->temporaryDirectory.'/2022-01-23 - Evening Service.mp4';
        $this->createFakeVideo($path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        $this->assertIsInt($size);
        $this->assertIsString($hash);

        $metrics = $this->runImportWithProcessor(
            $this->mockProcessorSuccess(),
            approvedWorkItems: [[
                'manifest_item_key' => 'item-content-read-failure',
                'tag' => 'livestream',
                'label' => 'item-content-read-failure',
                'files' => [$path],
                'source_files' => [[
                    'relative_path' => '2022-01-23 - Evening Service.mp4',
                    'sha256' => $hash,
                    'byte_size' => $size,
                ]],
                'date' => Carbon::parse('2022-01-23'),
                'service' => SermonService::Evening,
                'client_file_date' => '2022-01-23 18:38:15',
                'bytes' => $size,
                'manifest_concatenation' => 'separate',
            ]],
            failSourceContentRead: true,
        );

        $this->assertTrue($metrics['aborted_stale_mount']);
        $this->assertSame(0, $metrics['dispatched']);
        $this->assertSame(0, $metrics['errors']);
    }

    /**
     * Dispatch one approved manifest work item, optionally graded, through a supplied processor.
     *
     * Mirrors the shape {@see HistoricVideoCurationManifest::plan()}
     * builds, so these tests exercise the same item contract a definitive run would.
     */
    private function runImportWithApprovedItem(
        mixed $processor,
        string $path,
        string $relativePath,
        ?HistoricVideoCorroborationGrade $grade,
    ): array {
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        $this->assertIsInt($size);
        $this->assertIsString($hash);

        $item = [
            'manifest_item_key' => 'video-1',
            'tag' => 'livestream',
            'label' => $relativePath,
            'files' => [$path],
            'source_files' => [[
                'relative_path' => $relativePath,
                'sha256' => $hash,
                'byte_size' => $size,
            ]],
            'date' => Carbon::parse('2022-01-16'),
            'service' => SermonService::Evening,
            'client_file_date' => '2022-01-16 18:38:15',
            'bytes' => $size,
            'manifest_concatenation' => 'separate',
        ];

        if ($grade instanceof HistoricVideoCorroborationGrade) {
            $item['manifest_corroboration'] = $grade;
        }

        return $this->runImportWithProcessor($processor, approvedWorkItems: [$item]);
    }

    /**
     * The dedup key {@see HistoricVideoImporter} would use, recomputed here rather than exposed.
     *
     * `$itemFiles` is the item's whole file list, because an unmanifested item derives its key from
     * every source it groups — passing one file of a grouped item yields a key the importer never
     * uses, which is a mistake worth failing on rather than papering over. `$sourcePath` is the
     * segment being dispatched, omitted for a concatenated item.
     *
     * Mirroring the importer's private construction is deliberate: if the two diverge these tests
     * fail, which is the point — a resume key that no longer matches the dispatch key is exactly
     * the silent failure this guards.
     *
     * @param  list<string>  $itemFiles
     */
    private function manifestJobKey(array $itemFiles, ?string $sourcePath = null): string
    {
        $manifestHash = hash('sha256', $this->temporaryDirectory);
        $itemKey = 'legacy-'.hash('sha256', implode("\0", $itemFiles));
        $identity = "historic-video\0{$manifestHash}\0{$itemKey}";

        return hash('sha256', $sourcePath === null ? $identity : "{$identity}\0{$sourcePath}");
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
