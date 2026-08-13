<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class VerifyHistoricImportRecoveryCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_retains_only_exact_successfully_restored_recovery_evidence(): void
    {
        $operation = $this->createHistoricImportOperation();
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint);
        $path = $this->writeJson($evidence);
        $artifactPath = storage_path("app/private/historic-import/{$operation->operation_id}/recovery/rehearsal.json.enc");
        $this->paths[] = $artifactPath;

        $this->artisan('historic-import:verify-recovery', [
            'operation' => $operation->operation_id,
            'evidence' => $path,
        ])->assertSuccessful();

        $this->assertDatabaseHas('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'recovery-rehearsal',
            'kind' => 'backup',
        ]);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'recovery_rehearsal_verified',
        ]);
    }

    /**
     * HIR0 red test for review finding 3 (High), owned by package **HIR5**.
     *
     * The document below is the one this suite already accepts, and every claim
     * in it is unbacked: nothing authenticates the author, `verified_by` is a
     * free-text string, every digest is a repeated placeholder digit that
     * matches no artifact anywhere, and the same backup object is presented as
     * both the on-host and the off-host restore — so the "two independent
     * copies" the gate exists to prove are one copy counted twice.
     *
     * `HistoricImportRecoveryEvidence::verify()` checks shape, digest *syntax*
     * and success booleans. It never resolves a backup, report or exercise
     * artifact and never recomputes a digest, so this passes and is written into
     * encrypted storage, where exact closeout then reads it as the mandatory
     * recovery gate's satisfied evidence.
     *
     * Note what this test does and does not assert. HIR-D3 reuses the existing
     * approval signing key, which closes forgery by an arbitrary party but not
     * verifier independence — the application holds the symmetric secret. The
     * assurance HIR5 must actually deliver is the artifact-backed half below:
     * an accepted digest has to be reproducible from a retained artifact.
     *
     * This deliberately contradicts
     * {@see self::it_retains_only_exact_successfully_restored_recovery_evidence()},
     * which asserts the same document is accepted. That test is superseded
     * evidence: HIR5 replaces its fixture with a signed, artifact-backed one
     * rather than deleting the case.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 3
     * @see docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §11 (HIR5), §4.3 (HIR-D3)
     */
    #[Test]
    #[Group('hir-red')]
    public function it_refuses_unsigned_evidence_whose_named_artifacts_are_never_opened(): void
    {
        $operation = $this->createHistoricImportOperation();
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint);

        $this->assertArrayNotHasKey('signature', $evidence, 'The finding is that nothing authenticates this document.');
        $this->assertSame(
            $evidence['database_backups']['on_host'],
            $evidence['database_backups']['off_host'],
            'The finding is that one backup can stand in for two independent ones.',
        );

        $this->artisan('historic-import:verify-recovery', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence),
        ])->assertFailed();

        $this->assertDatabaseMissing('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'recovery-rehearsal',
        ]);
        $this->assertDatabaseMissing('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'recovery_rehearsal_verified',
        ]);
    }

    #[Test]
    public function it_rejects_cleanup_race_evidence_that_did_not_preserve_a_foreign_object(): void
    {
        $operation = $this->createHistoricImportOperation();
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint);
        $evidence['object_recovery']['foreign_before_cleanup_preserved'] = false;

        $this->artisan('historic-import:verify-recovery', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence),
        ])
            ->expectsOutput('Object recovery lacks version/create-only ownership and race evidence.')
            ->assertFailed();

        $this->assertDatabaseMissing('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'recovery-rehearsal',
        ]);
    }

    /** @return array<string, mixed> */
    private function evidence(string $operationId, string $targetFingerprint): array
    {
        $backup = [
            'artifact_sha256' => str_repeat('1', 64),
            'transaction_snapshot' => 'mysql-bin.000001:1234',
            'table_row_manifest_sha256' => str_repeat('2', 64),
            'restored_at' => '2026-08-09T12:00:00+00:00',
            'restored_target_fingerprint' => str_repeat('3', 64),
            'observed_rpo_seconds' => 0,
            'observed_rto_seconds' => 120,
            'restore_passed' => true,
        ];
        $artifact = ['independent_copy_count' => 2, 'sha256' => str_repeat('4', 64)];
        $exercise = ['passed' => true, 'duration_seconds' => 90, 'evidence_sha256' => str_repeat('5', 64)];

        return [
            'format' => 'crockenhill-historic-import-recovery',
            'version' => 1,
            'operation_id' => $operationId,
            'target_fingerprint' => $targetFingerprint,
            'accepted_rpo_seconds' => 60,
            'accepted_rto_seconds' => 300,
            'database_backups' => ['on_host' => $backup, 'off_host' => $backup],
            'object_recovery' => [
                'mode' => 'operation_owned_create_only',
                'evidence_sha256' => str_repeat('6', 64),
                'foreign_between_check_write_preserved' => true,
                'foreign_before_cleanup_preserved' => true,
                'ownership_reverified_before_delete' => true,
            ],
            'preserved_artifacts' => [
                'source' => $artifact,
                'bundles' => $artifact,
                'results' => $artifact,
                'private_staging' => $artifact,
                'journal' => $artifact,
            ],
            'exercises' => [
                'mid_service_failure' => $exercise,
                'cross_service_failure' => $exercise,
                'asset_compensation' => $exercise,
                'full_restore' => $exercise,
                'repeat_apply' => $exercise,
            ],
            'verified_by' => 'independent-verifier@example.test',
            'verified_at' => '2026-08-09T13:00:00+00:00',
        ];
    }

    /** @param array<string, mixed> $value */
    private function writeJson(array $value): string
    {
        $path = sys_get_temp_dir().'/historic-recovery-'.uniqid().'.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
        $this->paths[] = $path;

        return $path;
    }
}
