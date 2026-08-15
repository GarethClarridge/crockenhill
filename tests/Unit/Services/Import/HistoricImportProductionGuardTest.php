<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Exceptions\HistoricImportFrozen;
use App\Models\Sermon;
use App\Services\Import\HistoricImportApprovalManifest;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Support\CanonicalJson;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
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

    /**
     * IC2: the approval binds to one round's exact corpus and reviewed plan.
     * A caller that supplies the round's hashes must get the same refusal an
     * unapproved run would, when they do not match what was signed.
     */
    #[Test]
    public function a_round_approval_refuses_a_manifest_or_plan_hash_it_was_not_signed_for(): void
    {
        $path = $this->approval('oos:import-archive --import', round: [
            'label' => 'round-2026-08-09',
            'manifest_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'backup_receipt' => 'spatie-backup-2026-08-09.zip',
        ]);
        Config::set('church.historic_corpus.production_import_approval', $path);
        $guard = $this->guard('production');

        $this->assertStringContainsString(
            'does not match this manifest',
            (string) $guard->refusalFor('oos:import-archive --import', roundCorpusHash: str_repeat('c', 64), planHash: str_repeat('b', 64)),
        );
        $this->assertStringContainsString(
            'does not match this plan',
            (string) $guard->refusalFor('oos:import-archive --import', roundCorpusHash: str_repeat('a', 64), planHash: str_repeat('c', 64)),
        );
        $this->assertNull($guard->refusalFor(
            'oos:import-archive --import',
            roundCorpusHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
        ));
    }

    /**
     * IC2 changed the approval's fields, so a version-1 artifact signed under the one-shot
     * schema has to be told its schema moved. The artifact is hand-written and there is no
     * generator to regenerate it from — reporting the change as "missing or unknown fields"
     * would send the operator hunting for a typo that does not exist.
     */
    #[Test]
    public function a_pre_ic2_approval_is_refused_by_version_rather_than_by_field_name(): void
    {
        $path = $this->approval('oos:import-archive --import');
        /** @var array<string, mixed> $approval */
        $approval = json_decode((string) file_get_contents($path), true);
        $approval['version'] = 1;
        unset($approval['round']);
        $approval['freeze'] = ['deploy' => true, 'started_at' => now()->toIso8601String()];
        file_put_contents($path, json_encode($approval));

        Config::set('church.historic_corpus.production_import_approval', $path);
        $refusal = (string) $this->guard('production')->refusalFor('oos:import-archive --import');

        $this->assertStringContainsString('format is unsupported', $refusal);
        $this->assertStringContainsString('version 2', $refusal);
        $this->assertStringNotContainsString('missing or unknown fields', $refusal);
    }

    /**
     * D10: Crockenhill has one maintainer, so the four operational roles are
     * accountability fields rather than four people. This test exists to fail if
     * anyone reinstates a distinctness check — an approval the only maintainer
     * cannot sign would not be a control, it would be an unreachable gate.
     */
    #[Test]
    public function one_maintainer_may_hold_every_operational_role(): void
    {
        $path = $this->approval('oos:import-archive --import', [
            'incident_commander' => 'gareth',
            'operator' => 'gareth',
            'independent_verifier' => 'gareth',
            'monitoring_owner' => 'gareth',
        ]);
        Config::set('church.historic_corpus.production_import_approval', $path);

        $this->assertNull($this->guard('production')->refusalFor('oos:import-archive --import'));
    }

    /**
     * The roles stay *required* even though they may name one person: the
     * artifact is the durable record of who to call and who owns rollback.
     */
    #[Test]
    public function an_unnamed_operational_role_is_still_refused(): void
    {
        $path = $this->approval('oos:import-archive --import', [
            'incident_commander' => 'gareth',
            'operator' => 'gareth',
            'independent_verifier' => ' ',
            'monitoring_owner' => 'gareth',
        ]);
        Config::set('church.historic_corpus.production_import_approval', $path);

        $this->assertRefusesWithoutWriting(
            $this->guard('production'),
            'oos:import-archive --import',
        );
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
    public function a_local_app_pointed_at_the_production_database_is_still_guarded(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->anchorProductionDatabase();

        $this->assertNotNull($this->guard('local')->refusalFor('historic:apply'));
    }

    /**
     * HIR0 red test for review finding 4 (High), owned by package **HIR1**.
     *
     * `guardsCurrentEnvironment()` protects a production target outside an
     * `APP_ENV=production` process only when the *entire* current target
     * fingerprint equals one configured hash. That fingerprint mixes stable
     * resource identity (database, storage) with volatile per-operation
     * configuration: release identifier, migration batch/count, service
     * structure mode, transcription service and public cutoff.
     *
     * So a local shell still pointed at the production database silently loses
     * the protection as soon as any of those drifts — a newer release, one
     * extra migration, a different transcription setting. The fallback fails
     * open in precisely the misconfiguration it exists to catch, and the
     * existing coverage only ever asserts a byte-for-byte full match.
     *
     * HIR1 splits stable resource anchors from the volatile operation
     * fingerprint; matching a production anchor must fail closed regardless of
     * drift. Per HIR-D2 only the database anchor triggers production controls,
     * which is what this test drifts around.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 4
     * @see docs/archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §7 (HIR1), §4.2 (HIR-D2)
     */
    #[Test]
    #[Group('hir-red')]
    public function volatile_drift_does_not_disarm_the_guard_on_a_production_database(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->anchorProductionDatabase();
        $before = app(HistoricImportTargetFingerprint::class)->hash();

        // Same database, same storage: only the release the shell is running
        // and an unrelated pipeline setting have moved on.
        Config::set('app.release_identifier', 'release-that-drifted-'.uniqid());
        Config::set('media-processing.transcription.service', 'some-other-transcriber');

        $guard = $this->guard('local');

        // The drift is real - the operation binding must notice it - and the
        // guard must be indifferent to it. Both halves are the finding.
        $this->assertNotSame($before, app(HistoricImportTargetFingerprint::class)->hash());
        $this->assertTrue(
            $guard->guardsCurrentEnvironment(),
            'A process pointed at the production database must stay guarded when volatile configuration drifts.',
        );
        $this->assertRefusesWithoutWriting($guard, 'historic:apply');
    }

    /**
     * Migration drift is the same failure with a different cause, and the more
     * likely one: a shell on a branch with one extra migration.
     */
    #[Test]
    public function a_schema_change_does_not_disarm_the_guard_on_a_production_database(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->anchorProductionDatabase();

        DB::table('migrations')->insert([
            'migration' => '2026_08_13_000000_hir1_drift_probe',
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);

        $guard = $this->guard('local');

        $this->assertTrue($guard->guardsCurrentEnvironment());
        $this->assertRefusesWithoutWriting($guard, 'historic:apply');
    }

    /**
     * HIR-D2's accepted residual risk, pinned so it cannot be "fixed" by
     * accident.
     *
     * `.env` sets `SERMON_STORAGE_DISK=do_spaces` with
     * `DO_SPACES_BUCKET=crockenhill`, so a developer machine's public sermon
     * disk *is* the production bucket. OR-ing the storage anchor into the guard
     * would classify every local shell as production and refuse the §13.5
     * rehearsal, so the decision was taken — against the recommendation — that
     * only the database anchor arms it.
     *
     * The consequence is deliberate and unpleasant: a local historic *release*
     * still writes to the production public store and this guard will not stop
     * it. What the guard owes is visibility, which is why the match is reported
     * rather than silently dropped. HIR7 §4.2.1 carries the actual refusal.
     */
    #[Test]
    public function a_matching_storage_anchor_alone_does_not_arm_the_guard(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        Config::set('church.historic_corpus.production_database_anchor', str_repeat('a', 64));
        Config::set(
            'church.historic_corpus.production_storage_anchor',
            app(HistoricImportResourceIdentity::class)->storageAnchor(),
        );

        $guard = $this->guard('local');

        $this->assertFalse($guard->guardsCurrentEnvironment());
        $this->assertNull($guard->refusalFor('historic:apply'));
        $this->assertTrue(
            $guard->matchesProductionStorageAnchor(),
            'The guard must still report that this rehearsal target resolves the production store.',
        );
    }

    #[Test]
    public function a_matching_database_anchor_arms_the_guard_even_when_storage_differs(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        $this->anchorProductionDatabase();
        Config::set('church.historic_corpus.production_storage_anchor', str_repeat('b', 64));

        $guard = $this->guard('local');

        $this->assertTrue($guard->guardsCurrentEnvironment());
        $this->assertFalse($guard->matchesProductionStorageAnchor());
        $this->assertRefusesWithoutWriting($guard, 'historic:apply');
    }

    /**
     * A rehearsal target is fully configured and matches neither anchor. It has
     * to stay usable: §13.5 steps 3-4 are where the corpus is staged, projected
     * and re-projected, and gating that would gate G5.
     */
    #[Test]
    public function a_fully_configured_rehearsal_target_matches_neither_anchor_and_stays_usable(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        Config::set('church.historic_corpus.production_database_anchor', str_repeat('c', 64));
        Config::set('church.historic_corpus.production_storage_anchor', str_repeat('d', 64));

        $guard = $this->guard('local');

        $this->assertFalse($guard->guardsCurrentEnvironment());
        $this->assertNull($guard->anchorConfigurationError());
        $this->assertNull($guard->refusalFor('oos:import-archive --import'));
        $this->assertFalse($guard->matchesProductionStorageAnchor());
    }

    #[Test]
    public function a_malformed_anchor_fails_closed_rather_than_being_ignored(): void
    {
        foreach (['database', 'storage'] as $role) {
            Config::set('church.historic_corpus.production_database_anchor', null);
            Config::set('church.historic_corpus.production_storage_anchor', null);
            Config::set("church.historic_corpus.production_{$role}_anchor", 'not-a-digest');

            $guard = $this->guard('local');

            $this->assertTrue($guard->guardsCurrentEnvironment(), "A malformed {$role} anchor must fail closed.");
            $this->assertStringContainsString(
                "production {$role} anchor is not a SHA-256 digest",
                (string) $guard->refusalFor('historic:apply'),
            );
        }
    }

    /**
     * The two anchors hash differently shaped objects, so an equal pair is not a
     * coincidence — it is one value pasted into both variables, which would
     * silently arm or disarm the guard depending on which one was correct.
     */
    #[Test]
    public function anchors_recorded_twice_fail_closed(): void
    {
        Config::set('church.historic_corpus.production_database_anchor', str_repeat('e', 64));
        Config::set('church.historic_corpus.production_storage_anchor', str_repeat('e', 64));

        $guard = $this->guard('local');

        $this->assertTrue($guard->guardsCurrentEnvironment());
        $this->assertStringContainsString('recorded twice', (string) $guard->refusalFor('historic:apply'));
    }

    /**
     * The superseded key must never again be able to declare a target safe.
     * Reading it only to refuse is what makes a stale deploy loud instead of
     * silently unprotected.
     */
    #[Test]
    public function the_superseded_target_fingerprint_key_is_read_only_to_refuse(): void
    {
        Config::set(
            'church.historic_corpus.production_target_fingerprint',
            app(HistoricImportTargetFingerprint::class)->hash(),
        );

        $guard = $this->guard('local');
        $refusal = (string) $guard->refusalFor('historic:apply');

        $this->assertTrue($guard->guardsCurrentEnvironment());
        $this->assertStringContainsString('HISTORIC_IMPORT_PRODUCTION_TARGET_FINGERPRINT is still set', $refusal);
        $this->assertStringContainsString('HISTORIC_IMPORT_PRODUCTION_DATABASE_ANCHOR', $refusal);
    }

    /**
     * An anchor is configured and the host cannot say what database it is
     * looking at. Not knowing is not the same as knowing it is safe.
     */
    #[Test]
    public function an_unobservable_database_identity_fails_closed(): void
    {
        Config::set('church.historic_corpus.production_import_approval', null);
        Config::set('church.historic_corpus.production_database_anchor', str_repeat('f', 64));

        $this->app['env'] = 'local';
        $guard = new HistoricImportProductionGuard($this->app, $this->unobservableIdentity());

        $this->assertTrue($guard->guardsCurrentEnvironment());
        $this->assertRefusesWithoutWriting($guard, 'historic:apply');
    }

    /**
     * The storage half of the same rule. An unobservable storage anchor reports
     * `null`, never `false`: the diagnostic and HIR7's compensating refusal
     * must be able to tell "does not match production" from "cannot tell".
     */
    #[Test]
    public function an_unobservable_storage_anchor_is_reported_as_unknown_not_as_safe(): void
    {
        Config::set('church.historic_corpus.production_storage_anchor', str_repeat('f', 64));
        Config::set('media-processing.storage.sermon_disk', null);

        $this->assertNull($this->guard('local')->matchesProductionStorageAnchor());
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

        return new HistoricImportProductionGuard($this->app, app(HistoricImportResourceIdentity::class));
    }

    /**
     * Record this host's database anchor as the production one, which is what a
     * shell wrongly pointed at production looks like.
     */
    private function anchorProductionDatabase(): void
    {
        Config::set(
            'church.historic_corpus.production_database_anchor',
            app(HistoricImportResourceIdentity::class)->databaseAnchor(),
        );
    }

    /**
     * A host whose database driver exposes no stable server identity.
     *
     * Mocked at the connection rather than by repointing `database.default`,
     * because swapping the default connection mid-test would strand the
     * transaction this case runs inside.
     */
    private function unobservableIdentity(): HistoricImportResourceIdentity
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('a-driver-with-no-server-identity');
        $connection->shouldReceive('getDatabaseName')->andReturn('unknown');

        $connections = Mockery::mock(DatabaseManager::class);
        $connections->shouldReceive('connection')->andReturn($connection);

        return new HistoricImportResourceIdentity($connections);
    }

    /**
     * A refusal is only a refusal if nothing happened. The failure this package
     * repairs was silent permission, so asserting the message alone would not
     * distinguish "refused" from "refused after writing".
     */
    private function assertRefusesWithoutWriting(HistoricImportProductionGuard $guard, string $operation): void
    {
        $writes = [];

        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        Storage::fake('public');
        Storage::fake('historic_quarantine');

        $this->assertNotNull($guard->refusalFor($operation));
        $this->assertSame([], $writes, 'A guarded refusal must not write to the database.');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('historic_quarantine')->allFiles());
    }

    /**
     * @param  array<string, string>|null  $roles
     * @param  array<string, string>|null  $round
     */
    private function approval(string $command, ?array $roles = null, ?array $round = null): string
    {
        $target = app(HistoricImportTargetFingerprint::class)->hash();
        $operation = $this->createHistoricImportOperation($target);
        $approval = [
            'format' => HistoricImportApprovalManifest::Format,
            'version' => HistoricImportApprovalManifest::Version,
            'approval_id' => 'approval-2026-08-09',
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'target_fingerprint' => $target,
            'release_identifier' => config('app.release_identifier'),
            'expires_at' => now()->addHour()->toIso8601String(),
            'permitted_commands' => [$command],
            'round' => $round ?? [
                'label' => 'round-2026-08-09',
                'manifest_hash' => str_repeat('1', 64),
                'plan_hash' => str_repeat('2', 64),
                'backup_receipt' => 'spatie-backup-2026-08-09.zip',
            ],
            'roles' => $roles ?? [
                'incident_commander' => 'person-one',
                'operator' => 'person-two',
                'independent_verifier' => 'person-three',
                'monitoring_owner' => 'person-four',
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
