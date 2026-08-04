<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function a_failure_after_asset_copy_rolls_back_rows_and_cleans_attempt_assets(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        $bundle = $this->graphBundle();
        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        MediaProcessingLog::saved(function (MediaProcessingLog $log): void {
            if ($log->audio_file_path === 'service-transcripts/imported-run/audio.mp3') {
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
