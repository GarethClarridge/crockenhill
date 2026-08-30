<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportRuntimePreflight;
use App\Services\Import\HistoricImportTargetFingerprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrepareHistoricImportOperationCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_creates_one_target_bound_operation_and_hash_chained_preparation_event(): void
    {
        $target = app(HistoricImportTargetFingerprint::class)->hash();
        $runtimeFingerprint = str_repeat('d', 64);
        $runtime = $this->createMock(HistoricImportRuntimePreflight::class);
        $runtime->method('fingerprint')->willReturn($runtimeFingerprint);
        $this->app->instance(HistoricImportRuntimePreflight::class, $runtime);
        $runtimePath = tempnam(sys_get_temp_dir(), 'historic-runtime-evidence-');
        self::assertIsString($runtimePath);
        file_put_contents($runtimePath, '{}');
        $arguments = [
            'batch' => 'approved-archive-batch',
            'plan-hash' => str_repeat('a', 64),
            '--manifest' => ['video='.str_repeat('b', 64), 'oos='.str_repeat('c', 64)],
            '--runtime-evidence' => $runtimePath,
            '--deadline' => now()->addDay()->startOfSecond()->toIso8601String(),
        ];

        try {
            $this->artisan('historic-import:prepare-operation', $arguments)
                ->expectsOutputToContain("Target fingerprint: {$target}")
                ->expectsOutputToContain("Runtime fingerprint: {$runtimeFingerprint}")
                ->assertSuccessful();
            $this->artisan('historic-import:prepare-operation', $arguments)->assertSuccessful();
        } finally {
            unlink($runtimePath);
        }

        $this->assertDatabaseCount('historic_import_operations', 1);
        $this->assertDatabaseHas('historic_import_operations', [
            'target_fingerprint' => $target,
            'runtime_fingerprint' => $runtimeFingerprint,
        ]);
        $this->assertDatabaseCount('historic_import_journal_entries', 1);
    }

    /**
     * A rehearsal target may prepare an operation without runtime evidence.
     *
     * The 15-key attestation belongs to the one-shot GO. Under incremental convergence a bounded
     * staging pass is re-run repeatedly and released from nowhere, so asserting production
     * properties about it would be manufacturing evidence. The identity already models the absent
     * case by falling back to the target fingerprint, which preserves the only property anything
     * downstream actually reads: that the runtime did not change mid-operation.
     */
    #[Test]
    public function a_rehearsal_target_may_prepare_an_operation_without_runtime_evidence(): void
    {
        $target = app(HistoricImportTargetFingerprint::class)->hash();

        $this->artisan('historic-import:prepare-operation', [
            'batch' => 'rehearsal-calibration-batch',
            'plan-hash' => str_repeat('e', 64),
            '--manifest' => ['historic_video='.str_repeat('f', 64)],
            '--deadline' => now()->addDay()->startOfSecond()->toIso8601String(),
        ])
            ->expectsOutputToContain("Runtime fingerprint: {$target}")
            ->expectsOutputToContain('no runtime evidence offered')
            ->assertSuccessful();

        $this->assertDatabaseHas('historic_import_operations', [
            'target_fingerprint' => $target,
            'runtime_fingerprint' => $target,
        ]);
    }

    /**
     * The narrowing stops at the rehearsal boundary. `guardsCurrentEnvironment()` fails closed —
     * an unreadable or misconfigured anchor also counts as production — so an ambiguous target
     * must present evidence rather than inherit the rehearsal exemption.
     */
    #[Test]
    public function a_production_target_may_not_omit_runtime_evidence(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('historic-import:prepare-operation', [
            'batch' => 'production-batch',
            'plan-hash' => str_repeat('e', 64),
            '--manifest' => ['historic_video='.str_repeat('f', 64)],
            '--deadline' => now()->addDay()->startOfSecond()->toIso8601String(),
        ])
            ->expectsOutputToContain('requires --runtime-evidence')
            ->assertExitCode(1);

        $this->assertDatabaseCount('historic_import_operations', 0);
    }

    /**
     * Omission is deliberate; a typo is not. A named path that does not resolve must fail rather
     * than silently degrade into the rehearsal fallback.
     */
    #[Test]
    public function a_named_but_unreadable_runtime_evidence_path_is_still_refused(): void
    {
        $this->artisan('historic-import:prepare-operation', [
            'batch' => 'typo-batch',
            'plan-hash' => str_repeat('e', 64),
            '--manifest' => ['historic_video='.str_repeat('f', 64)],
            '--runtime-evidence' => '/nonexistent/runtime-evidence.json',
            '--deadline' => now()->addDay()->startOfSecond()->toIso8601String(),
        ])
            ->expectsOutputToContain('not a readable file')
            ->assertExitCode(1);

        $this->assertDatabaseCount('historic_import_operations', 0);
    }

    /**
     * HIR1's read-only anchor diagnostic, on the surface that already reports
     * the operation's fingerprints rather than as a command of its own.
     *
     * It must print hashes and a verdict and nothing else: the underlying
     * server identity, schema name, bucket, endpoint and local root are what an
     * operator would otherwise paste into a ticket.
     */
    #[Test]
    public function the_anchors_diagnostic_reports_hashes_and_a_verdict_without_touching_anything(): void
    {
        $resources = app(HistoricImportResourceIdentity::class);

        $this->artisan('historic-import:prepare-operation', ['--anchors' => true])
            ->expectsOutputToContain("Observed database anchor: {$resources->databaseAnchor()}")
            ->expectsOutputToContain("Observed storage anchor:  {$resources->storageAnchor()}")
            ->expectsOutputToContain('Verdict: rehearsal')
            ->expectsOutputToContain('Storage anchor: does not match production')
            ->assertSuccessful();

        $this->assertDatabaseCount('historic_import_operations', 0);
        $this->assertDatabaseCount('historic_import_journal_entries', 0);
    }

    #[Test]
    public function the_anchors_diagnostic_refuses_when_the_anchor_configuration_cannot_be_trusted(): void
    {
        Config::set('church.historic_corpus.production_target_fingerprint', str_repeat('a', 64));

        $this->artisan('historic-import:prepare-operation', ['--anchors' => true])
            ->expectsOutputToContain('HISTORIC_IMPORT_PRODUCTION_TARGET_FINGERPRINT is still set')
            ->assertFailed();
    }

    /**
     * Making the arguments optional is what lets `--anchors` share this surface.
     * Omitting them without it must still be a clear refusal rather than a
     * half-prepared operation.
     */
    #[Test]
    public function preparing_without_the_batch_and_plan_hash_arguments_is_refused(): void
    {
        $this->artisan('historic-import:prepare-operation')
            ->expectsOutputToContain('requires the batch key and plan hash arguments')
            ->assertFailed();

        $this->assertDatabaseCount('historic_import_operations', 0);
    }
}
