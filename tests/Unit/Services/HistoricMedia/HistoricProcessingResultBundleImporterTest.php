<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultBundleImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        // Imports land on the quarantine disk, so leaving this at its real
        // configured value writes bundle assets into storage/app/private.
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function it_preflights_the_whole_service_without_writing(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        $bundle = $this->bundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);

        $first = $importer->prepareService($bundle);
        $second = $importer->prepareService($bundle);

        $this->assertSame('create', $first->classification);
        $this->assertSame($first->planHash, $second->planHash);
        Storage::disk('local')->assertMissing('historic/run/video.mp4');
        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function closeout_audit_preparation_does_not_require_the_private_staging_disk(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle);
        $result = $importer->importService($bundle, $plan->planHash);

        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)
            ->build($result['processing_log']->fresh())['logical_hash'];
        $bundle = app(HistoricProcessingResultBundle::class)->make(
            $bundle['batch_hash'],
            $bundle['processing_fingerprint'],
            $bundle['services'],
        );
        Storage::disk('historic_staging')->delete('historic/run/audio.mp3');

        $auditPlan = $importer->prepareServiceForAudit($bundle);

        $this->assertSame('already_present', $auditPlan->classification);
    }

    #[Test]
    public function it_rejects_a_staged_asset_hash_mismatch(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'different');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultBundleImporter::class)->prepareService($this->bundle());
    }

    #[Test]
    public function asset_copy_is_create_only_and_cleans_only_attempt_created_paths(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        $assets = $this->bundle()['services'][0]['assets'];
        $transfer = app(HistoricProcessingResultAssetTransfer::class);

        $created = $transfer->copyCreateOnly($assets);
        $second = $transfer->copyCreateOnly($assets);

        $this->assertSame(['historic/run/video.mp4'], $created);
        $this->assertSame([], $second);
        $transfer->cleanup($created);
        Storage::disk('local')->assertMissing('historic/run/video.mp4');
    }

    #[Test]
    public function asset_copy_never_overwrites_different_content(): void
    {
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        Storage::disk('local')->put('historic/run/video.mp4', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultAssetTransfer::class)->copyCreateOnly(
            $this->bundle()['services'][0]['assets'],
        );
    }

    #[Test]
    public function it_persists_and_remaps_a_portable_graph_then_reruns_as_a_no_op(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        $result = $importer->importService($bundle, $plan->planHash);
        $section = ServiceSection::query()->firstOrFail();
        $segment = $result['processing_log']->segments()->firstOrFail();

        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)
            ->build($result['processing_log']->fresh())['logical_hash'];
        $bundle = app(HistoricProcessingResultBundle::class)->make(
            $bundle['batch_hash'],
            $bundle['processing_fingerprint'],
            $bundle['services'],
        );

        $this->assertSame([$segment->id], $section->source_segment_ids);
        $this->assertSame(
            'service-transcripts/imported-run/audio.mp3',
            $result['processing_log']->audio_file_path,
        );
        Storage::disk('local')->assertExists('service-transcripts/imported-run/audio.mp3');

        $secondPlan = $importer->prepareService($bundle);
        $second = $importer->importService($bundle, $secondPlan->planHash);

        $this->assertSame('already_present', $secondPlan->classification);
        $this->assertSame($result['processing_log']->id, $second['processing_log']->id);
        $this->assertSame([], $second['created_assets']);
        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run')->count());
    }

    /**
     * An operation-owned apply writes every asset under
     * `historic-import/{operation_id}/`. Verification recomputes section and
     * run-level destinations from scratch, so it has to allocate the same
     * prefix — otherwise the exact no-op rerun the closeout depends on
     * reclassifies every already-present service as a blocked difference.
     */
    #[Test]
    public function an_operation_owned_import_reruns_as_an_exact_no_op(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $operation = $this->historicImportOperation();
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle, 0, $operation);

        $result = DB::transaction(fn (): array => $importer->persistPreparedService($plan, $plan->planHash));
        $prefix = "historic-import/{$operation->operation_id}/";

        $this->assertSame(
            $prefix.'service-transcripts/imported-run/audio.mp3',
            $result['processing_log']->audio_file_path,
        );
        Storage::disk('local')->assertExists($prefix.'service-transcripts/imported-run/audio.mp3');

        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)
            ->build($result['processing_log']->fresh())['logical_hash'];
        $bundle = app(HistoricProcessingResultBundle::class)->make(
            $bundle['batch_hash'],
            $bundle['processing_fingerprint'],
            $bundle['services'],
        );

        $secondPlan = $importer->prepareService($bundle, 0, $operation);
        $second = DB::transaction(fn (): array => $importer->persistPreparedService($secondPlan, $secondPlan->planHash));

        $this->assertSame('already_present', $secondPlan->classification);
        $this->assertSame($result['processing_log']->id, $second['processing_log']->id);
        $this->assertSame([], $second['created_assets']);
        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run')->count());
    }

    private function historicImportOperation(): HistoricImportOperation
    {
        return HistoricImportOperation::query()->create([
            'operation_id' => 'historic-'.str_repeat('a', 32),
            'binding_hash' => str_repeat('b', 64),
            'batch_key' => 'bundle-importer-test',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);
    }

    /**
     * B19: `already_present` used to be decided from the import's own cached
     * `historic_promotion.logical_hash`, so a graph or an asset that had since
     * changed still read as an exact no-op. Both halves must be re-derived from
     * what production currently holds.
     */
    #[Test]
    public function already_present_revalidates_the_live_graph_and_destination_assets(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $bundle = $this->alreadyPresentBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);

        $this->assertSame('already_present', $importer->prepareService($bundle)->classification);

        /**
         * The live graph moved underneath a previously exact import. Only a
         * rebuild from production catches this: the cached import hash still
         * says the bundle was applied verbatim.
         */
        MediaProcessingLog::query()->where('processing_id', 'imported-run')->sole()
            ->forceFill(['duration' => 61.0])->save();
        $movedGraph = $importer->prepareService($bundle);

        $this->assertSame('blocked_difference', $movedGraph->classification);
        $this->assertStringContainsString('different durable content', $movedGraph->reason);

        /** Graph restored, but the destination bytes are damaged. */
        MediaProcessingLog::query()->where('processing_id', 'imported-run')->sole()
            ->forceFill(['duration' => 60.0])->save();
        Storage::disk('local')->put('service-transcripts/imported-run/audio.mp3', 'damaged');
        $damagedAsset = $importer->prepareService($bundle);

        $this->assertSame('blocked_difference', $damagedAsset->classification);
        $this->assertStringContainsString('destination assets differ', $damagedAsset->reason);
    }

    /**
     * Import the graph bundle once and return a bundle whose declared logical hash
     * is the one production now holds, so the next prepare classifies it as the
     * exact no-op this suite needs as its baseline.
     *
     * @return array<string, mixed>
     */
    private function alreadyPresentBundle(): array
    {
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $result = $importer->importService($bundle, $importer->prepareService($bundle)->planHash);

        $bundle['services'][0]['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)
            ->build($result['processing_log']->fresh())['logical_hash'];

        return app(HistoricProcessingResultBundle::class)->make(
            $bundle['batch_hash'],
            $bundle['processing_fingerprint'],
            $bundle['services'],
        );
    }

    #[Test]
    public function it_persists_and_remaps_the_explicit_song_video_asset_role(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        Storage::disk('historic_staging')->put('historic/run/song.mp4', 'song video');
        $song = Song::factory()->create(['canonical_key' => 'portable-song-video']);
        $bundle = $this->graphBundle('portable-song-video');
        $plan = app(HistoricProcessingResultBundleImporter::class)->prepareService($bundle);

        $result = app(HistoricProcessingResultBundleImporter::class)->importService($bundle, $plan->planHash);
        $songVideo = SongVideo::query()->sole();

        $this->assertSame($song->id, $songVideo->song_id);
        $this->assertSame(
            "sermons/songs/{$song->id}/{$songVideo->service_section_id}.mp4",
            $songVideo->video_file_path,
        );
        $this->assertSame('service-transcripts/imported-run/song.mp4', $result['processing_log']->video_file_path);
        Storage::disk('local')->assertExists($songVideo->video_file_path);
        Storage::disk('local')->assertExists($result['processing_log']->video_file_path);
        $this->assertCount(3, $result['created_assets']);
    }

    #[Test]
    public function it_reuses_a_song_video_for_the_same_portable_section_and_asset(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        Storage::disk('historic_staging')->put('historic/run/song.mp4', 'song video');
        Storage::disk('local')->put('historic/previous/song.mp4', 'song video');

        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
        ]);
        $previousRun = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'previous-song-run',
            'church_service_id' => $service->id,
        ]);
        $previousSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $previousRun->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => 'sermon',
            'title' => 'Sermon',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'source_segment_ids' => [],
        ]);
        $song = Song::factory()->create(['canonical_key' => 'portable-song-video']);
        $existing = SongVideo::factory()->featured()->create([
            'song_id' => $song->id,
            'service_section_id' => $previousSection->id,
            'church_service_id' => $service->id,
            'video_file_path' => 'historic/previous/song.mp4',
        ]);

        $bundle = $this->graphBundle('portable-song-video');
        $plan = app(HistoricProcessingResultBundleImporter::class)->prepareService($bundle);
        $result = app(HistoricProcessingResultBundleImporter::class)->importService($bundle, $plan->planHash);

        $this->assertSame(1, SongVideo::query()->count());
        $this->assertSame($existing->id, SongVideo::query()->sole()->id);
        $this->assertSame($result['processing_log']->serviceSections()->sole()->id, SongVideo::query()->sole()->service_section_id);
        $this->assertTrue(SongVideo::query()->sole()->is_featured);
        $this->assertSame('historic/previous/song.mp4', SongVideo::query()->sole()->video_file_path);
        $this->assertCount(2, $result['created_assets']);
    }

    #[Test]
    public function a_failure_after_asset_copy_rolls_back_rows_and_cleans_attempt_assets(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        /**
         * The persister applies allocated paths with model events disabled, so
         * the failure has to be injected below Eloquent. This fires on the same
         * write the old `saved` hook watched: the update that remaps the run's
         * audio path to its allocated destination, after the copy has happened.
         */
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'update `media_processing_logs`')
                && in_array('service-transcripts/imported-run/audio.mp3', $query->bindings, true)) {
                throw new RuntimeException('Failure after asset copy');
            }
        });

        try {
            $importer->importService($bundle, $plan->planHash);
            $this->fail('The simulated post-copy failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failure after asset copy', $exception->getMessage());
        }

        $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => 'imported-run']);
        Storage::disk('local')->assertMissing('service-transcripts/imported-run/audio.mp3');
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        $service = [
            'date' => '2026-08-02',
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('1', 64),
            'evidence_set_hash' => str_repeat('2', 64),
            'pre_review_hash' => str_repeat('3', 64),
            'media_graph' => [
                'processing_key' => 'historic-run',
                'run' => ['file_hash' => str_repeat('4', 64)],
                'logical_hash' => str_repeat('5', 64),
            ],
            'livestream_source_revision' => [],
            'assets' => [[
                'role' => 'main_video',
                'path' => 'historic/run/video.mp4',
                'size' => 5,
                'sha256' => hash('sha256', 'video'),
            ]],
        ];

        return (new HistoricProcessingResultBundle)->make(
            str_repeat('6', 64),
            ['pipeline_version' => 1],
            [$service],
        );
    }

    /** @return array<string, mixed> */
    private function graphBundle(?string $songCanonicalKey = null): array
    {
        $graph = [
            'processing_key' => 'imported-run',
            'run' => [
                'processing_id' => 'imported-run',
                'processing_type' => 'livestream',
                'status' => 'completed',
                'current_step' => 'completed',
                'original_filename' => 'misleading-evening-name.mp4',
                'file_hash' => str_repeat('a', 64),
                'file_size' => 5,
                'duration' => 60.0,
                'extracted_date' => '2026-08-02',
                'extracted_service' => 'morning',
                'audio_file_path' => 'historic/run/audio.mp3',
                'video_file_path' => $songCanonicalKey === null ? null : 'historic/run/song.mp4',
                'transcript_file_path' => null,
                'rms_log_path' => null,
                'sermon_start_time' => 10.0,
                'sermon_end_time' => 50.0,
                'threshold_method' => 'rms',
                'adaptive_threshold' => 0.2,
                'rms_stats' => [],
                'started_at' => '2026-08-02T10:00:00+00:00',
                'completed_at' => '2026-08-02T11:00:00+00:00',
                'is_degraded_completion' => false,
            ],
            'steps' => [[
                'step' => 'transcription',
                'status' => 'completed',
                'message' => null,
                'started_at' => '2026-08-02T10:00:00+00:00',
                'completed_at' => '2026-08-02T10:30:00+00:00',
            ]],
            'segments' => [[
                'segment_key' => 'imported-run:segment:4',
                'segment_index' => 4,
                'start_time' => 0.0,
                'end_time' => 60.0,
                'duration' => 60.0,
                'classification' => 'speech',
                'avg_rms' => 0.2,
                'peak_rms' => 0.4,
                'is_sermon_candidate' => true,
                'is_sermon_segment' => true,
                'segment_order' => 1,
                'metadata' => [],
            ]],
            'sections' => [[
                'section_key' => 'imported-run:section:1:signature',
                'section_order' => 1,
                'section_type' => 'sermon',
                'title' => 'Sermon',
                'summary' => null,
                'start_time' => 0.0,
                'end_time' => 60.0,
                'duration' => 60.0,
                'confidence' => 0.9,
                'status' => 'identified',
                'needs_manual_review' => false,
                'source_segment_keys' => ['imported-run:segment:4'],
                'song_match_type' => null,
                'publication_status' => 'not_applicable',
                'extracted_video_path' => null,
                'extracted_audio_path' => null,
                'published_at' => null,
            ]],
            'publications' => [],
            'song_videos' => $songCanonicalKey === null ? [] : [[
                'section_key' => 'imported-run:section:1:signature',
                'song_canonical_key' => $songCanonicalKey,
                'church_service_identity' => null,
                'video_file_path' => 'historic/run/song.mp4',
                'duration' => 120.0,
                'recorded_date' => '2026-08-02',
                'is_featured' => false,
            ]],
            'metadata' => [],
            'logical_hash' => str_repeat('b', 64),
        ];
        $service = [
            'date' => '2026-08-02',
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('1', 64),
            'evidence_set_hash' => str_repeat('2', 64),
            'pre_review_hash' => str_repeat('3', 64),
            'media_graph' => $graph,
            'livestream_source_revision' => [],
            'assets' => [
                [
                    'role' => 'run_audio_file_path',
                    'path' => 'historic/run/audio.mp3',
                    'size' => 5,
                    'sha256' => hash('sha256', 'audio'),
                ],
                ...($songCanonicalKey === null ? [] : [[
                    'path' => 'historic/run/song.mp4',
                    'size' => 10,
                    'sha256' => hash('sha256', 'song video'),
                    'kind' => 'video',
                    'roles' => [
                        'run_video_file_path',
                        'song_video:imported-run:section:1:signature:video_file_path',
                    ],
                ]]),
            ],
        ];

        return (new HistoricProcessingResultBundle)->make(
            str_repeat('6', 64),
            ['pipeline_version' => 1],
            [$service],
        );
    }
}
