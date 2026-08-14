<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\HistoricVideoCurationManifest;
use App\Services\Media\Video\HistoricVideoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ImportHistoricVideoBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    /** @var list<string> */
    private array $temporaryManifestPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-video-import-test-'.uniqid();
        mkdir($this->temporaryDirectory, 0755, true);

        // The batch refuses to dispatch unless media output is isolated on the private
        // staging disk, so every applying run below configures it.
        config([
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryManifestPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

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

    /**
     * Dispatch is a production-once operation, and the refusal comes before the
     * manifest requirement so that "no approved import operation" is what an
     * operator is told rather than "no manifest" — the manifest is not the
     * problem.
     */
    #[Test]
    public function an_unapproved_production_dispatch_is_refused(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('sermons:import-historic-videos', ['--dir' => $this->temporaryDirectory])
            ->expectsOutputToContain('no approved G8 import operation is recorded')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_production_dry_run_is_not_blocked(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--dry-run' => true,
        ])->assertExitCode(0);
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
    public function a_manifest_dry_run_writes_the_plan_hash_required_for_dispatch(): void
    {
        $relativePath = 'unparseable-archive-name.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening');
        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
        $reportPath = sys_get_temp_dir().'/historic-video-plan-'.uniqid().'.json';
        $this->temporaryManifestPaths[] = $reportPath;

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
            '--manifest' => $manifestPath,
            '--report' => $reportPath,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain("Plan hash: {$plan->planHash}");

        $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($plan->planHash, $report['plan_hash']);
        self::assertSame($plan->manifestHash, $report['manifest_hash']);
        self::assertSame('2021-04-12', $report['items'][0]['date']);
        self::assertSame('evening', $report['items'][0]['service']);
        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function it_skips_completed_livestream_for_same_date_and_service(): void
    {
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
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-exists]');

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_skips_in_flight_livestream_for_same_date_and_service(): void
    {
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
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-inflight]');
    }

    #[Test]
    public function it_skips_pending_manual_review_for_same_date_and_service(): void
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

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-pending-review]');
    }

    #[Test]
    public function definitive_manifest_runs_reject_the_generic_force_flag(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/2022-01-16 10-38-15.mkv');
        $manifestPath = $this->historicManifest('2022-01-16 10-38-15.mkv', '2022-01-16', 'morning');
        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);

        MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Morning,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->mock(HistoricVideoImporter::class)
            ->shouldReceive('import')
            ->never();

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--force' => true,
            '--manifest' => $manifestPath,
            '--plan-hash' => $plan->planHash,
        ])
            ->expectsOutputToContain('forbid')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_skips_small_files(): void
    {

        // Create a very small file (less than 30MB default)
        $path = $this->temporaryDirectory.'/2022-01-16 10-38-15.mkv';
        file_put_contents($path, 'tiny content');

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-small]');
    }

    #[Test]
    public function it_skips_files_with_unparseable_dates(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/YouTubeDownloads/26 April_ Sermon.mp4');

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('[skip-no-date]');
    }

    #[Test]
    public function default_year_flag_allows_month_day_only_youtube_filenames(): void
    {
        $this->createFakeVideo($this->temporaryDirectory.'/YouTubeDownloads/12 April Sermon.mp4');
        $manifestPath = $this->historicManifest('YouTubeDownloads/12 April Sermon.mp4', '2021-04-12', 'morning');
        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);

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
            '--manifest' => $manifestPath,
            '--plan-hash' => $plan->planHash,
        ])
            ->assertExitCode(0);
    }

    #[Test]
    public function every_dispatch_uses_the_approved_manifest_identity_not_filename_inference(): void
    {
        $relativePath = 'unparseable-archive-name.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening');
        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
        $approvedWorkItems = null;
        $stagingContext = null;

        $this->mock(HistoricVideoImporter::class)
            ->shouldReceive('import')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$approvedWorkItems, &$stagingContext): array {
                $approvedWorkItems = $arguments[18] ?? null;
                $stagingContext = $arguments[19] ?? null;

                return [
                    'dispatched' => 1, 'concatenated' => 0, 'concatenated_reencoded' => 0,
                    'enriched' => 0, 'skipped_exists' => 0, 'skipped_inflight' => 0,
                    'skipped_pending_review' => 0, 'skipped_small' => 0, 'skipped_audio_dup' => 0,
                    'skipped_no_date' => 0, 'skipped_unclassified' => 0, 'skipped_low_disk' => 0,
                    'errors' => 0, 'bytes_processed' => 1024, 'bytes_skipped' => 0,
                ];
            });

        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--manifest' => $manifestPath,
            '--plan-hash' => $plan->planHash,
        ])->assertExitCode(0);

        self::assertIsArray($approvedWorkItems);
        self::assertCount(1, $approvedWorkItems);
        self::assertSame('2021-04-12', $approvedWorkItems[0]['date']->toDateString());
        self::assertSame(SermonService::Evening, $approvedWorkItems[0]['service']);
        self::assertSame(["{$this->temporaryDirectory}/{$relativePath}"], $approvedWorkItems[0]['files']);
        self::assertSame($plan->manifestHash, $stagingContext->manifestHash);
        self::assertSame($plan->planHash, $stagingContext->planHash);
    }

    #[Test]
    public function it_shows_summary_table_on_completion(): void
    {
        $this->artisan('sermons:import-historic-videos', [
            '--dir' => $this->temporaryDirectory,
            '--allow-local-storage' => true,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dispatched')
            ->expectsOutputToContain('Skipped (total)')
            ->expectsOutputToContain('Errors');
    }

    #[Test]
    public function it_exits_with_failure_code_when_errors_occur(): void
    {
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
            '--dry-run' => true,
        ])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_manifest_rejects_a_recording_nobody_curated(): void
    {
        $this->createFakeVideo("{$this->temporaryDirectory}/2021-04-12 18-02-00.mkv");
        $manifestPath = $this->historicManifest('2021-04-12 18-02-00.mkv', '2021-04-12', 'evening');
        $this->createFakeVideo("{$this->temporaryDirectory}/2021-04-19 18-02-00.mkv");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmanifested files: 2021-04-19 18-02-00.mkv');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    /**
     * The corpus lives on a removable drive that macOS and Windows both litter
     * with their own metadata. Demanding a curation decision for those would make
     * every plan unrunnable against the real drive.
     */
    #[Test]
    public function the_manifest_ignores_operating_system_metadata_beside_the_recordings(): void
    {
        $this->createFakeVideo("{$this->temporaryDirectory}/2021-04-12 18-02-00.mkv");
        $manifestPath = $this->historicManifest('2021-04-12 18-02-00.mkv', '2021-04-12', 'evening');

        file_put_contents("{$this->temporaryDirectory}/.DS_Store", 'x');
        file_put_contents("{$this->temporaryDirectory}/._2021-04-12 18-02-00.mkv", 'x');
        file_put_contents("{$this->temporaryDirectory}/Thumbs.db", 'x');
        mkdir("{$this->temporaryDirectory}/.Spotlight-V100");
        file_put_contents("{$this->temporaryDirectory}/.Spotlight-V100/store.db", 'x');

        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);

        $this->assertCount(1, $plan->workItems);
    }

    #[Test]
    public function the_manifest_rejects_a_recording_replaced_after_approval(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening');

        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}", 36 * 1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic video source changed: {$relativePath}");

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_a_segment_count_that_contradicts_its_files(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'expected_occurrence_count' => 2,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects 2 occurrences but declares 1 source files');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_preserves_portable_editorial_facts_in_the_approved_plan(): void
    {
        $relativePath = '2021-12-19 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $facts = [
            'occasion' => 'Carol service',
            'title' => 'A light in the darkness',
            'speaker' => 'Guest preacher',
            'scripture_reference' => 'John 1:1-14',
            'series' => 'Christmas 2021',
        ];
        $manifestPath = $this->historicManifest($relativePath, '2021-12-19', 'other', [
            'editorial_facts' => $facts,
        ]);

        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);

        $this->assertSame($facts, $plan->workItems[0]['editorial_facts']);
        $this->assertSame($facts, $plan->report()['items'][0]['editorial_facts']);
    }

    #[Test]
    public function the_manifest_refuses_two_included_services_with_the_same_natural_identity(): void
    {
        $first = 'special-one.mkv';
        $second = 'special-two.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$first}");
        $this->createFakeVideo("{$this->temporaryDirectory}/{$second}");
        $manifestPath = $this->historicManifest($first, '2021-12-19', 'other');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $secondEntry = $manifest['entries'][0];
        $secondEntry['item_key'] = 'second-special-service';
        $secondEntry['files'] = [[
            'relative_path' => $second,
            'sha256' => hash_file('sha256', "{$this->temporaryDirectory}/{$second}"),
            'byte_size' => filesize("{$this->temporaryDirectory}/{$second}"),
        ]];
        $manifest['entries'][] = $secondEntry;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('multiple included services for 2021-12-19|other');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_unknown_entry_schema_fields(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'unreviewed_hint' => 'must not become mutation authority',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown or missing schema fields');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_a_duplicate_of_an_undeclared_item(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'disposition' => 'exclude',
            'exclusion_reason' => 'duplicate upload',
            'duplicate_of' => 'an-item-key-that-was-never-declared',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicates undeclared item key an-item-key-that-was-never-declared');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_an_exclusion_without_a_reason(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'disposition' => 'exclude',
            'exclusion_reason' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is excluded without a reason');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_an_unknown_corroboration_grade(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'corroboration' => 'probably_fine',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a known corroboration grade');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_manifest_rejects_a_corroboration_grade_contradicting_its_source_files(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening', [
            'corroboration' => 'fragmented',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('corroboration grade contradicting its source files');

        app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);
    }

    #[Test]
    public function the_plan_counts_corroboration_grades_so_evidence_strength_is_hash_covered(): void
    {
        $relativePath = '2021-04-12 18-02-00.mkv';
        $this->createFakeVideo("{$this->temporaryDirectory}/{$relativePath}");
        $manifestPath = $this->historicManifest($relativePath, '2021-04-12', 'evening');

        $plan = app(HistoricVideoCurationManifest::class)->plan($this->temporaryDirectory, $manifestPath);

        $this->assertSame(1, $plan->counts['corroboration_full']);
        $this->assertSame(0, $plan->counts['corroboration_short_partial']);
        $this->assertSame(0, $plan->counts['corroboration_fragmented']);
        $this->assertSame(0, $plan->counts['corroboration_unknown']);
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

    /** @param array<string, mixed> $overrides */
    private function historicManifest(string $relativePath, string $date, string $service, array $overrides = []): string
    {
        $path = "{$this->temporaryDirectory}/{$relativePath}";
        $manifestPath = sys_get_temp_dir().'/historic-video-manifest-'.uniqid().'.json';
        $this->temporaryManifestPaths[] = $manifestPath;
        $manifest = [
            'format' => 'crockenhill-historic-video-curation',
            'version' => 4,
            'batch_key' => 'historic-video-test-batch',
            'entries' => [[
                'item_key' => 'approved-'.hash('sha256', $relativePath),
                'source_kind' => 'livestream',
                'disposition' => 'include',
                'exclusion_reason' => null,
                'duplicate_of' => null,
                'date' => $date,
                'service' => $service,
                'concatenation' => 'single',
                'client_file_date' => "{$date} 12:00:00",
                'expected_occurrence_count' => 1,
                'corroboration' => 'full',
                'decision' => ['approved_rule_version' => 'test-v1'],
                'editorial_facts' => [
                    'occasion' => null,
                    'title' => null,
                    'speaker' => null,
                    'scripture_reference' => null,
                    'series' => null,
                ],
                'files' => [[
                    'relative_path' => $relativePath,
                    'sha256' => hash_file('sha256', $path),
                    'byte_size' => filesize($path),
                ]],
                ...$overrides,
            ]],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $manifestPath;
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
