<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Exceptions\RecordedVideoOutputMismatch;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use App\Services\Media\MediaDiskReachability;
use App\Services\Media\RecordedVideoOutput;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricAssetPromotionTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

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
    public function promoted_video_keeps_its_recorded_identity_and_can_be_reassessed(): void
    {
        foreach (['historic_staging', 'historic_quarantine'] as $disk) {
            config(["filesystems.disks.{$disk}.root" => Storage::disk($disk)->path('')]);
        }
        [$log, $sermon] = $this->historicRun();
        $log->update(['sermon_id' => $sermon->id]);
        $path = $sermon->video_file_path;
        $this->stage($path, 'verified video bytes');
        $outputs = app(RecordedVideoOutput::class);
        $recorded = $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        $assessment = $this->createMock(SermonVideoQualityAssessmentService::class);
        $assessment->expects($this->exactly(2))->method('assessAndRetainLocalPath')
            ->willReturnCallback(function ($sermon, $videoPath, $disk) use ($path): array {
                $this->assertSame($path, $videoPath);
                $this->assertSame('verified video bytes', Storage::disk($disk)->get($videoPath));

                return ['result' => new SermonVideoQualityAssessmentResult(
                    SermonVideoQualityStatus::Approved, null, 60.0, 0.0, 1.0, 0.0, 0.0,
                ), 'localVideoPath' => null];
            });
        $assess = function () use ($sermon, $assessment): void {
            (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
                $assessment,
                $this->createStub(FrameExtractionService::class),
                $this->createStub(SermonExposurePolicy::class),
                new MediaDiskReachability,
            );
        };
        $assess();
        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());
        Storage::disk('historic_staging')->assertMissing($path);
        $this->assertEquals([...$recorded, 'disk' => 'historic_quarantine'], $outputs->verified($log->fresh(), 'sermon'));
        $assess();
        $this->assertSame('historic_quarantine', $log->fresh()->videoQualityMetadata()['asset_disk']);
        $this->assertSame($recorded['sha256'], $log->fresh()->videoQualityMetadata()['sha256']);
        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());
        $this->assertEquals([...$recorded, 'disk' => 'historic_quarantine'], $outputs->verified($log->fresh(), 'sermon'));
    }

    #[Test]
    public function promotion_verifies_outside_its_transaction_and_refuses_a_changed_video_path(): void
    {
        [$log, $sermon] = $this->historicRun();
        $log->update(['sermon_id' => $sermon->id]);
        $path = $sermon->video_file_path;
        $this->stage($path, 'verified video');
        $outputs = app(RecordedVideoOutput::class);
        $recorded = $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        $level = DB::transactionLevel();
        $beforeDisk = $sermon->asset_disk;
        $mock = Mockery::mock(RecordedVideoOutput::class);
        $mock->shouldReceive('prepareRelocation')->once()->andReturnUsing(function ($run, $key, $source, $target, $videoPath) use ($outputs, $sermon, $level): ?array {
            $this->assertSame($level, DB::transactionLevel(), 'Hashing must finish before promotion takes its row locks.');
            $prepared = $outputs->prepareRelocation($run, $key, $source, $target, $videoPath);
            $sermon->update(['video_file_path' => 'sermons/replacement.mp4']);

            return $prepared;
        });
        $mock->shouldNotReceive('commitRelocation');
        $this->app->instance(RecordedVideoOutput::class, $mock);

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log);
            $this->fail('Promotion bound a video path that was never verified.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Historic sermon video path changed before promotion binding.', $exception->getMessage());
        }

        Storage::disk('historic_staging')->assertExists($path);
        $this->assertSame($beforeDisk, $sermon->refresh()->asset_disk);
        $this->assertEquals($recorded, $log->fresh()->processing_metadata->raw['media_outputs']['sermon']);
    }

    #[Test]
    public function promotion_recovers_recorded_custody_when_the_staging_copy_was_already_removed(): void
    {
        [$log, $sermon] = $this->historicRun();
        $log->update(['sermon_id' => $sermon->id]);
        $path = $sermon->video_file_path;
        $this->stage($path, 'verified video');
        $outputs = app(RecordedVideoOutput::class);
        $recorded = $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        Storage::disk('historic_quarantine')->put($path, 'verified video');
        Storage::disk('historic_staging')->delete($path);

        app(HistoricAssetPromotion::class)->promoteRun($log);

        $this->assertEquals([...$recorded, 'disk' => 'historic_quarantine'], $outputs->verified($log->fresh(), 'sermon'));
        $this->assertSame('historic_quarantine', $sermon->refresh()->asset_disk);
    }

    #[Test]
    public function promotion_refuses_corrupt_destination_before_changing_custody(): void
    {
        [$log, $sermon] = $this->historicRun();
        $log->update(['sermon_id' => $sermon->id]);
        $path = $sermon->video_file_path;
        $this->stage($path, 'verified video');
        $outputs = app(RecordedVideoOutput::class);
        $recorded = $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        Storage::disk('historic_quarantine')->put($path, 'corrupted file');
        Storage::disk('historic_staging')->delete($path);
        $beforeDisk = $sermon->asset_disk;

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log);
            $this->fail('Corrupt destination was accepted.');
        } catch (RecordedVideoOutputMismatch $exception) {
            $this->assertSame('recorded_video_hash_mismatch', $exception->getMessage());
        }

        $this->assertEquals($recorded, $log->fresh()->processing_metadata->raw['media_outputs']['sermon']);
        $this->assertSame($beforeDisk, $sermon->refresh()->asset_disk);
    }

    #[Test]
    public function a_recompose_round_can_promote_old_bytes_without_relabelling_their_provenance(): void
    {
        [$log, $sermon] = $this->historicRun();
        $log->update(['sermon_id' => $sermon->id]);
        $path = $sermon->video_file_path;
        $this->stage($path, 'verified video');
        $outputs = app(RecordedVideoOutput::class);
        $recorded = $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        $log->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata,
            'corpus_rerun' => [['snapshot_file_sha256' => str_repeat('a', 64), 'dispatched_at' => now()->toIso8601String()]],
            'service_structure_projection' => ['attempt_id' => 'recomposed'],
        ]);

        app(HistoricAssetPromotion::class)->promoteRun($log);

        Storage::disk('historic_staging')->assertMissing($path);
        $this->assertEquals([...$recorded, 'disk' => 'historic_quarantine'], $log->fresh()->processing_metadata->raw['media_outputs']['sermon']);
        $this->assertSame('historic_quarantine', $sermon->refresh()->asset_disk);
        $this->expectExceptionMessage('recorded_video_provenance_mismatch');
        $outputs->verified($log->fresh(), 'sermon');
    }

    #[Test]
    public function song_promotion_updates_the_sections_disk_and_canonical_path_on_repromotion(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);
        $section = ServiceSection::findOrFail($songVideo->service_section_id);
        $section->update(['asset_disk' => 'historic_staging', 'extracted_video_path' => 'sections/stale.mp4', 'extracted_at' => now(), 'publication_status' => ServiceSectionPublicationStatus::Published, 'published_at' => now()]);
        $songVideo->update(['asset_disk' => 'historic_quarantine', 'publication_state' => SermonPublicationState::Quarantined, 'historic_import_operation_id' => $log->historic_import_operation_id]);
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'canonical song');

        app(HistoricAssetPromotion::class)->promoteSongVideos($log);

        $this->assertSame('historic_quarantine', $section->refresh()->asset_disk);
        $this->assertSame($songVideo->video_file_path, $section->extracted_video_path);
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
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

        $this->assertArrayNotHasKey('media_outputs', $log->fresh()->processing_metadata?->toArray() ?? []);
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

    #[Test]
    public function it_rebinds_a_newly_created_quarantined_sermon_without_demoting_its_publication_state(): void
    {
        [$log, $sermon] = $this->historicRun();
        $sermon->forceFill([
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_staging',
            'historic_import_operation_id' => $log->historic_import_operation_id,
        ])->save();
        $this->stage('sermons/video/pilot.mp4', 'video bytes');

        $totals = app(HistoricAssetPromotion::class)->promoteRun($log);

        $this->assertSame(1, $totals['assets_promoted']);
        $sermon->refresh();
        $this->assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        $this->assertSame('historic_quarantine', $sermon->asset_disk);
        $this->assertSame($log->historic_import_operation_id, $sermon->historic_import_operation_id);
        Storage::disk('historic_staging')->assertMissing('sermons/video/pilot.mp4');
        Storage::disk('historic_quarantine')->assertExists('sermons/video/pilot.mp4');
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

    /**
     * The counterpart to {@see self::it_refuses_to_overwrite_a_destination_holding_different_bytes()}.
     * An operator's `sermons:re-extract` is the one differing destination that is
     * not a conflict, and before this the re-cut video was produced correctly and
     * then stranded in staging while promotion failed the run permanently.
     */
    #[Test]
    public function it_replaces_a_quarantined_video_when_the_run_carries_replacement_authority(): void
    {
        [$log] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'the re-cut video');
        Storage::disk('historic_quarantine')->put('sermons/video/pilot.mp4', 'the old vp9 cut');

        $log->authoriseVideoReplacementOnPromotion();

        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $this->assertSame(
            'the re-cut video',
            Storage::disk('historic_quarantine')->get('sermons/video/pilot.mp4'),
        );
        Storage::disk('historic_quarantine')->assertMissing('sermons/video/pilot.mp4.replacing');
    }

    #[Test]
    public function it_spends_the_replacement_authority_once_the_replacement_is_verified(): void
    {
        [$log] = $this->historicRun();
        $this->stage('sermons/video/pilot.mp4', 'the re-cut video');
        Storage::disk('historic_quarantine')->put('sermons/video/pilot.mp4', 'the old vp9 cut');

        $log->authoriseVideoReplacementOnPromotion();

        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $this->assertFalse($log->fresh()?->permitsPromotionVideoReplacement());
    }

    /**
     * A re-cut replaces the sermon video *and* every section asset cut from the
     * same source, and those promote after it. Spending the authority on the
     * first asset left the rest of the run's own assets refused, so it is read
     * once for the run and cleared only at the end.
     */
    #[Test]
    public function one_replacement_authority_covers_every_asset_in_the_run(): void
    {
        [$log] = $this->historicRun();
        $songVideo = $this->songVideoForRun($log);

        $this->stage('sermons/video/pilot.mp4', 'the re-cut video');
        Storage::disk('historic_quarantine')->put('sermons/video/pilot.mp4', 'the old vp9 cut');

        $this->stage($songVideo->video_file_path, 'the re-cut song');
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'the old vp9 song');

        $log->authoriseVideoReplacementOnPromotion();

        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $quarantine = Storage::disk('historic_quarantine');
        $this->assertSame('the re-cut video', $quarantine->get('sermons/video/pilot.mp4'));
        $this->assertSame('the re-cut song', $quarantine->get($songVideo->video_file_path));
        $this->assertFalse($log->fresh()?->permitsPromotionVideoReplacement());
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
