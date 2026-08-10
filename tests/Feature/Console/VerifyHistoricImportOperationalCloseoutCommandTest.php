<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ImportIngressLock;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class VerifyHistoricImportOperationalCloseoutCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.release_identifier', 'release-closeout-test');
        config()->set('media-processing.historic_import.evidence_signing_key', 'closeout-signing-key');
    }

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
    public function it_retains_signed_smoke_monitoring_and_runtime_recovery_evidence_after_ingress_release(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $path = $this->writeJson($evidence);
        $this->paths[] = storage_path(
            "app/private/historic-import/{$operation->operation_id}/closeout/operational-readiness.json.enc",
        );

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $path,
        ])->assertSuccessful();

        $this->assertDatabaseHas('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'operational-closeout-readiness',
            'kind' => 'acceptance_report',
        ]);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'operational_closeout_verified',
        ]);
    }

    #[Test]
    public function it_rejects_a_failed_smoke_or_an_unreleased_ingress_window(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $evidence['smoke']['admin']['passed'] = false;
        $evidence = $this->sign($evidence);

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence),
        ])
            ->expectsOutput('Operational closeout smoke admin did not pass.')
            ->assertFailed();

        $this->assertDatabaseMissing('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'operational-closeout-readiness',
        ]);
    }

    private function releasedLock(string $operationId): ImportIngressLock
    {
        return ImportIngressLock::query()->create([
            'operation_id' => $operationId,
            'reason' => 'Historic import closeout test',
            'blocked_by' => 'operator@example.test',
            'blocked_at' => now()->subMinutes(10),
            'released_at' => now(),
            'queue_pause_accounting' => [
                'supervisors_to_pause' => ['historic' => ['historic']],
                'collateral_depth_at_release' => [],
                'collateral_delay_minutes' => 10,
            ],
            'is_active' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function evidence(string $operationId, string $targetFingerprint, ImportIngressLock $lock): array
    {
        $passed = ['passed' => true, 'evidence_sha256' => str_repeat('2', 64)];
        $evidence = [
            'format' => 'crockenhill-historic-import-operational-closeout',
            'version' => 1,
            'operation_id' => $operationId,
            'target_fingerprint' => $targetFingerprint,
            'release_identifier' => 'release-closeout-test',
            'audit_report_sha256' => str_repeat('1', 64),
            'ingress_release' => [
                'blocked_at' => $lock->blocked_at->toIso8601String(),
                'released_at' => $lock->released_at?->toIso8601String(),
                'queue_pause_accounting_sha256' => CanonicalJson::hash($lock->queue_pause_accounting),
            ],
            'smoke' => ['public' => $passed, 'admin' => $passed],
            'runtime_recovery' => ['workers' => $passed, 'scheduler' => $passed, 'queues' => $passed],
            'monitoring' => [
                'watchboard_sha256' => str_repeat('3', 64),
                'unresolved_exceptions' => 0,
                'failed_jobs' => 0,
                'timed_out_jobs' => 0,
            ],
            'deferred_inbound' => [
                'pending' => 0,
                'failed' => 0,
                'reconciled_at' => now()->toIso8601String(),
            ],
            'verified_by' => 'independent-verifier@example.test',
            'verified_at' => now()->toIso8601String(),
            'signature' => ['algorithm' => 'hmac-sha256', 'key_id' => 'test-key', 'digest' => ''],
        ];

        return $this->sign($evidence);
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function sign(array $evidence): array
    {
        $evidence['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($evidence, ['signature' => true])),
            'closeout-signing-key',
        );

        return $evidence;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(array $value): string
    {
        $path = sys_get_temp_dir().'/historic-operational-closeout-'.uniqid().'.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
        $this->paths[] = $path;

        return $path;
    }
}
