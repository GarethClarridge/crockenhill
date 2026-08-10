<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Data\HistoricImportOperationIdentity;
use App\Enums\HistoricImportArtifactKind;
use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricImportOperationSchemaTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_shared_operation_contract_has_durable_owned_records(): void
    {
        $this->assertTrue(Schema::hasColumns('historic_import_operations', [
            'operation_id',
            'binding_hash',
            'manifest_hashes',
            'plan_hash',
            'target_fingerprint',
            'runtime_fingerprint',
            'state',
        ]));
        $this->assertTrue(Schema::hasColumns('historic_import_checkpoints', [
            'historic_import_operation_id',
            'checkpoint_key',
            'membership_hash',
            'item_keys',
            'state',
        ]));
        $this->assertTrue(Schema::hasColumns('historic_import_source_snapshots', [
            'historic_import_operation_id',
            'historic_import_artifact_id',
            'approved_sha256',
            'observed_sha256',
            'file_identity',
        ]));
        $this->assertTrue(Schema::hasColumns('historic_import_journal_entries', [
            'historic_import_operation_id',
            'sequence',
            'previous_entry_hash',
            'entry_hash',
        ]));
        $this->assertTrue(Schema::hasColumns('historic_import_item_outcomes', [
            'historic_import_operation_id',
            'expectation',
            'disposition',
            'output_hashes',
        ]));
    }

    #[Test]
    public function operation_and_checkpoint_transitions_are_fail_closed(): void
    {
        $operation = $this->operation();
        $checkpoint = $operation->checkpoints()->create([
            'checkpoint_key' => 'video-001',
            'ordinal' => 1,
            'membership_hash' => str_repeat('e', 64),
            'item_keys' => ['video-1'],
            'forecast_seconds' => 600,
            'state' => HistoricImportCheckpointState::Planned,
        ]);

        $operation->transitionTo(HistoricImportOperationState::Running);
        $checkpoint->transitionTo(HistoricImportCheckpointState::Admitted);
        $checkpoint->transitionTo(HistoricImportCheckpointState::Running);
        $checkpoint->transitionTo(HistoricImportCheckpointState::Complete);

        $this->expectException(LogicException::class);
        $checkpoint->transitionTo(HistoricImportCheckpointState::Running);
    }

    #[Test]
    public function source_artifact_and_journal_evidence_is_immutable_and_owned_by_one_operation(): void
    {
        $operation = $this->operation();
        $checkpoint = $operation->checkpoints()->create([
            'checkpoint_key' => 'video-001',
            'ordinal' => 1,
            'membership_hash' => str_repeat('e', 64),
            'item_keys' => ['video-1'],
            'forecast_seconds' => 600,
            'state' => HistoricImportCheckpointState::Planned,
        ]);
        $artifact = $operation->artifacts()->create([
            'historic_import_checkpoint_id' => $checkpoint->id,
            'artifact_key' => 'source/video-1/segment-1',
            'kind' => HistoricImportArtifactKind::SourceSnapshot,
            'storage_disk' => 'historic_staging',
            'relative_path' => 'operations/'.$operation->operation_id.'/source/video-1.bin',
            'sha256' => str_repeat('a', 64),
            'byte_size' => 100,
            'encrypted' => true,
        ]);
        $snapshot = $operation->sourceSnapshots()->create([
            'historic_import_checkpoint_id' => $checkpoint->id,
            'historic_import_artifact_id' => $artifact->id,
            'source_kind' => 'video',
            'item_key' => 'video-1',
            'file_key' => 'segment-1',
            'relative_path' => '2010/video.mkv',
            'approved_sha256' => str_repeat('a', 64),
            'observed_sha256' => str_repeat('a', 64),
            'byte_size' => 100,
            'file_identity' => ['device' => 1, 'inode' => 2],
            'captured_at' => now(),
        ]);
        $journal = $operation->journalEntries()->create([
            'historic_import_checkpoint_id' => $checkpoint->id,
            'sequence' => 1,
            'event' => 'source_snapshotted',
            'payload' => ['item_key' => 'video-1'],
            'previous_entry_hash' => null,
            'entry_hash' => str_repeat('b', 64),
            'recorded_at' => now(),
        ]);

        $this->assertTrue($artifact->operation->is($operation));
        $this->assertTrue($snapshot->artifact->is($artifact));
        $this->assertTrue($journal->checkpoint->is($checkpoint));

        $this->expectException(LogicException::class);
        $artifact->update(['sha256' => str_repeat('c', 64)]);
    }

    private function operation(): HistoricImportOperation
    {
        $identity = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'archive-2026-08',
            manifestHashes: ['email' => str_repeat('a', 64)],
            planHash: str_repeat('b', 64),
            targetFingerprint: str_repeat('c', 64),
        );

        return HistoricImportOperation::query()->create([
            ...$identity->toArray(),
            'state' => HistoricImportOperationState::Planned,
        ]);
    }
}
