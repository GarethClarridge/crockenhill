<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Services\Processing\HistoricWorkingCopyReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricWorkingCopyReachabilityTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    /**
     * The two branches were combined with a raw OR, which the query builder does
     * not parenthesise. SQL binds AND tighter than OR, so the state filter
     * applied only to the second branch and a completed store job was read as
     * unsettled — deferring cleanup twelve times and then failing the run.
     */
    #[Test]
    public function completed_sermon_video_storage_does_not_read_as_unsettled(): void
    {
        $log = $this->historicRunWithStorageState('completed');

        self::assertNull(app(HistoricWorkingCopyReachability::class)->unsettledWork($log));
    }

    #[Test]
    public function in_flight_sermon_video_storage_still_reads_as_unsettled(): void
    {
        foreach (['queued', 'running', 'retryable'] as $state) {
            $log = $this->historicRunWithStorageState($state);
            $unsettled = app(HistoricWorkingCopyReachability::class)->unsettledWork($log);

            self::assertNotNull($unsettled, "State {$state} must block cleanup.");
            self::assertFalse($unsettled['terminal']);
            self::assertStringContainsString($state, $unsettled['description']);
        }
    }

    #[Test]
    public function permanently_failed_sermon_video_storage_is_surfaced_as_terminal(): void
    {
        $log = $this->historicRunWithStorageState('failed');
        $unsettled = app(HistoricWorkingCopyReachability::class)->unsettledWork($log);

        self::assertNotNull($unsettled);
        self::assertTrue($unsettled['terminal']);
    }

    /**
     * The unparenthesised OR also left the second branch unscoped by operation
     * and run, so another run's in-flight work could answer for this one.
     */
    #[Test]
    public function another_runs_unsettled_work_does_not_block_this_run(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $otherLog = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
        ]);

        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $otherLog->id,
            'job_key' => 'prepare-section-publication-candidates-'.$otherLog->processing_id,
            'job_type' => 'App\\Jobs\\PrepareSectionPublicationCandidates',
            'state' => 'running',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        self::assertNull(app(HistoricWorkingCopyReachability::class)->unsettledWork($log));
    }

    #[Test]
    public function a_non_historic_run_has_no_unsettled_historic_work(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => null,
        ]);

        self::assertNull(app(HistoricWorkingCopyReachability::class)->unsettledWork($log));
    }

    private function historicRunWithStorageState(string $state): MediaProcessingLog
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
        ]);

        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => $state,
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        return $log;
    }
}
