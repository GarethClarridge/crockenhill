<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAsset;
use App\Models\HistoricImportReleaseAttempt;
use App\Services\Import\HistoricImportRecoveryEvidence;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportRowManifest;
use App\Services\Import\HistoricSermonPublicationService;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\FakeHistoricSourceFilesystemInspector;
use Tests\TestCase;

/**
 * HIR5. Recovery evidence is signed, and every digest it names is reproduced
 * from an artifact the gate opens.
 *
 * The fixtures here are deliberately heavy, because the finding was that a light
 * one passed: an unsigned document of repeated placeholder digits, with one
 * backup standing in for two independent copies, satisfied the mandatory
 * recovery gate. Building an acceptable v2 document now means producing twenty-two
 * real artifacts in two failure domains, two comparable row manifests, and a
 * release ledger that actually records a survived destination race.
 *
 * What these tests do **not** assert is verifier independence. HIR-D3 reuses the
 * approval signing key, so the application holds the secret that produces a
 * valid signature; the signature closes forgery by an arbitrary party and
 * nothing more. The assurance being tested is the artifact-backed half.
 *
 * @see docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §11 (HIR5), §4.3 (HIR-D3)
 */
class VerifyHistoricImportRecoveryCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private const SigningKey = 'historic-recovery-signing-key';

    private const KeyId = 'historic-import-recovery-v2';

    private const Release = 'release-2026-08-13';

    private string $root;

    /** @var array<string, mixed> */
    private array $evidence = [];

    /** @var array<string, string> */
    private array $mappings = [];

    private FakeHistoricSourceFilesystemInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('media-processing.historic_import.evidence_signing_key', self::SigningKey);
        config()->set('media-processing.historic_import.recovery_evidence_key_id', self::KeyId);
        config()->set('app.release_identifier', self::Release);

        $this->root = (string) realpath(sys_get_temp_dir()).'/hir5-'.Str::uuid();
        mkdir($this->root.'/alpha', 0755, true);
        mkdir($this->root.'/beta', 0755, true);

        /**
         * One container cannot mount two failure domains, which is the single
         * fact this fake supplies. Everything else — the bytes, the digests, the
         * ledger rows — is real.
         */
        $this->inspector = (new FakeHistoricSourceFilesystemInspector)
            ->root($this->root.'/alpha', 'alpha')
            ->root($this->root.'/beta', 'beta');
        $this->app->instance(HistoricSourceFilesystemInspector::class, $this->inspector);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_retains_signed_artifact_backed_recovery_evidence(): void
    {
        $operation = $this->prepare();

        $this->verify($operation)
            ->expectsOutputToContain('Verified artifacts: 22')
            ->assertSuccessful();

        $this->assertDatabaseHas('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'recovery-rehearsal-v2',
            'kind' => 'backup',
        ]);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'recovery_rehearsal_verified',
        ]);
    }

    /**
     * The document HIR0's red test showed being accepted: version 1, unsigned,
     * every digest a repeated placeholder digit, and the same backup presented
     * as both the on-host and the off-host restore.
     *
     * Rebuilt rather than deleted — it is the superseded evidence that shows
     * what the repaired gate now refuses.
     */
    #[Test]
    public function it_refuses_the_unsigned_version_one_document_whose_artifacts_were_never_opened(): void
    {
        $operation = $this->createHistoricImportOperation();
        $legacy = $this->legacyEvidence($operation);

        $this->assertArrayNotHasKey('signature', $legacy, 'The finding is that nothing authenticated this document.');
        $this->assertSame(
            $legacy['database_backups']['on_host'],
            $legacy['database_backups']['off_host'],
            'The finding is that one backup could stand in for two independent ones.',
        );

        $this->artisan('historic-import:verify-recovery', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($legacy),
        ])->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_evidence_signed_under_an_unrecognised_key_id(): void
    {
        $operation = $this->prepare();
        $evidence = $this->sign($this->evidence, keyId: 'some-other-key');

        $this->verify($operation, $evidence)
            ->expectsOutput('Recovery evidence is signed under an unrecognised key id.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_evidence_signed_with_the_wrong_key(): void
    {
        $operation = $this->prepare();
        $evidence = $this->sign($this->evidence, signingKey: 'not-the-configured-key');

        $this->verify($operation, $evidence)
            ->expectsOutput('Recovery evidence signature does not authenticate this document.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_body_tampered_with_after_signing(): void
    {
        $operation = $this->prepare();
        $evidence = $this->sign($this->evidence);
        $evidence['accepted_rto_seconds'] = 86_400;

        $this->verify($operation, $evidence)
            ->expectsOutput('Recovery evidence signature does not authenticate this document.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    /**
     * A validly signed document, replayed against a different operation. The
     * signature still verifies — it is the same key — so the binding is what has
     * to refuse it.
     */
    #[Test]
    public function it_refuses_evidence_replayed_against_another_operation(): void
    {
        $operation = $this->prepare();
        $other = $this->createHistoricImportOperation(targetFingerprint: str_repeat('d', 64));

        $this->verify($other, $this->sign($this->evidence))
            ->expectsOutput('Recovery evidence is not bound to this exact import operation and target.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($other);
    }

    #[Test]
    public function it_refuses_evidence_produced_against_another_release(): void
    {
        $operation = $this->prepare();
        $this->evidence['release_identifier'] = 'release-2026-01-01';

        $this->verify($operation)
            ->expectsOutput('Recovery evidence was produced against a different deployed release.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_declared_artifact_that_was_not_supplied(): void
    {
        $operation = $this->prepare();
        unset($this->mappings['exercise-full-restore']);

        $this->verify($operation)
            ->expectsOutputToContain('declares artifacts that were not supplied')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_an_artifact_the_evidence_does_not_declare(): void
    {
        $operation = $this->prepare();
        $this->mappings['unexpected-extra'] = $this->file('alpha', 'unexpected-extra', 'nobody declared this');

        $this->verify($operation)
            ->expectsOutputToContain('artifacts the evidence does not declare')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_an_artifact_whose_bytes_do_not_match_the_declared_digest(): void
    {
        $operation = $this->prepare();
        file_put_contents($this->mappings['backup-on-host'], 'these are not the bytes that were signed for');

        $this->verify($operation)
            ->expectsOutputToContain('backup-on-host')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    /**
     * The artifact changes between the resolver's two reads of it, which is the
     * window a mount observation sits inside.
     */
    #[Test]
    public function it_refuses_an_artifact_whose_bytes_change_while_it_is_being_verified(): void
    {
        $operation = $this->prepare();
        $this->inspector->mutateDuringFirstObservation(function (): void {
            file_put_contents($this->mappings['backup-on-host'], 'replaced part-way through verification');
        });

        $this->verify($operation)
            ->expectsOutputToContain('changed while it was being verified')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_two_artifact_ids_resolving_to_one_file(): void
    {
        $operation = $this->prepare();
        $this->mappings['backup-off-host'] = $this->mappings['backup-on-host'];

        $this->verify($operation)
            ->expectsOutputToContain('are the same file')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_symlinked_artifact_path(): void
    {
        $operation = $this->prepare();
        $link = $this->root.'/alpha/backup-off-host.link';
        symlink($this->mappings['backup-off-host'], $link);
        $this->mappings['backup-off-host'] = $link;

        $this->verify($operation)
            ->expectsOutputToContain('is a symlink, not a retained artifact')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_backups_that_share_one_failure_domain(): void
    {
        $operation = $this->prepare();
        $this->inspector->root($this->root.'/beta', 'alpha');

        $this->verify($operation)
            ->expectsOutputToContain('share one failure domain')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_preserved_copies_that_share_one_failure_domain(): void
    {
        $operation = $this->prepare();
        $duplicate = $this->file('alpha', 'preserved-journal-2', 'preserved journal bytes');
        $this->mappings['preserved-journal-2'] = $duplicate;

        $this->verify($operation)
            ->expectsOutputToContain('share a failure domain')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_preserved_copies_that_do_not_hold_identical_bytes(): void
    {
        $operation = $this->prepare();
        $divergent = 'preserved journal bytes, but different';
        file_put_contents($this->mappings['preserved-journal-2'], $divergent);
        $this->evidence['preserved_artifacts']['journal']['copies'][1] = [
            'artifact_id' => 'preserved-journal-2',
            'byte_size' => strlen($divergent),
            'sha256' => hash('sha256', $divergent),
        ];

        $this->verify($operation)
            ->expectsOutputToContain('do not hold identical bytes')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_restore_verified_against_the_production_database(): void
    {
        $operation = $this->prepare();
        $this->replaceRowManifest(
            'manifest-on-host',
            'beta',
            app(HistoricImportResourceIdentity::class)->databaseAnchor(),
            $this->membership(),
        );

        $this->verify($operation)
            ->expectsOutputToContain('production database rather than a disposable one')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_two_row_manifests_read_from_one_database(): void
    {
        $operation = $this->prepare();
        $this->replaceRowManifest('manifest-off-host', 'beta', str_repeat('1', 64), $this->membership());

        $this->verify($operation)
            ->expectsOutputToContain('read from the same database')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_restores_whose_row_membership_disagrees(): void
    {
        $operation = $this->prepare();
        $membership = $this->membership();
        $membership['sermons']['row_count'] = 41;
        $this->replaceRowManifest('manifest-off-host', 'beta', str_repeat('b', 64), $membership);

        $this->verify($operation)
            ->expectsOutputToContain('disagree about table sermons')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_row_manifest_edited_away_from_its_own_membership_digest(): void
    {
        $operation = $this->prepare();
        $manifest = json_decode((string) file_get_contents($this->mappings['manifest-off-host']), true);
        $manifest['tables']['sermons']['row_count'] = 999;
        $this->rewriteArtifact('manifest-off-host', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->verify($operation)
            ->expectsOutputToContain('does not match its own membership digest')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_restore_that_overran_the_accepted_recovery_time(): void
    {
        $operation = $this->prepare();
        $this->evidence['database_backups']['off_host']['observed_rto_seconds'] = 4_000;

        $this->verify($operation)
            ->expectsOutput('The off_host restore exceeded the accepted recovery time objective.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_restore_that_overran_the_accepted_recovery_point(): void
    {
        $operation = $this->prepare();
        $this->evidence['database_backups']['on_host']['observed_rpo_seconds'] = 900;

        $this->verify($operation)
            ->expectsOutput('The on_host restore exceeded the accepted recovery point objective.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_a_rollback_exercise_that_did_not_pass(): void
    {
        $operation = $this->prepare();
        $this->evidence['exercises']['asset_compensation']['passed'] = false;

        $this->verify($operation)
            ->expectsOutput('Recovery exercise asset_compensation did not pass.')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    /**
     * Evidence that a destination race is survivable proves nothing if it was
     * gathered against the implementation whose compensation deleted by path.
     */
    #[Test]
    public function it_refuses_object_recovery_gathered_against_another_release_implementation(): void
    {
        $operation = $this->prepare();
        $this->evidence['object_recovery']['release_implementation'] = 'pre-hir7-copy-verified-v0';

        $this->verify($operation)
            ->expectsOutputToContain('different release implementation')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    /**
     * The rebuilt cleanup-race case. Version 1 asked for
     * `foreign_before_cleanup_preserved => true` and believed it; version 2
     * reads the ledger, so an exercise that never retained an orphan has nothing
     * to show.
     */
    #[Test]
    public function it_refuses_object_recovery_with_no_retained_orphan_in_the_ledger(): void
    {
        $operation = $this->prepare();
        HistoricImportReleaseAsset::query()
            ->where('destination_identity', $this->evidence['object_recovery']['retained_orphan']['destination_identity'])
            ->update([
                'state' => HistoricImportReleaseAsset::StateAbandoned,
                'compensated_at' => null,
            ]);

        $this->verify($operation)
            ->expectsOutputToContain('created, failed on and retained')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_object_recovery_naming_a_destination_the_ledger_does_not_hold(): void
    {
        $operation = $this->prepare();
        $this->evidence['object_recovery']['foreign_object']['destination_identity'] = str_repeat('f', 64);

        $this->verify($operation)
            ->expectsOutputToContain('holds no foreign object at the destination')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    #[Test]
    public function it_refuses_object_recovery_whose_release_attempt_is_still_running(): void
    {
        $operation = $this->prepare();
        HistoricImportReleaseAttempt::query()
            ->where('attempt_id', $this->evidence['object_recovery']['foreign_object']['attempt_id'])
            ->update(['state' => HistoricImportReleaseAttempt::StateClaimed]);

        $this->verify($operation)
            ->expectsOutputToContain('has not finished')
            ->assertFailed();

        $this->assertNoEvidenceRetained($operation);
    }

    private function assertNoEvidenceRetained(HistoricImportOperation $operation): void
    {
        $this->assertDatabaseMissing('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'recovery-rehearsal-v2',
        ]);
        $this->assertDatabaseMissing('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'recovery_rehearsal_verified',
        ]);
    }

    /**
     * A complete, acceptable rehearsal: twenty-two artifacts across two failure
     * domains, two comparable row manifests, and a release ledger holding a
     * survived foreign write and a retained orphan.
     */
    private function prepare(): HistoricImportOperation
    {
        $operation = $this->createHistoricImportOperation();
        $resources = app(HistoricImportResourceIdentity::class);
        [$foreign, $orphan] = $this->releaseLedger($operation);

        $this->evidence = [
            'format' => HistoricImportRecoveryEvidence::Format,
            'version' => HistoricImportRecoveryEvidence::Version,
            'operation_id' => $operation->operation_id,
            'target_fingerprint' => $operation->target_fingerprint,
            'release_identifier' => self::Release,
            'resource_identities' => [
                'database_anchor' => $resources->databaseAnchor(),
                'storage_anchor' => $resources->storageAnchor(),
            ],
            'accepted_rpo_seconds' => 300,
            'accepted_rto_seconds' => 3_600,
            'database_backups' => [
                'on_host' => [
                    'artifact' => $this->artifact('alpha', 'backup-on-host', 'on-host database backup bytes'),
                    'row_manifest' => $this->rowManifest('alpha', 'manifest-on-host', str_repeat('1', 64)),
                    'transaction_snapshot' => 'mysql-bin.000001:1234',
                    'restored_at' => '2026-08-12T12:00:00+00:00',
                    'observed_rpo_seconds' => 0,
                    'observed_rto_seconds' => 900,
                ],
                'off_host' => [
                    'artifact' => $this->artifact('beta', 'backup-off-host', 'off-host database backup bytes'),
                    'row_manifest' => $this->rowManifest('beta', 'manifest-off-host', str_repeat('2', 64)),
                    'transaction_snapshot' => 'mysql-bin.000001:1234',
                    'restored_at' => '2026-08-12T13:00:00+00:00',
                    'observed_rpo_seconds' => 120,
                    'observed_rto_seconds' => 1_800,
                ],
            ],
            'object_recovery' => [
                'release_implementation' => HistoricSermonPublicationService::ReleaseImplementation,
                'exact_version_delete_supported' => false,
                'foreign_object' => [
                    'attempt_id' => $foreign->attempt_id,
                    'destination_identity' => HistoricImportReleaseAsset::identityFor('public', 'historic/foreign.mp3'),
                    'artifact' => $this->artifact('alpha', 'object-foreign', 'foreign writer object evidence'),
                ],
                'retained_orphan' => [
                    'attempt_id' => $orphan->attempt_id,
                    'destination_identity' => HistoricImportReleaseAsset::identityFor('public', 'historic/orphan.mp3'),
                    'artifact' => $this->artifact('alpha', 'object-orphan', 'retained orphan object evidence'),
                ],
                'receipts' => $this->artifact('alpha', 'object-receipts', 'object store receipts'),
            ],
            'preserved_artifacts' => $this->preservedArtifacts(),
            'exercises' => $this->exercises(),
            'verified_by' => 'recovery-verifier@example.test',
            'verified_at' => '2026-08-12T14:00:00+00:00',
        ];

        return $operation;
    }

    /** @return array<string, mixed> */
    private function preservedArtifacts(): array
    {
        $preserved = [];

        foreach (['source', 'bundles', 'results', 'private_staging', 'journal'] as $kind) {
            $bytes = "preserved {$kind} bytes";
            $slug = str_replace('_', '-', $kind);

            $preserved[$kind] = [
                'copies' => [
                    $this->artifact('alpha', "preserved-{$slug}-1", $bytes),
                    $this->artifact('beta', "preserved-{$slug}-2", $bytes),
                ],
            ];
        }

        return $preserved;
    }

    /** @return array<string, mixed> */
    private function exercises(): array
    {
        $exercises = [];
        $names = [
            'mid_service_failure', 'cross_service_failure', 'asset_compensation',
            'full_restore', 'repeat_apply',
        ];

        foreach ($names as $name) {
            $slug = str_replace('_', '-', $name);

            $exercises[$name] = [
                'passed' => true,
                'duration_seconds' => 90,
                'artifact' => $this->artifact('alpha', "exercise-{$slug}", "{$name} exercise transcript"),
            ];
        }

        return $exercises;
    }

    /**
     * The ledger a real object-recovery exercise leaves behind: a foreign
     * writer's object observed and left alone, and an object this programme
     * created, failed on and retained.
     *
     * @return array{0: HistoricImportReleaseAttempt, 1: HistoricImportReleaseAttempt}
     */
    private function releaseLedger(HistoricImportOperation $operation): array
    {
        $foreign = $this->releaseAttempt($operation, HistoricImportReleaseAttempt::StateCompleted, '1');
        $orphan = $this->releaseAttempt($operation, HistoricImportReleaseAttempt::StateOrphaned, '2');

        $foreign->assets()->create([
            'record_type' => HistoricImportReleaseAsset::TypeSermon,
            'record_id' => 1,
            'source_disk' => 'historic_quarantine',
            'source_path' => 'quarantine/foreign.mp3',
            'destination_disk' => 'public',
            'destination_path' => 'historic/foreign.mp3',
            'destination_identity' => HistoricImportReleaseAsset::identityFor('public', 'historic/foreign.mp3'),
            'size' => 128,
            'sha256' => str_repeat('a', 64),
            'state' => HistoricImportReleaseAsset::StatePublished,
            'create_result' => 'preexisting',
            'verified_at' => now(),
            'published_at' => now(),
        ]);
        $orphan->assets()->create([
            'record_type' => HistoricImportReleaseAsset::TypeSermon,
            'record_id' => 2,
            'source_disk' => 'historic_quarantine',
            'source_path' => 'quarantine/orphan.mp3',
            'destination_disk' => 'public',
            'destination_path' => 'historic/orphan.mp3',
            'destination_identity' => HistoricImportReleaseAsset::identityFor('public', 'historic/orphan.mp3'),
            'size' => 256,
            'sha256' => str_repeat('b', 64),
            'state' => HistoricImportReleaseAsset::StateOrphaned,
            'create_result' => 'created',
            'compensated_at' => now(),
        ]);

        return [$foreign, $orphan];
    }

    private function releaseAttempt(
        HistoricImportOperation $operation,
        string $state,
        string $seed,
    ): HistoricImportReleaseAttempt {
        return HistoricImportReleaseAttempt::query()->create([
            'attempt_id' => (string) Str::uuid(),
            'historic_import_operation_id' => $operation->id,
            'authorisation_hash' => str_repeat($seed, 64),
            'membership_hash' => str_repeat($seed, 64),
            'state' => $state,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subMinute(),
            'started_at' => now()->subHour(),
            'completed_at' => $state === HistoricImportReleaseAttempt::StateCompleted ? now() : null,
            'failed_at' => $state === HistoricImportReleaseAttempt::StateOrphaned ? now() : null,
        ]);
    }

    /**
     * Two tables is enough for a membership comparison and keeps the fixture
     * readable; the shape is the producer's.
     *
     * @return array<string, array{row_count: int, columns_sha256: string}>
     */
    private function membership(): array
    {
        return [
            'church_services' => ['row_count' => 812, 'columns_sha256' => str_repeat('c', 64)],
            'sermons' => ['row_count' => 40, 'columns_sha256' => str_repeat('d', 64)],
        ];
    }

    /**
     * @param  array<string, array{row_count: int, columns_sha256: string}>|null  $membership
     * @return array<string, mixed>
     */
    private function rowManifest(string $domain, string $artifactId, string $anchor, ?array $membership = null): array
    {
        $tables = $membership ?? $this->membership();

        return $this->artifact($domain, $artifactId, json_encode([
            'format' => HistoricImportRowManifest::Format,
            'version' => HistoricImportRowManifest::Version,
            'connection_anchor' => $anchor,
            'generated_at' => '2026-08-12T12:30:00+00:00',
            'table_count' => count($tables),
            'tables' => $tables,
            'membership_sha256' => CanonicalJson::hash($tables),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, array{row_count: int, columns_sha256: string}> $membership */
    private function replaceRowManifest(string $artifactId, string $domain, string $anchor, array $membership): void
    {
        $role = $artifactId === 'manifest-on-host' ? 'on_host' : 'off_host';
        unlink($this->mappings[$artifactId]);
        unset($this->mappings[$artifactId]);

        $this->evidence['database_backups'][$role]['row_manifest'] = $this->rowManifest(
            $domain,
            $artifactId,
            $anchor,
            $membership,
        );
    }

    /**
     * Replace an artifact's bytes and re-declare its digest, so the case under
     * test is the manifest's content rather than the digest check in front of it.
     */
    private function rewriteArtifact(string $artifactId, string $contents): void
    {
        file_put_contents($this->mappings[$artifactId], $contents);
        $role = $artifactId === 'manifest-on-host' ? 'on_host' : 'off_host';
        $this->evidence['database_backups'][$role]['row_manifest'] = [
            'artifact_id' => $artifactId,
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    /** @return array{artifact_id: string, byte_size: int, sha256: string} */
    private function artifact(string $domain, string $artifactId, string $contents): array
    {
        $this->mappings[$artifactId] = $this->file($domain, $artifactId, $contents);

        return [
            'artifact_id' => $artifactId,
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function file(string $domain, string $name, string $contents): string
    {
        $path = $this->root.'/'.$domain.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  array<string, mixed>|null  $evidence  a pre-signed document, or null to sign the built one
     */
    private function verify(HistoricImportOperation $operation, ?array $evidence = null): PendingCommand
    {
        $options = [];

        foreach ($this->mappings as $artifactId => $path) {
            $options[] = "{$artifactId}={$path}";
        }

        return $this->artisan('historic-import:verify-recovery', [
            'operation' => $operation->operation_id,
            'evidence' => $this->writeJson($evidence ?? $this->sign($this->evidence)),
            '--artifact' => $options,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function sign(array $evidence, ?string $signingKey = null, ?string $keyId = null): array
    {
        $body = array_diff_key($evidence, ['signature' => true]);
        $evidence['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => $keyId ?? self::KeyId,
            'digest' => hash_hmac('sha256', CanonicalJson::encode($body), $signingKey ?? self::SigningKey),
        ];

        return $evidence;
    }

    /**
     * The version 1 document, verbatim from the HIR0 red test.
     *
     * @return array<string, mixed>
     */
    private function legacyEvidence(HistoricImportOperation $operation): array
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
            'operation_id' => $operation->operation_id,
            'target_fingerprint' => $operation->target_fingerprint,
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
        $path = $this->root.'/evidence-'.uniqid().'.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child) && ! is_link($child)) {
                $this->deleteTree($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
