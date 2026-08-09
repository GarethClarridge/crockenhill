<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use App\Enums\HistoricImportOperationState;
use App\Services\Import\HistoricImportCheckpointPlanner;
use App\Services\Import\HistoricImportCheckpointRuntime;
use App\Services\Import\HistoricImportCostLedger;
use App\Services\Import\HistoricImportJournal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricImportCheckpointRuntimeTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    #[Test]
    public function it_plans_immutable_ordered_checkpoints_with_both_hard_bounds(): void
    {
        $operation = $this->createHistoricImportOperation();
        $items = [];

        for ($index = 1; $index <= 26; $index++) {
            $items[] = [
                'item_key' => sprintf('video-%02d', $index),
                'forecast_seconds' => 1_800,
                'accepted_cost_minor_units' => 100,
            ];
        }

        $checkpoints = app(HistoricImportCheckpointPlanner::class)->plan($operation, $items);

        $this->assertCount(2, $checkpoints);
        $this->assertCount(24, $checkpoints[0]->item_keys);
        $this->assertSame(43_200, $checkpoints[0]->forecast_seconds);
        $this->assertSame(['video-25', 'video-26'], $checkpoints[1]->item_keys);
        $this->assertSame(2_600, $checkpoints[0]->accepted_cost_minor_units + $checkpoints[1]->accepted_cost_minor_units);
        app(HistoricImportJournal::class)->verify($operation);
    }

    #[Test]
    public function it_survives_a_runtime_restart_and_requires_reconciliation_before_more_dispatch(): void
    {
        $fingerprint = str_repeat('c', 64);
        $operation = $this->createHistoricImportOperation($fingerprint);
        $checkpoint = app(HistoricImportCheckpointPlanner::class)->plan($operation, [
            ['item_key' => 'video-1', 'forecast_seconds' => 3_600, 'accepted_cost_minor_units' => 500],
            ['item_key' => 'video-2', 'forecast_seconds' => 3_600, 'accepted_cost_minor_units' => 500],
        ])[0];
        $runtime = app(HistoricImportCheckpointRuntime::class);
        $runtime->admit($checkpoint, $fingerprint);
        $deduplicationKey = $runtime->beforeDispatch($checkpoint, 'video-1');
        $runtime->afterDispatch($checkpoint, 'video-1', 'processing-1', $deduplicationKey);

        $restartedRuntime = new HistoricImportCheckpointRuntime(app(HistoricImportJournal::class));
        $entry = app(HistoricImportCostLedger::class)->record(
            $checkpoint,
            'request-1',
            'video-1',
            'openai',
            'gpt-5-mini',
            125,
            inputTokens: 1_000,
            outputTokens: 100,
        );
        $sameEntry = app(HistoricImportCostLedger::class)->record(
            $checkpoint,
            'request-1',
            'video-1',
            'openai',
            'gpt-5-mini',
            125,
            inputTokens: 1_000,
            outputTokens: 100,
        );
        $this->assertTrue($entry->is($sameEntry));

        $restartedRuntime->settle(
            $checkpoint,
            'video',
            'video-1',
            HistoricImportItemExpectation::Process,
            HistoricImportDisposition::ExactComplete,
            str_repeat('1', 64),
            str_repeat('1', 64),
            ['bundle' => str_repeat('2', 64)],
        );
        $restartedRuntime->recordAnomaly($checkpoint, 'worker_lost', ['processing_id' => 'processing-2']);

        $this->assertSame(HistoricImportCheckpointState::ReconciliationRequired, $checkpoint->fresh()->state);
        $this->assertSame(HistoricImportOperationState::ReconciliationRequired, $operation->fresh()->state);

        try {
            $restartedRuntime->resumeAfterReconciliation($checkpoint, $fingerprint, ['processing-2']);
            $this->fail('Live timed-out work must block resume.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('remains live', $exception->getMessage());
        }

        $restartedRuntime->settle(
            $checkpoint,
            'video',
            'video-2',
            HistoricImportItemExpectation::Process,
            HistoricImportDisposition::ExactAlreadyPresent,
            str_repeat('3', 64),
            str_repeat('3', 64),
            ['bundle' => str_repeat('4', 64)],
        );
        $restartedRuntime->resumeAfterReconciliation($checkpoint, $fingerprint, []);
        $restartedRuntime->complete($checkpoint);

        $this->assertSame(HistoricImportCheckpointState::Complete, $checkpoint->fresh()->state);
        $this->assertSame(HistoricImportOperationState::CloseoutRequired, $operation->fresh()->state);
        $this->assertEquals(125, $operation->usageEntries()->sum('cost_minor_units'));
        app(HistoricImportJournal::class)->verify($operation->fresh());
    }

    #[Test]
    public function a_timeout_is_durable_and_blocks_new_admission_until_live_work_is_cleared(): void
    {
        $fingerprint = str_repeat('c', 64);
        $operation = $this->createHistoricImportOperation($fingerprint);
        $checkpoint = app(HistoricImportCheckpointPlanner::class)->plan($operation, [
            ['item_key' => 'video-1', 'forecast_seconds' => 60],
        ])[0];
        $runtime = app(HistoricImportCheckpointRuntime::class);
        $runtime->admit($checkpoint, $fingerprint);
        DB::table('historic_import_checkpoints')->where('id', $checkpoint->id)->update(['deadline_at' => now()->subMinute()]);

        try {
            $runtime->beforeDispatch($checkpoint, 'video-1');
            $this->fail('Elapsed checkpoint must stop admission.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deadline elapsed', $exception->getMessage());
        }

        $this->assertSame(HistoricImportCheckpointState::ReconciliationRequired, $checkpoint->fresh()->state);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'reconciliation_required',
        ]);
    }

    #[Test]
    public function portable_dispatch_identity_ignores_mount_roots_and_journal_tampering_is_detected(): void
    {
        $operation = $this->createHistoricImportOperation();
        $checkpoint = app(HistoricImportCheckpointPlanner::class)->plan($operation, [
            ['item_key' => 'video-1', 'forecast_seconds' => 60],
        ])[0];
        $runtime = app(HistoricImportCheckpointRuntime::class);
        $runtime->admit($checkpoint, $operation->target_fingerprint);

        config(['filesystems.disks.historic_staging.root' => '/first/mount']);
        $first = $runtime->beforeDispatch($checkpoint, 'video-1');
        config(['filesystems.disks.historic_staging.root' => '/second/mount']);
        $recorded = $operation->journalEntries()->where('event', 'dispatch_started')->firstOrFail();

        $this->assertSame($first, $recorded->payload['deduplication_key']);

        DB::table('historic_import_journal_entries')->where('id', $recorded->id)->update([
            'payload' => json_encode(['item_key' => 'tampered'], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed its hash check');
        app(HistoricImportJournal::class)->verify($operation->fresh());
    }

    #[Test]
    public function numeric_cost_thresholds_abort_before_a_usage_entry_is_written(): void
    {
        $operation = $this->createHistoricImportOperation(attributes: ['max_cost_minor_units' => 100]);
        $checkpoint = app(HistoricImportCheckpointPlanner::class)->plan($operation, [
            ['item_key' => 'video-1', 'forecast_seconds' => 60, 'accepted_cost_minor_units' => 50],
        ])[0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checkpoint cost threshold');

        try {
            app(HistoricImportCostLedger::class)->record(
                $checkpoint,
                'request-over-budget',
                'video-1',
                'openai',
                'gpt-5-mini',
                51,
            );
        } finally {
            $this->assertDatabaseCount('historic_import_usage_entries', 0);
        }
    }

    #[Test]
    public function operation_bindings_and_checkpoint_membership_are_immutable(): void
    {
        $operation = $this->createHistoricImportOperation();
        $checkpoint = app(HistoricImportCheckpointPlanner::class)->plan($operation, [
            ['item_key' => 'video-1', 'forecast_seconds' => 60],
        ])[0];

        try {
            $operation->update(['target_fingerprint' => str_repeat('d', 64)]);
            $this->fail('Operation binding mutation must fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('bindings are immutable', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('membership is immutable');
        $checkpoint->update(['item_keys' => ['video-2']]);
    }
}
