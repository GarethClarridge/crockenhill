<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
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

        $this->runWithPromotion($operation, ['promoted_bytes' => 100, 'reclaimed_bytes' => 100, 'staging_bytes_before_reclaim' => 900]);
        $this->runWithPromotion($operation, ['promoted_bytes' => 250, 'reclaimed_bytes' => 200, 'staging_bytes_before_reclaim' => 1500]);

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
        Storage::disk('historic_staging')->put('temp/leftover.wav', str_repeat('a', 64));
        Storage::disk('historic_quarantine')->put('sermons/video/promoted.mp4', str_repeat('b', 128));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(64, $measures['staging_retained_bytes']);
        $this->assertSame(128, $measures['quarantine_bytes']);
    }

    /**
     * Staging output a run still owns is accounted for, so it is not residue.
     */
    #[Test]
    public function unpromoted_output_a_run_owns_is_not_counted_as_residue(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = $this->runWithPromotion($operation, null);

        Sermon::factory()->create([
            'livestream_processing_id' => $log->processing_id,
            'video_file_path' => 'sermons/video/owned.mp4',
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
        ]);

        Storage::disk('historic_staging')->put('sermons/video/owned.mp4', str_repeat('a', 40));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(40, $measures['staging_retained_bytes']);
        $this->assertSame(40, $measures['staging_accounted_bytes']);
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
        $this->runWithPromotion($operation, null);

        Storage::disk('historic_staging')->put('temp/rms_0d1c2b3a.log', str_repeat('a', 75));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation);

        $this->assertSame(75, $measures['staging_retained_bytes']);
        $this->assertSame(0, $measures['staging_accounted_bytes']);
        $this->assertSame(75, $measures['unexplained_residue_bytes']);
    }

    #[Test]
    public function it_scopes_recorded_and_retained_bytes_to_the_selected_manifest_items(): void
    {
        $operation = $this->createHistoricImportOperation();
        $selected = $this->runWithPromotion($operation, [
            'promoted_bytes' => 100,
            'reclaimed_bytes' => 80,
            'staging_bytes_before_reclaim' => 900,
        ], 'selected');
        $this->runWithPromotion($operation, [
            'promoted_bytes' => 500,
            'reclaimed_bytes' => 500,
            'staging_bytes_before_reclaim' => 2000,
        ], 'other');

        $selected->forceFill(['source_file_path' => 'temp/selected.mp4'])->save();
        Storage::disk('historic_staging')->put('temp/selected.mp4', str_repeat('a', 40));
        Storage::disk('historic_staging')->put('temp/other.mp4', str_repeat('b', 70));

        $measures = app(HistoricVideoPassMeasures::class)->report($operation, ['selected']);

        $this->assertSame(100, $measures['promoted_bytes']);
        $this->assertSame(80, $measures['reclaimed_bytes']);
        $this->assertSame(900, $measures['peak_working_bytes']);
        $this->assertSame(40, $measures['staging_retained_bytes']);
    }

    /**
     * @param  array<string, int>|null  $promotion
     */
    private function runWithPromotion(
        HistoricImportOperation $operation,
        ?array $promotion,
        ?string $itemKey = null,
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
                'historic_import' => $itemKey === null ? null : ['manifest_item_key' => $itemKey],
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
