<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Enums\SermonTitleProvenance;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairHistoricVideoSermonTitleProvenanceCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media-processing.storage.historic_quarantine_disk' => 'historic_quarantine']);
    }

    #[Test]
    public function it_is_dry_run_by_default(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertNull($sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_requires_confirmation_to_apply(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes confirmation')
            ->assertFailed();

        self::assertNull($sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_records_generated_provenance_for_a_reproducible_filename_title(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        // The canary shape: a filename title the placeholder recogniser refuses
        // to match, so banked analysis can never replace it while provenance is
        // null.
        self::assertSame('Sunday 23 January 2022 101', $sermon->title);
        self::assertNull($sermon->title_provenance);

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Recorded 1 generated title provenance value(s)')
            ->assertSuccessful();

        self::assertSame(SermonTitleProvenance::Generated, $sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_is_idempotent_once_provenance_is_recorded(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        $sermon->forceFill(['title_provenance' => SermonTitleProvenance::Generated])->save();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('already recorded: 1')
            ->assertSuccessful();

        self::assertSame(SermonTitleProvenance::Generated, $sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_refuses_a_title_that_does_not_reproduce_from_the_filename(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        $sermon->forceFill(['title' => 'A genuinely curated carol service'])->save();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('refused: 1')
            ->assertSuccessful();

        self::assertNull($sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_refuses_a_row_whose_provenance_is_already_curated(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        $sermon->forceFill(['title_provenance' => SermonTitleProvenance::Curated])->save();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('refused: 1')
            ->assertSuccessful();

        self::assertSame(SermonTitleProvenance::Curated, $sermon->fresh()?->title_provenance);
    }

    #[Test]
    public function it_rejects_a_run_outside_the_named_operation(): void
    {
        [, $log] = $this->historicRun();
        $otherOperation = $this->createHistoricImportOperation();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $otherOperation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])->assertFailed();
    }

    #[Test]
    public function it_rejects_a_sermon_not_owned_by_private_quarantine(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        $sermon->forceFill([
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
        ])->save();

        $this->artisan('historic-import:repair-video-sermon-title-provenance', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('is not private media owned by the named historic operation')
            ->assertFailed();

        self::assertNull($sermon->fresh()?->title_provenance);
    }

    /** @return array{0: HistoricImportOperation, 1: MediaProcessingLog, 2: Sermon} */
    private function historicRun(): array
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'original_filename' => 'Sunday 23 January 2022 101.mp4',
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'title-provenance-repair-job',
                    'operation_id' => $operation->operation_id,
                ],
            ],
        ]);
        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'title' => 'Sunday 23 January 2022 101',
            'title_provenance' => null,
            'date' => '2022-01-23',
            'service' => SermonService::Morning,
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        return [$operation, $log, $sermon];
    }
}
