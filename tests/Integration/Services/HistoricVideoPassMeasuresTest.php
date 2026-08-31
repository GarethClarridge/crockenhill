<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\HistoricMedia\HistoricVideoPassMeasures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricVideoPassMeasuresTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config()->set(
            'filesystems.disks.historic_staging.root',
            Storage::disk('historic_staging')->path(''),
        );
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');
        config()->set('thumbnail-generation.storage.disk', 'historic_staging');
    }

    #[Test]
    public function it_sums_promotion_records_and_takes_the_peak_from_their_samples(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);

        $this->runWithPromotion($operation, ['promoted_bytes' => 100, 'reclaimed_bytes' => 100, 'staging_bytes_before_reclaim' => 900], stagingContext: $context);
        $this->runWithPromotion($operation, ['promoted_bytes' => 250, 'reclaimed_bytes' => 200, 'staging_bytes_before_reclaim' => 1500], stagingContext: $context);

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(350, $measures['promoted_bytes']);
        $this->assertSame(300, $measures['reclaimed_bytes']);
        $this->assertSame(1500, $measures['peak_working_bytes'], 'Peak is the maximum sample, not the sum.');
        $this->assertSame(2, $measures['runs_reporting_promotion']);
    }

    #[Test]
    public function it_measures_bytes_held_on_each_disk_right_now(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        Storage::disk('historic_staging')->put("{$context->batchRoot}/temp/leftover.wav", str_repeat('a', 64));
        Storage::disk('historic_quarantine')->put('sermons/video/promoted.mp4', str_repeat('b', 128));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(64, $measures['staging_retained_bytes']);
        $this->assertSame(128, $measures['quarantine_bytes']);
    }

    #[Test]
    public function it_reports_review_source_bytes_as_a_separate_subset_of_staging_retention(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $log = $this->runWithPromotion($operation, null, 'held', $context);
        $log->forceFill(['source_file_path' => 'temp/review-source.mp4'])->save();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/temp/review-source.mp4",
            str_repeat('r', 29),
        );

        $measures = app(HistoricVideoPassMeasures::class)->report($operation, ['held']);

        $this->assertSame(29, $measures['review_source_retained_bytes']);
        $this->assertSame(29, $measures['staging_retained_bytes']);
        $this->assertSame(29, $measures['staging_accounted_bytes']);
    }

    /**
     * Staging output a run still owns is accounted for, so it is not residue.
     */
    #[Test]
    public function unpromoted_output_a_run_owns_is_not_counted_as_residue(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $log = $this->runWithPromotion($operation, null, stagingContext: $context);

        Sermon::factory()->create([
            'livestream_processing_id' => $log->processing_id,
            'video_file_path' => 'sermons/video/owned.mp4',
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
        ]);

        $log->forceFill([
            'rms_log_path' => 'service-transcripts/2024-01-01/morning-'.$log->processing_id.'.rms.json',
            'processing_metadata' => [
                'historic_import' => [
                    'staging_context' => $context->toArray(),
                ],
                'service_artifacts' => [[
                    'kind' => 'rms',
                    'disk' => 'historic_staging',
                    'path' => 'service-transcripts/2024-01-01/morning-'.$log->processing_id.'.rms.json',
                ]],
            ],
        ])->save();

        Storage::disk('historic_staging')->put("{$context->batchRoot}/sermons/video/owned.mp4", str_repeat('a', 40));
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/service-transcripts/2024-01-01/morning-{$log->processing_id}.rms.json",
            str_repeat('b', 12),
        );

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(52, $measures['staging_retained_bytes']);
        $this->assertSame(52, $measures['staging_accounted_bytes']);
        $this->assertSame(0, $measures['unexplained_residue_bytes']);
    }

    /**
     * The RMS logs the pilot left behind were named after a fresh UUID that no
     * record pointed at. That is exactly the shape residue takes.
     */
    #[Test]
    public function staging_bytes_no_run_can_explain_are_reported_as_unexplained_residue(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $this->runWithPromotion($operation, null, stagingContext: $context);

        Storage::disk('historic_staging')->put("{$context->batchRoot}/temp/rms_0d1c2b3a.log", str_repeat('a', 75));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(75, $measures['staging_retained_bytes']);
        $this->assertSame(0, $measures['staging_accounted_bytes']);
        $this->assertSame(75, $measures['unexplained_residue_bytes']);
    }

    #[Test]
    public function it_scopes_recorded_and_retained_bytes_to_the_selected_manifest_items(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $selected = $this->runWithPromotion($operation, [
            'promoted_bytes' => 100,
            'reclaimed_bytes' => 80,
            'staging_bytes_before_reclaim' => 900,
        ], 'selected', $context);
        $this->runWithPromotion($operation, [
            'promoted_bytes' => 500,
            'reclaimed_bytes' => 500,
            'staging_bytes_before_reclaim' => 2000,
        ], 'other', $context);

        $selected->forceFill(['source_file_path' => 'temp/selected.mp4'])->save();
        Storage::disk('historic_staging')->put("{$context->batchRoot}/temp/selected.mp4", str_repeat('a', 40));
        Storage::disk('historic_staging')->put("{$context->batchRoot}/temp/other.mp4", str_repeat('b', 70));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation, ['selected']);

        $this->assertSame(100, $measures['promoted_bytes']);
        $this->assertSame(80, $measures['reclaimed_bytes']);
        $this->assertSame(900, $measures['peak_working_bytes']);
        $this->assertSame(40, $measures['staging_retained_bytes']);
    }

    #[Test]
    public function it_scopes_residue_to_the_recorded_batch_root(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $run = $this->runWithPromotion($operation, null, 'selected', $context);

        $run->forceFill(['source_file_path' => 'temp/owned.mp4'])->save();
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/temp/owned.mp4",
            str_repeat('a', 40),
        );
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/temp/unknown.log",
            str_repeat('b', 7),
        );
        Storage::disk('historic_staging')->put(
            'historic-batches/previous-plan/temp/previous.mp4',
            str_repeat('c', 100),
        );
        Storage::disk('historic_staging')->put('outside-batch.txt', str_repeat('d', 200));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(47, $measures['staging_retained_bytes']);
        $this->assertSame(40, $measures['staging_accounted_bytes']);
        $this->assertSame(7, $measures['unexplained_residue_bytes']);
    }

    #[Test]
    public function it_accounts_for_song_video_bytes_and_review_held_section_candidates(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $log = $this->runWithPromotion($operation, null, stagingContext: $context);

        $songSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => null,
        ]);
        $songVideo = SongVideo::factory()->create([
            'song_id' => Song::factory(),
            'service_section_id' => $songSection->id,
            'video_file_path' => 'sermons/songs/7/song.mp4',
        ]);

        $heldSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'section-publications/held/video.mp4',
            'extracted_audio_path' => null,
        ]);

        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/{$songVideo->video_file_path}",
            str_repeat('s', 17),
        );
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/{$heldSection->extracted_video_path}",
            str_repeat('h', 23),
        );

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(40, $measures['staging_retained_bytes']);
        $this->assertSame(40, $measures['staging_accounted_bytes']);
        $this->assertSame(0, $measures['unexplained_residue_bytes']);
    }

    /**
     * @param  array<string, int>|null  $promotion
     */
    private function runWithPromotion(
        HistoricImportOperation $operation,
        ?array $promotion,
        ?string $itemKey = null,
        ?HistoricStagingContext $stagingContext = null,
    ): MediaProcessingLog {
        return MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'cleanup',
            'source_file_path' => null,
            'stored_file_path' => null,
            'enhanced_audio_file_path' => null,
            'video_file_path' => null,
            'processing_metadata' => array_filter([
                'historic_promotion' => $promotion,
                'historic_import' => $itemKey === null && $stagingContext === null ? null : array_filter([
                    'manifest_item_key' => $itemKey,
                    'staging_context' => $stagingContext?->toArray(),
                ], static fn (mixed $value): bool => $value !== null),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function stagingContextFor(HistoricImportOperation $operation): HistoricStagingContext
    {
        return app(HistoricStagingGuard::class)->contextForApprovedPlan(
            $operation->manifest_hashes['video'],
            $operation->plan_hash,
        );
    }
}
