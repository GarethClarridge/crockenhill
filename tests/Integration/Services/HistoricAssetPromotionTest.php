<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricAssetPromotionTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');

        /**
         * The staging guard refuses to promote unless every media output disk is
         * the staging disk, which is exactly the configuration a historic pass
         * runs under.
         */
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');
        config()->set('thumbnail-generation.storage.disk', 'historic_staging');
    }

    #[Test]
    public function it_promotes_staged_assets_to_quarantine_and_reclaims_the_working_copies(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');
        $this->stage('transcripts/pilot.txt', 'transcript bytes');

        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        Storage::disk('historic_quarantine')->assertExists('sermons/video/pilot.mp4');
        Storage::disk('historic_quarantine')->assertExists('transcripts/pilot.txt');
        $this->assertSame(
            'video bytes',
            Storage::disk('historic_quarantine')->get('sermons/video/pilot.mp4'),
        );

        Storage::disk('historic_staging')->assertMissing('sermons/video/pilot.mp4');
        Storage::disk('historic_staging')->assertMissing('transcripts/pilot.txt');

        $this->assertSame(1, $totals['sermons']);
        $this->assertSame(2, $totals['assets_promoted']);
        $this->assertSame(0, $totals['assets_already_promoted']);
        $this->assertSame(
            strlen('video bytes') + strlen('transcript bytes'),
            $totals['promoted_bytes'],
        );
        $this->assertSame($totals['promoted_bytes'], $totals['reclaimed_bytes']);

        $sermon->refresh();
        $this->assertSame('historic_quarantine', $sermon->asset_disk);
        $this->assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        $this->assertSame($log->historic_import_operation_id, $sermon->historic_import_operation_id);
    }

    #[Test]
    public function it_accepts_unchanged_pipeline_paths_in_production_quarantine(): void
    {
        $this->app['env'] = 'production';
        [$log] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');

        app(HistoricAssetPromotion::class)->promoteRun($log);

        Storage::disk('historic_quarantine')->assertExists('sermons/video/pilot.mp4');
    }

    #[Test]
    public function it_leaves_the_stored_paths_untouched_so_only_the_disk_identity_moves(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');

        app(HistoricAssetPromotion::class)->promoteRun($log);

        $sermon->refresh();
        $this->assertSame('sermons/video/pilot.mp4', $sermon->video_file_path);
    }

    #[Test]
    public function repeating_a_promotion_is_an_exact_no_op(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');

        app(HistoricAssetPromotion::class)->promoteRun($log);
        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        $this->assertSame(0, $totals['assets_promoted']);
        $this->assertSame(1, $totals['assets_already_promoted']);
        $this->assertSame(0, $totals['promoted_bytes']);
        $this->assertSame(0, $totals['reclaimed_bytes']);
        $this->assertSame(
            'video bytes',
            Storage::disk('historic_quarantine')->get('sermons/video/pilot.mp4'),
        );
    }

    /**
     * A retry that resumes at extraction writes fresh bytes under the same paths
     * while the record is already bound to quarantine. Treating `asset_disk` as a
     * promoted flag would strand them on the working volume forever.
     */
    #[Test]
    public function it_promotes_fresh_staging_output_for_a_record_already_bound_to_quarantine(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');
        app(HistoricAssetPromotion::class)->promoteRun($log);

        Storage::disk('historic_quarantine')->delete('sermons/video/pilot.mp4');
        $this->stage('sermons/video/pilot.mp4', 're-extracted bytes');

        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        $this->assertSame(1, $totals['assets_promoted']);
        $this->assertSame(
            're-extracted bytes',
            Storage::disk('historic_quarantine')->get('sermons/video/pilot.mp4'),
        );
        Storage::disk('historic_staging')->assertMissing('sermons/video/pilot.mp4');
    }

    #[Test]
    public function it_refuses_to_overwrite_a_destination_holding_different_bytes(): void
    {
        [$log] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');
        Storage::disk('historic_quarantine')->put('sermons/video/pilot.mp4', 'someone else');

        $this->expectException(RuntimeException::class);

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log);
        } finally {
            Storage::disk('historic_staging')->assertExists('sermons/video/pilot.mp4');
            $this->assertSame(
                'someone else',
                Storage::disk('historic_quarantine')->get('sermons/video/pilot.mp4'),
            );
        }
    }

    #[Test]
    public function it_fails_when_a_verified_working_copy_cannot_be_deleted(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');
        $realStaging = Storage::disk('historic_staging');
        $realQuarantine = Storage::disk('historic_quarantine');
        $staging = Mockery::mock($realStaging);
        $staging->shouldReceive('delete')
            ->once()
            ->with('sermons/video/pilot.mp4')
            ->andReturnFalse();
        Storage::shouldReceive('disk')->with('historic_staging')->andReturn($staging);
        Storage::shouldReceive('disk')->with('historic_quarantine')->andReturn($realQuarantine);

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log);
            $this->fail('Promotion should fail when its staging copy cannot be removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be removed from staging', $exception->getMessage());
            $this->assertTrue($realStaging->exists('sermons/video/pilot.mp4'));
            $this->assertTrue($realQuarantine->exists('sermons/video/pilot.mp4'));
            $this->assertSame(SermonPublicationState::Quarantined, $sermon->fresh()->publication_state);
        }
    }

    #[Test]
    public function it_promotes_historic_song_videos_and_binds_their_custody(): void
    {
        [$log, $sermon] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');
        $songVideo = $this->songVideoForRun($log);
        $this->stage($songVideo->video_file_path, 'song video bytes');

        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        Storage::disk('historic_quarantine')->assertExists($songVideo->video_file_path);
        Storage::disk('historic_staging')->assertMissing($songVideo->video_file_path);
        $songVideo->refresh();

        $this->assertSame(1, $totals['song_videos']);
        $this->assertSame(2, $totals['assets_promoted']);
        $this->assertSame(SermonPublicationState::Quarantined, $songVideo->publication_state);
        $this->assertSame('historic_quarantine', $songVideo->asset_disk);
        $this->assertSame($log->historic_import_operation_id, $songVideo->historic_import_operation_id);
        $this->assertSame(SermonPublicationState::Quarantined, $sermon->fresh()->publication_state);
    }

    #[Test]
    public function it_refuses_a_same_size_song_destination_conflict_and_retains_staging(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);
        $this->stage($songVideo->video_file_path, 'song-a');
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'song-b');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from existing destination');

        try {
            app(HistoricAssetPromotion::class)->promoteSongVideos($log);
        } finally {
            Storage::disk('historic_staging')->assertExists($songVideo->video_file_path);
            Storage::disk('historic_quarantine')->assertExists($songVideo->video_file_path);
            $this->assertSame('song-b', Storage::disk('historic_quarantine')->get($songVideo->video_file_path));
        }
    }

    #[Test]
    public function it_accepts_an_identical_song_destination_replay_and_reclaims_staging(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);
        $this->stage($songVideo->video_file_path, 'song-a');
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'song-a');

        $totals = app(HistoricAssetPromotion::class)->promoteSongVideos($log);

        $this->assertSame(1, $totals['song_videos']);
        $this->assertSame(1, $totals['assets_promoted']);
        $this->assertSame(0, $totals['assets_already_promoted']);
        $this->assertSame(strlen('song-a'), $totals['promoted_bytes']);
        $this->assertSame(strlen('song-a'), $totals['reclaimed_bytes']);
        Storage::disk('historic_staging')->assertMissing($songVideo->video_file_path);
        Storage::disk('historic_quarantine')->assertExists($songVideo->video_file_path);
        $songVideo->refresh();
        $this->assertSame(SermonPublicationState::Quarantined, $songVideo->publication_state);
        $this->assertSame('historic_quarantine', $songVideo->asset_disk);
    }

    #[Test]
    public function it_retains_a_song_staging_copy_when_reclaim_fails(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);
        $this->stage($songVideo->video_file_path, 'song-a');

        $realStaging = Storage::disk('historic_staging');
        $realQuarantine = Storage::disk('historic_quarantine');
        $staging = Mockery::mock($realStaging);
        $staging->shouldReceive('delete')
            ->once()
            ->with($songVideo->video_file_path)
            ->andReturnFalse();
        Storage::shouldReceive('disk')->with('historic_staging')->andReturn($staging);
        Storage::shouldReceive('disk')->with('historic_quarantine')->andReturn($realQuarantine);

        try {
            app(HistoricAssetPromotion::class)->promoteSongVideos($log);
            $this->fail('Song promotion should fail when its staging copy cannot be removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be removed from staging', $exception->getMessage());
            $this->assertTrue($realStaging->exists($songVideo->video_file_path));
            $this->assertTrue($realQuarantine->exists($songVideo->video_file_path));
            $this->assertSame(
                SermonPublicationState::Quarantined,
                $songVideo->fresh()->publication_state,
            );
        }
    }

    #[Test]
    public function it_rejects_unbound_quarantine_bytes_without_a_staging_source(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'song-a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a verified staging source');

        app(HistoricAssetPromotion::class)->promoteSongVideos($log);
    }

    #[Test]
    public function it_refuses_a_song_video_when_its_asset_is_missing_from_both_disks(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is on neither staging nor quarantine');

        app(HistoricAssetPromotion::class)->promoteSongVideos($log);
    }

    #[Test]
    public function it_refuses_when_a_referenced_asset_exists_on_neither_disk(): void
    {
        [$log] = $this->historicRun();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is on neither staging nor quarantine');

        app(HistoricAssetPromotion::class)->promoteRun($log);
    }

    #[Test]
    public function promotion_cannot_overtake_unsettled_historic_video_storage(): void
    {
        [$log] = $this->historicRun();
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $log->historic_import_operation_id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('video storage is unsettled');

        (new PromoteHistoricAssets($log))->handle(app(HistoricAssetPromotion::class));
    }

    #[Test]
    public function a_non_historic_run_promotes_nothing(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Processing,
            'historic_import_operation_id' => null,
        ]);

        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        $this->assertSame(0, $totals['sermons']);
        $this->assertSame(0, $totals['assets_promoted']);
    }

    /**
     * @return array{0: MediaProcessingLog, 1: Sermon}
     */
    private function historicRun(): array
    {
        $operation = $this->createHistoricImportOperation();

        $log = MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Processing,
            'historic_import_operation_id' => $operation->id,
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $log->processing_id,
            'video_file_path' => 'sermons/video/pilot.mp4',
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
        ]);

        return [$log, $sermon];
    }

    private function stage(string $path, string $contents): void
    {
        Storage::disk('historic_staging')->put($path, $contents);

        $sermon = Sermon::query()->firstOrFail();

        if (str_starts_with($path, 'transcripts/')) {
            $sermon->forceFill(['transcript_file_path' => $path])->save();
        }
    }

    private function songVideoForRun(MediaProcessingLog $log): SongVideo
    {
        $song = Song::factory()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        return SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4',
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
            'historic_import_operation_id' => null,
        ]);
    }
}
