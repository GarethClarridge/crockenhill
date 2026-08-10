<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\HistoricImportArtifactKind;
use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use App\Enums\HistoricImportOperationState;
use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportOperationCloseout;
use App\Services\Import\HistoricImportTargetFingerprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricImportOperationCloseoutTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private string $ledgerPath;

    /** @var list<string> */
    private array $artifactPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'historic-final-closeout-');
        self::assertIsString($path);
        $this->ledgerPath = $path;
        $this->app->instance(HistoricConvergenceLedger::class, new HistoricConvergenceLedger($path));
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }

        foreach ($this->artifactPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_completes_only_after_exact_runtime_recovery_audit_and_no_op_evidence(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash(), [
            'state' => HistoricImportOperationState::CloseoutRequired,
        ]);
        $checkpoint = $operation->checkpoints()->create([
            'checkpoint_key' => 'checkpoint-1',
            'ordinal' => 1,
            'membership_hash' => str_repeat('1', 64),
            'item_keys' => ['video-1'],
            'forecast_seconds' => 60,
            'state' => HistoricImportCheckpointState::Complete,
            'settled_at' => now(),
        ]);
        $sourceArtifact = $operation->artifacts()->create([
            'artifact_key' => 'source-video-1',
            'kind' => HistoricImportArtifactKind::SourceSnapshot,
            'storage_disk' => 'local',
            'relative_path' => 'historic-import/source-video-1.enc',
            'sha256' => str_repeat('2', 64),
            'byte_size' => 10,
            'encrypted' => true,
        ]);
        $snapshot = $operation->sourceSnapshots()->create([
            'historic_import_checkpoint_id' => $checkpoint->id,
            'historic_import_artifact_id' => $sourceArtifact->id,
            'source_kind' => 'video',
            'item_key' => 'video-1',
            'file_key' => 'source',
            'relative_path' => 'video-1.mkv',
            'approved_sha256' => str_repeat('3', 64),
            'observed_sha256' => str_repeat('3', 64),
            'byte_size' => 100,
            'file_identity' => ['device' => 1, 'inode' => 2],
            'captured_at' => now(),
        ]);
        $operation->itemOutcomes()->create([
            'historic_import_checkpoint_id' => $checkpoint->id,
            'historic_import_source_snapshot_id' => $snapshot->id,
            'source_kind' => 'video',
            'item_key' => 'video-1',
            'expectation' => HistoricImportItemExpectation::Process,
            'disposition' => HistoricImportDisposition::ExactComplete,
            'approved_source_sha256' => str_repeat('3', 64),
            'observed_source_sha256' => str_repeat('3', 64),
            'output_hashes' => ['bundle' => str_repeat('4', 64)],
            'settled_at' => now(),
        ]);
        $binding = [
            'batch_hash' => str_repeat('6', 64),
            'media_bundle_hash' => str_repeat('7', 64),
            'convergence_bundle_hash' => str_repeat('8', 64),
            'processing_fingerprint_hash' => str_repeat('9', 64),
            'target_fingerprint' => $operation->target_fingerprint,
            'plan_hash' => $operation->plan_hash,
            'content_hash' => str_repeat('e', 64),
            'identity_hash' => str_repeat('a', 64),
            'service_count' => 1,
            'report_digest' => str_repeat('b', 64),
        ];
        $writer = app(HistoricImportArtifactWriter::class);
        $writer->writeJson(
            operation: $operation,
            artifactKey: 'recovery-rehearsal',
            kind: HistoricImportArtifactKind::Backup,
            relativePath: 'recovery/rehearsal.json.enc',
            payload: [
                'format' => 'crockenhill-historic-import-recovery',
                'version' => 1,
                'operation_id' => $operation->operation_id,
                'target_fingerprint' => $operation->target_fingerprint,
            ],
            redact: false,
        );
        $writer->writeJson(
            operation: $operation,
            artifactKey: 'operational-closeout-readiness',
            kind: HistoricImportArtifactKind::AcceptanceReport,
            relativePath: 'closeout/operational-readiness.json.enc',
            payload: [
                'format' => 'crockenhill-historic-import-operational-closeout',
                'version' => 1,
                'operation_id' => $operation->operation_id,
                'target_fingerprint' => $operation->target_fingerprint,
                'audit_report_sha256' => $binding['report_digest'],
            ],
            redact: false,
        );
        $this->artifactPaths = [
            storage_path("app/private/historic-import/{$operation->operation_id}/recovery/rehearsal.json.enc"),
            storage_path("app/private/historic-import/{$operation->operation_id}/closeout/operational-readiness.json.enc"),
        ];
        $ledger = app(HistoricConvergenceLedger::class);
        $ledger->append([
            'event' => 'exact_audit_passed',
            'operation_id' => $operation->operation_id,
            ...$binding,
            'passed' => true,
        ]);
        $ledger->append([
            'event' => 'exact_noop_rerun',
            'operation_id' => $operation->operation_id,
            ...array_diff_key($binding, ['report_digest' => true]),
            'passed' => true,
        ]);

        $closed = app(HistoricImportOperationCloseout::class)->complete($operation->operation_id, $binding);

        $this->assertSame(HistoricImportOperationState::Complete, $closed->state);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'operation_closeout_complete',
        ]);
    }
}
