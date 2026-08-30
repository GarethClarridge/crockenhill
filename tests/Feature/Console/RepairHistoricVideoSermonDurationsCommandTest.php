<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairHistoricVideoSermonDurationsCommandTest extends TestCase
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

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('1500')
            ->assertSuccessful();

        self::assertSame(3600.0, (float) $sermon->fresh()->duration);
    }

    #[Test]
    public function it_requires_confirmation_before_apply(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();

        self::assertSame(3600.0, (float) $sermon->fresh()->duration);
    }

    #[Test]
    public function it_repairs_only_duration_from_the_gapped_extraction_plan(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Repaired 1 sermon duration')
            ->assertSuccessful();

        $sermon->refresh();
        self::assertSame(1500.0, (float) $sermon->duration);
        self::assertSame('Curated title', $sermon->title);
        self::assertSame('John 3:16', $sermon->reference);
        self::assertSame('Curated series', $sermon->series);
        self::assertSame('Curated preacher', $sermon->preacher);

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Repaired 0 sermon duration(s); already repaired: 1')
            ->assertSuccessful();
    }

    #[Test]
    public function it_rejects_wrong_operation_non_completed_and_missing_plan_runs(): void
    {
        [$operation, $log] = $this->historicRun();
        $otherOperation = $this->createHistoricImportOperation();

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $otherOperation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])->assertFailed();

        $log->forceFill(['status' => ProcessingStatus::Processing])->save();
        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('must be a completed livestream run')
            ->assertFailed();

        $metadata = $log->processing_metadata?->toArray() ?? [];
        unset($metadata['sermon_extraction_plan']);
        $log->forceFill([
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $metadata,
            'sermon_start_time' => null,
            'sermon_end_time' => null,
        ])->save();

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('no positive recorded extraction duration')
            ->assertFailed();
    }

    #[Test]
    public function it_rejects_a_sermon_not_owned_by_private_quarantine(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        $sermon->forceFill([
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
        ])->save();

        $this->artisan('historic-import:repair-video-sermon-durations', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('is not private media owned by the named historic operation')
            ->assertFailed();

        self::assertSame(3600.0, (float) $sermon->fresh()->duration);
    }

    /** @return array{0: HistoricImportOperation, 1: MediaProcessingLog, 2: Sermon} */
    private function historicRun(): array
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'duration-repair-job',
                    'operation_id' => $operation->operation_id,
                ],
                'sermon_extraction_plan' => [
                    'segments' => [
                        ['start_time' => 300.0, 'end_time' => 1200.0],
                        ['start_time' => 1500.0, 'end_time' => 2100.0],
                    ],
                ],
            ],
        ]);
        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'duration' => 3600.0,
            'title' => 'Curated title',
            'reference' => 'John 3:16',
            'series' => 'Curated series',
            'preacher' => 'Curated preacher',
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        return [$operation, $log, $sermon];
    }
}
