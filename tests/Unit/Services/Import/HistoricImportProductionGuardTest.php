<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Exceptions\HistoricImportFrozen;
use App\Models\ImportIngressLock;
use App\Models\Sermon;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricImportProductionGuardTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    /** @var list<string> */
    private array $approvalPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('church.services.public_from', '2026-01-01');
        Config::set('media-processing.historic_import.evidence_signing_key', 'approval-test-key');
    }

    protected function tearDown(): void
    {
        foreach ($this->approvalPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_is_silent_outside_production(): void
    {
        // The whole point of scoping the G8 prohibition: a rehearsal database is
        // where §13.5 steps 3-4 stage and re-project the corpus, repeatedly.
        Config::set('church.historic_corpus.production_import_approval', null);

        $this->assertNull($this->guard('local')->refusalFor('oos:import-archive --import'));
    }

    #[Test]
    public function it_refuses_in_production_when_no_approval_is_recorded(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);

        $refusal = $this->guard('production')->refusalFor('oos:import-archive --import');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('oos:import-archive --import', $refusal);
        $this->assertStringContainsString('HISTORIC_IMPORT_PRODUCTION_APPROVAL', $refusal);
    }

    #[Test]
    public function it_permits_a_production_run_the_approval_names(): void
    {
        $path = $this->approval('oos:import-archive --import');
        Config::set('church.historic_corpus.production_import_approval', $path);

        $guard = $this->guard('production');

        $this->assertNull($guard->refusalFor('oos:import-archive --import'));
        $this->assertStringStartsWith('historic-', (string) $guard->approvedOperationId());
    }

    #[Test]
    public function it_refuses_production_when_the_public_service_cutoff_is_blank(): void
    {
        Config::set('church.historic_corpus.production_import_approval', 'g8-2026-08-20');
        Config::set('church.services.public_from', ' ');

        $refusal = $this->guard('production')->refusalFor('historic:apply');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('CHURCH_SERVICES_PUBLIC_FROM', $refusal);
    }

    #[Test]
    public function it_refuses_production_when_quarantine_resolves_to_the_public_sermon_disk(): void
    {
        Config::set('church.historic_corpus.production_import_approval', 'g8-2026-08-20');
        Config::set('media-processing.storage.historic_quarantine_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $refusal = $this->guard('production')->refusalFor('historic:apply');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('HISTORIC_QUARANTINE_DISK', $refusal);
    }

    /**
     * A fail-closed default that a stray space defeats is not fail-closed. The
     * realistic way this happens is an env line left as `=` or `= ` while a
     * window is being set up.
     */
    #[Test]
    public function it_does_not_read_a_blank_approval_as_permission(): void
    {
        Config::set('church.historic_corpus.production_import_approval', '   ');

        $guard = $this->guard('production');

        $this->assertNull($guard->approvedOperationId());
        $this->assertNotNull($guard->refusalFor('oos:import-archive --import'));
    }

    #[Test]
    public function it_trims_a_recorded_approval(): void
    {
        $path = $this->approval('historic:apply');
        Config::set('church.historic_corpus.production_import_approval', "  {$path}\n");

        $this->assertStringStartsWith('historic-', (string) $this->guard('production')->approvedOperationId());
    }

    #[Test]
    public function a_local_app_pointed_at_the_production_target_is_still_guarded(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        Config::set(
            'church.historic_corpus.production_target_fingerprint',
            app(HistoricImportTargetFingerprint::class)->hash(),
        );

        $this->assertNotNull($this->guard('local')->refusalFor('historic:apply'));
    }

    #[Test]
    public function approval_for_one_command_cannot_authorize_another_phase(): void
    {
        Config::set('church.historic_corpus.production_import_approval', $this->approval('historic:preflight'));

        $refusal = $this->guard('production')->refusalFor('historic:apply');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('does not permit command/phase', $refusal);
    }

    #[Test]
    public function the_active_freeze_blocks_ordinary_targeted_mutation_but_allows_the_authorized_command(): void
    {
        Config::set('church.historic_corpus.production_import_approval', $this->approval('historic:apply'));

        try {
            Sermon::factory()->create();
            $this->fail('The production freeze did not block an ordinary target mutation.');
        } catch (HistoricImportFrozen $exception) {
            $this->assertStringContainsString('paused for the historic import window', $exception->getMessage());
        }

        $this->assertNull($this->guard('production')->refusalFor('historic:apply'));
        Sermon::factory()->create();
        $this->assertDatabaseCount('sermons', 1);
    }

    private function guard(string $environment): HistoricImportProductionGuard
    {
        // `isProduction()` reads the container's `env` binding rather than
        // `config('app.env')`, so setting the config alone would leave the guard
        // looking at the test environment and every assertion below vacuous.
        $this->app['env'] = $environment;

        return new HistoricImportProductionGuard($this->app);
    }

    private function approval(string $command): string
    {
        $target = app(HistoricImportTargetFingerprint::class)->hash();
        $operation = $this->createHistoricImportOperation($target);
        ImportIngressLock::factory()->create([
            'operation_id' => $operation->operation_id,
            'queue_pause_accounting' => ['supervisors_to_pause' => ['historic' => ['historic-ffmpeg']]],
            'released_at' => null,
            'is_active' => 1,
        ]);
        $approval = [
            'format' => 'crockenhill-historic-import-approval',
            'version' => 1,
            'approval_id' => 'approval-2026-08-09',
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'target_fingerprint' => $target,
            'release_identifier' => config('app.release_identifier'),
            'expires_at' => now()->addHour()->toIso8601String(),
            'permitted_commands' => [$command],
            'freeze' => [
                'deploy' => true,
                'rollback' => true,
                'configuration' => true,
                'manifests' => true,
                'targeted_mutations' => true,
                'started_at' => now()->toIso8601String(),
            ],
            'roles' => [
                'incident_commander' => 'person-one',
                'operator' => 'person-two',
                'independent_verifier' => 'person-three',
                'monitoring_owner' => 'person-four',
            ],
            'abort_thresholds' => [
                'failed_services' => 1,
                'max_job_age_seconds' => 900,
                'max_db_connections' => 100,
                'min_free_bytes' => 10_000_000,
                'max_http_429' => 1,
                'max_http_5xx' => 1,
                'max_cost_minor_units' => 10_000,
            ],
            'monitoring' => [
                'provider' => 'retained-live-monitor',
                'external_watchboard' => 'watchboard-2026-08-09',
                'retained' => true,
            ],
            'signature' => ['algorithm' => 'hmac-sha256', 'key_id' => 'test-key', 'digest' => ''],
        ];
        $approval['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($approval, ['signature' => true])),
            'approval-test-key',
        );
        $path = sys_get_temp_dir().'/historic-approval-'.uniqid().'.json';
        file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR));
        $this->approvalPaths[] = $path;

        return $path;
    }
}
