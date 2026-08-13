<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Models\InboundEmail;
use App\Models\SongUsageReport;
use App\Services\Import\HistoricImportOperationalCloseoutEvidence;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Services\Import\ImportIngressGate;
use App\Services\Song\HistoricSongUsageCloseout;
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
            "app/private/historic-import/{$operation->operation_id}/closeout/operational-readiness-v2.json.enc",
        );

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $path,
        ])->assertSuccessful();

        $this->assertDatabaseHas('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'operational-closeout-readiness-v2',
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
            'artifact_key' => 'operational-closeout-readiness-v2',
        ]);
    }

    /**
     * HIR6. The version 1 block asked the verifier to assert `pending = 0` and
     * `failed = 0`, and the gate behind it treated a queue handoff as
     * completion. So an operation could pass this with an order of service that
     * arrived during the freeze still queued — and the claimed zeros went stale
     * the moment its job failed and returned the row to `pending`.
     */
    #[Test]
    public function a_deferred_email_that_is_only_dispatched_fails_the_operational_closeout(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $this->deferredInboundEmail($operation->operation_id, ImportDeferredInboundEmail::StateDispatched);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence),
        ])
            ->expectsOutputToContain('undrained deferred inbound email (dispatched=1)')
            ->assertFailed();

        $this->assertDatabaseMissing('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'operational-closeout-readiness-v2',
        ]);
    }

    #[Test]
    public function a_processed_deferred_email_satisfies_the_operational_closeout(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $this->deferredInboundEmail($operation->operation_id, ImportDeferredInboundEmail::StateProcessed);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $this->paths[] = storage_path(
            "app/private/historic-import/{$operation->operation_id}/closeout/operational-readiness-v2.json.enc",
        );

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence),
        ])->assertSuccessful();
    }

    /** Counts are compared against the outbox, not taken on the verifier's word. */
    #[Test]
    public function state_counts_that_do_not_match_the_outbox_are_refused(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $this->deferredInboundEmail($operation->operation_id, ImportDeferredInboundEmail::StateProcessed);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $evidence['deferred_inbound']['state_counts'][ImportDeferredInboundEmail::StateProcessed] = 7;

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($this->sign($evidence)),
        ])
            ->expectsOutput('Operational closeout deferred-inbound state counts do not match the outbox.')
            ->assertFailed();
    }

    /** And so is the membership: which emails finished, not how many. */
    #[Test]
    public function a_processed_membership_digest_that_names_other_rows_is_refused(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $this->deferredInboundEmail($operation->operation_id, ImportDeferredInboundEmail::StateProcessed);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $evidence['deferred_inbound']['processed_membership_sha256'] = str_repeat('9', 64);

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($this->sign($evidence)),
        ])
            ->expectsOutput('Operational closeout deferred-inbound evidence does not name the processed rows.')
            ->assertFailed();
    }

    /**
     * F61: the hymn lane used to contribute nothing here, so an operation could close out
     * exactly while its date-only song usage rows were unaccounted for.
     */
    #[Test]
    public function song_usage_rows_outside_the_reported_membership_are_refused(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        SongUsageReport::factory()->quarantined()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($this->sign($evidence)),
        ])
            ->expectsOutput(
                'The historic song usage lane holds rows with no import report artifact, so its membership cannot be reconciled.',
            )
            ->assertFailed();
    }

    /**
     * A version 1 document was signed against a gate that accepted a queue
     * handoff as completion. It is retained, but it cannot satisfy the repaired
     * closeout.
     */
    #[Test]
    public function version_one_evidence_cannot_satisfy_the_repaired_closeout(): void
    {
        $operation = $this->createHistoricImportOperation(app(HistoricImportTargetFingerprint::class)->hash());
        $lock = $this->releasedLock($operation->operation_id);
        $evidence = $this->evidence($operation->operation_id, $operation->target_fingerprint, $lock);
        $evidence['version'] = 1;
        $evidence['deferred_inbound'] = [
            'pending' => 0,
            'failed' => 0,
            'reconciled_at' => now()->toIso8601String(),
        ];

        $this->artisan('historic-import:verify-operational-closeout', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($this->sign($evidence)),
        ])
            ->expectsOutput('Operational closeout evidence is not bound to this operation, target and release.')
            ->assertFailed();
    }

    private function deferredInboundEmail(string $operationId, string $state): ImportDeferredInboundEmail
    {
        return ImportDeferredInboundEmail::query()->create([
            'operation_id' => $operationId,
            'inbound_email_id' => InboundEmail::factory()->create()->id,
            'state' => $state,
            'dispatch_attempts' => 1,
            'deferred_at' => now()->subMinutes(5),
            'dispatched_at' => now()->subMinutes(4),
            'processed_at' => $state === ImportDeferredInboundEmail::StateProcessed ? now()->subMinute() : null,
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
            'version' => HistoricImportOperationalCloseoutEvidence::Version,
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
            /**
             * HIR6: exact per-state counts and a digest over the processed rows
             * themselves, rather than the verifier's word that pending and
             * failed were both zero.
             */
            'deferred_inbound' => [
                'state_counts' => app(ImportIngressGate::class)->deferredInboundStateCounts($operationId),
                'processed_membership_sha256' => CanonicalJson::hash(
                    app(ImportIngressGate::class)->processedDeferredInboundMembership($operationId),
                ),
                'reconciled_at' => now()->toIso8601String(),
            ],
            /** F61: the date-only hymn lane, named rather than counted. */
            'song_usage' => [
                'state_counts' => app(HistoricSongUsageCloseout::class)->stateCounts($operationId),
                'membership_sha256' => app(HistoricSongUsageCloseout::class)->membershipDigest($operationId),
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
