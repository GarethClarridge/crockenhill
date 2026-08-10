<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
