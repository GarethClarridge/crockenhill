<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricReleaseObjectStore;
use App\Data\HistoricRecoveryArtifactObservation;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAsset;
use App\Models\HistoricImportReleaseAttempt;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * HIR5: recovery evidence that is authenticated and backed by artifacts the
 * gate actually opens.
 *
 * Version 1 accepted an unsigned JSON document in which every digest was a
 * repeated placeholder digit, `verified_by` was free text, and the same backup
 * object was presented as both the on-host and the off-host restore — two
 * independent copies being one copy counted twice. It checked shape, digest
 * *syntax* and success booleans, and never opened a backup, manifest or exercise
 * artifact. Exact closeout then read the result as the mandatory recovery gate's
 * satisfied evidence.
 *
 * Version 2 changes what a claim is worth:
 *
 * - **Signed, and verified first.** Nothing reads a verification path or writes
 *   a retained artifact until the signature over the canonical body verifies
 *   under the configured recovery key ID.
 * - **Every digest is reproduced.** Each backup, row manifest, preserved copy,
 *   exercise and object-recovery artifact carries a logical artifact ID, and the
 *   operator supplies `artifact-id=path` for each. Size and SHA-256 are
 *   recomputed from the bytes.
 * - **Independence is a failure domain, not a string.** The two backups must sit
 *   in different failure domains, as must the copies of each preserved artifact,
 *   observed through the same boundary HIR4 uses for source custody.
 * - **The restores are compared, not asserted equal.** Both row manifests are
 *   read through {@see HistoricImportRowManifest} and compared as exact table/row
 *   membership, and neither restore may be the production database or the other
 *   restore.
 * - **Object recovery is read from the HIR7 ledger.** The self-attested
 *   `foreign_before_cleanup_preserved` boolean is gone; what replaces it is a
 *   release attempt, a foreign object recorded `preexisting`, and an object this
 *   programme created, failed on, and retained as an orphan.
 *
 * **What this does not establish.** HIR-D3 decided, against recommendation, that
 * recovery evidence is signed with the existing approval signing key rather than
 * a separate recovery-only key. That key is a symmetric secret the application
 * must hold in order to verify, so the application holds everything needed to
 * generate a valid signature. The signature therefore attests integrity and
 * approval-key custody — it does not establish that an independent verifier
 * produced this document, and no field here may be read as saying it does. The
 * assurance in this class is the artifact-backed half: an accepted digest can be
 * reproduced from a retained artifact, and an observed ledger fact cannot be
 * typed into a JSON file. A distinct key ID is still required so the decision can
 * be revisited without a schema change.
 *
 * Delete alongside the rest of the historic import tooling once the acceptance
 * and rollback-retention windows have expired (G9/WP10).
 */
final class HistoricImportRecoveryEvidence
{
    public const string Format = 'crockenhill-historic-import-recovery';

    /**
     * Version 2 (HIR5). Version 1 artifacts stay retained and readable but
     * cannot satisfy the repaired closeout gate: they were signed against a gate
     * that opened nothing.
     */
    public const int Version = 2;

    private const PreservedArtifacts = ['source', 'bundles', 'results', 'private_staging', 'journal'];

    private const Exercises = [
        'mid_service_failure', 'cross_service_failure', 'asset_compensation',
        'full_restore', 'repeat_apply',
    ];

    public function __construct(
        private readonly HistoricImportRecoveryArtifactResolver $resolver,
        private readonly HistoricImportRowManifest $manifests,
        private readonly HistoricImportResourceIdentity $resources,
        private readonly HistoricReleaseObjectStore $objects,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, string>  $artifactPaths  logical artifact id => verification path
     * @return array<string, mixed>
     */
    public function verify(
        HistoricImportOperation $operation,
        array $evidence,
        array $artifactPaths,
        string $signingKey,
        string $keyId,
    ): array {
        /**
         * Before anything else, and before any path is opened. A document that
         * does not authenticate is not evidence, and reading the artifacts it
         * names would be acting on an attacker's file list.
         */
        $this->assertAuthentic($evidence, $signingKey, $keyId);
        $this->assertSchema($evidence);
        $this->assertBinding($operation, $evidence);

        $rpo = $this->positiveNumber($evidence['accepted_rpo_seconds'], 'accepted RPO');
        $rto = $this->positiveNumber($evidence['accepted_rto_seconds'], 'accepted RTO');
        $references = $this->artifactReferences($evidence);
        $observations = $this->resolver->resolve(array_keys($references), $artifactPaths);

        foreach ($references as $artifactId => $reference) {
            $this->assertObservedArtifact($artifactId, $reference, $observations[$artifactId]);
        }

        $restores = $this->verifyDatabaseBackups($evidence['database_backups'], $observations, $rpo, $rto);
        $this->verifyPreservedArtifacts($evidence['preserved_artifacts'], $observations);
        $this->verifyExercises($evidence['exercises'], $rto);
        $ledger = $this->verifyObjectRecovery($operation, $evidence['object_recovery']);

        return [
            ...$evidence,
            /**
             * What was observed, as distinct from what was claimed. Verification
             * paths are deliberately absent: they say where this host found an
             * artifact during one verification, and a later reader must not be
             * able to mistake that for custody.
             */
            'observed' => [
                'artifacts' => array_map(
                    static fn (HistoricRecoveryArtifactObservation $observation): array => $observation->toArray(),
                    $observations,
                ),
                'restores' => $restores,
                'object_recovery' => $ledger,
                'verified_at' => now()->utc()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    public function declaredArtifactIds(array $evidence): array
    {
        return array_keys($this->artifactReferences($evidence));
    }

    /** @param array<string, mixed> $evidence */
    private function assertAuthentic(array $evidence, string $signingKey, string $keyId): void
    {
        if ($signingKey === '') {
            throw new RuntimeException('The historic import recovery evidence signing key is not configured.');
        }

        if ($keyId === '') {
            throw new RuntimeException('The historic import recovery evidence key id is not configured.');
        }

        $signature = $evidence['signature'] ?? null;

        if (! is_array($signature)) {
            throw new RuntimeException('Recovery evidence carries no signature, so nothing authenticates it.');
        }

        $this->exactKeys($signature, ['algorithm', 'key_id', 'digest'], 'recovery evidence signature');

        if (($signature['key_id'] ?? null) !== $keyId) {
            throw new RuntimeException('Recovery evidence is signed under an unrecognised key id.');
        }

        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($evidence, ['signature' => true])),
            $signingKey,
        );

        if (($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('Recovery evidence signature does not authenticate this document.');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertSchema(array $evidence): void
    {
        $this->exactKeys($evidence, [
            'format', 'version', 'operation_id', 'target_fingerprint', 'release_identifier',
            'resource_identities', 'accepted_rpo_seconds', 'accepted_rto_seconds',
            'database_backups', 'object_recovery', 'preserved_artifacts', 'exercises',
            'verified_by', 'verified_at', 'signature',
        ], 'recovery evidence');

        foreach (['database_backups', 'object_recovery', 'preserved_artifacts', 'exercises'] as $section) {
            if (! is_array($evidence[$section])) {
                throw new RuntimeException("Recovery evidence section {$section} is invalid.");
            }
        }

        if (! is_string($evidence['verified_by']) || trim($evidence['verified_by']) === ''
            || ! is_string($evidence['verified_at']) || trim($evidence['verified_at']) === '') {
            throw new RuntimeException('Recovery evidence requires a named verifier and timestamp.');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertBinding(HistoricImportOperation $operation, array $evidence): void
    {
        if ($evidence['format'] !== self::Format
            || $evidence['version'] !== self::Version
            || $evidence['operation_id'] !== $operation->operation_id
            || $evidence['target_fingerprint'] !== $operation->target_fingerprint) {
            throw new RuntimeException('Recovery evidence is not bound to this exact import operation and target.');
        }

        if ($evidence['release_identifier'] !== config('app.release_identifier')) {
            throw new RuntimeException('Recovery evidence was produced against a different deployed release.');
        }

        $identities = $evidence['resource_identities'];

        if (! is_array($identities)) {
            throw new RuntimeException('Recovery evidence records no stable resource identities.');
        }

        $this->exactKeys($identities, ['database_anchor', 'storage_anchor'], 'recovery resource identities');

        /**
         * The stable HIR1 anchors, not the whole target fingerprint. A rehearsal
         * whose release or migration count has since drifted is still the same
         * database and object store, and version 1's
         * `restored_target_fingerprint` comparison could be satisfied by any
         * string that differed.
         */
        if ($identities['database_anchor'] !== $this->resources->databaseAnchor()
            || $identities['storage_anchor'] !== $this->resources->storageAnchor()) {
            throw new RuntimeException('Recovery evidence names different database/storage resources than this target.');
        }
    }

    /**
     * Every artifact the document declares, keyed by logical artifact ID.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, array<string, mixed>>
     */
    private function artifactReferences(array $evidence): array
    {
        $references = [];
        $backups = is_array($evidence['database_backups'] ?? null) ? $evidence['database_backups'] : [];
        $preserved = is_array($evidence['preserved_artifacts'] ?? null) ? $evidence['preserved_artifacts'] : [];
        $exercises = is_array($evidence['exercises'] ?? null) ? $evidence['exercises'] : [];
        $objects = is_array($evidence['object_recovery'] ?? null) ? $evidence['object_recovery'] : [];

        foreach ($backups as $role => $backup) {
            if (is_array($backup)) {
                $references = $this->collect($references, $backup['artifact'] ?? null, "{$role} backup");
                $references = $this->collect($references, $backup['row_manifest'] ?? null, "{$role} row manifest");
            }
        }

        foreach ($preserved as $kind => $artifact) {
            $copies = is_array($artifact) && is_array($artifact['copies'] ?? null) ? $artifact['copies'] : [];

            foreach ($copies as $index => $copy) {
                $references = $this->collect($references, $copy, "preserved {$kind} copy {$index}");
            }
        }

        foreach ($exercises as $name => $exercise) {
            if (is_array($exercise)) {
                $references = $this->collect($references, $exercise['artifact'] ?? null, "exercise {$name}");
            }
        }

        foreach (['foreign_object', 'retained_orphan'] as $role) {
            $entry = $objects[$role] ?? null;

            if (is_array($entry)) {
                $references = $this->collect($references, $entry['artifact'] ?? null, "object recovery {$role}");
            }
        }

        return $this->collect($references, $objects['receipts'] ?? null, 'object recovery receipts');
    }

    /**
     * @param  array<string, array<string, mixed>>  $references
     * @return array<string, array<string, mixed>>
     */
    private function collect(array $references, mixed $reference, string $label): array
    {
        if (! is_array($reference)) {
            throw new RuntimeException("Recovery evidence has no artifact reference for {$label}.");
        }

        $this->exactKeys($reference, ['artifact_id', 'byte_size', 'sha256'], "{$label} artifact reference");

        $artifactId = $reference['artifact_id'];

        if (! is_string($artifactId) || trim($artifactId) === '') {
            throw new RuntimeException("Recovery evidence artifact id for {$label} is invalid.");
        }

        if (isset($references[$artifactId])) {
            throw new RuntimeException(
                "Recovery evidence declares artifact id {$artifactId} more than once, so one artifact stands in for two.",
            );
        }

        $this->hash($reference['sha256'], "{$label} artifact");

        if (! is_int($reference['byte_size']) || $reference['byte_size'] < 1) {
            throw new RuntimeException("Recovery evidence artifact {$artifactId} declares no byte size.");
        }

        $references[$artifactId] = $reference;

        return $references;
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function assertObservedArtifact(
        string $artifactId,
        array $reference,
        HistoricRecoveryArtifactObservation $observation,
    ): void {
        if ($reference['byte_size'] !== $observation->byteSize) {
            throw new RuntimeException(
                "Recovery artifact {$artifactId} is {$observation->byteSize} bytes, not the declared "
                ."{$reference['byte_size']}.",
            );
        }

        if (! hash_equals((string) $reference['sha256'], $observation->sha256)) {
            throw new RuntimeException("Recovery artifact {$artifactId} does not hash to its declared digest.");
        }
    }

    /**
     * @param  array<string, mixed>  $backups
     * @param  array<string, HistoricRecoveryArtifactObservation>  $observations
     * @return array<string, mixed>
     */
    private function verifyDatabaseBackups(array $backups, array $observations, float $rpo, float $rto): array
    {
        $this->exactKeys($backups, ['on_host', 'off_host'], 'database backups');
        $manifests = [];
        $domains = [];

        foreach ($backups as $role => $backup) {
            if (! is_array($backup)) {
                throw new RuntimeException("The {$role} database backup evidence is invalid.");
            }

            $this->exactKeys($backup, [
                'artifact', 'row_manifest', 'transaction_snapshot', 'restored_at',
                'observed_rpo_seconds', 'observed_rto_seconds',
            ], "{$role} database backup");

            if (! is_string($backup['transaction_snapshot']) || trim($backup['transaction_snapshot']) === ''
                || ! is_string($backup['restored_at']) || trim($backup['restored_at']) === '') {
                throw new RuntimeException("The {$role} database backup has no restore point or timestamp.");
            }

            if ($this->positiveNumber($backup['observed_rpo_seconds'], "{$role} observed RPO") > $rpo) {
                throw new RuntimeException("The {$role} restore exceeded the accepted recovery point objective.");
            }

            if ($this->positiveNumber($backup['observed_rto_seconds'], "{$role} observed RTO") > $rto) {
                throw new RuntimeException("The {$role} restore exceeded the accepted recovery time objective.");
            }

            $domains[$role] = $observations[$backup['artifact']['artifact_id']]->failureDomain;
            $manifests[$role] = $this->manifests->parse(
                $this->contentsOf($observations[$backup['row_manifest']['artifact_id']]),
                $role,
            );
        }

        /**
         * Two backups on one mounted device are one backup. The artifacts are
         * already known to be different files — the resolver refuses two IDs
         * resolving to one inode — but different files that a single filesystem
         * loss takes together are not an on-host and an off-host copy.
         */
        if ($domains['on_host'] === $domains['off_host']) {
            throw new RuntimeException(
                'The on-host and off-host database backups share one failure domain, so a single storage loss '
                .'defeats both. Two independently protected copies are required.',
            );
        }

        return $this->verifyRestoreIdentities($manifests);
    }

    /**
     * @param  array<string, array<string, mixed>>  $manifests
     * @return array<string, mixed>
     */
    private function verifyRestoreIdentities(array $manifests): array
    {
        $production = $this->resources->databaseAnchor();

        foreach ($manifests as $role => $manifest) {
            if ($manifest['connection_anchor'] === $production) {
                throw new RuntimeException(
                    "The {$role} restore was verified against the production database rather than a disposable one.",
                );
            }
        }

        if ($manifests['on_host']['connection_anchor'] === $manifests['off_host']['connection_anchor']) {
            throw new RuntimeException(
                'Both row manifests were read from the same database, so one restore is being counted twice.',
            );
        }

        $this->manifests->assertEqualMembership($manifests['on_host'], $manifests['off_host']);

        return [
            'on_host_connection_anchor' => $manifests['on_host']['connection_anchor'],
            'off_host_connection_anchor' => $manifests['off_host']['connection_anchor'],
            'membership_sha256' => $manifests['on_host']['membership_sha256'],
            'table_count' => $manifests['on_host']['table_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $preserved
     * @param  array<string, HistoricRecoveryArtifactObservation>  $observations
     */
    private function verifyPreservedArtifacts(array $preserved, array $observations): void
    {
        $this->exactKeys($preserved, self::PreservedArtifacts, 'preserved artifacts');

        foreach ($preserved as $kind => $artifact) {
            if (! is_array($artifact)) {
                throw new RuntimeException("Recovery evidence does not preserve {$kind}.");
            }

            $this->exactKeys($artifact, ['copies'], "preserved {$kind}");
            $copies = $artifact['copies'];

            if (! is_array($copies) || count($copies) < 2) {
                throw new RuntimeException("Recovery evidence preserves {$kind} in fewer than two copies.");
            }

            $domains = [];
            $digests = [];

            foreach ($copies as $copy) {
                $observation = $observations[$copy['artifact_id']];
                $domains[] = $observation->failureDomain;
                $digests[] = $observation->sha256;
            }

            if (count(array_unique($domains)) !== count($domains)) {
                throw new RuntimeException(
                    "The preserved copies of {$kind} share a failure domain, so they are not independent copies.",
                );
            }

            /**
             * Copies of one thing, not two different things counted as a pair.
             * Version 1 asked for `independent_copy_count >= 2` and a single
             * digest, so a second copy holding anything at all satisfied it.
             */
            if (count(array_unique($digests)) !== 1) {
                throw new RuntimeException("The preserved copies of {$kind} do not hold identical bytes.");
            }
        }
    }

    /** @param array<string, mixed> $exercises */
    private function verifyExercises(array $exercises, float $rto): void
    {
        $this->exactKeys($exercises, self::Exercises, 'recovery exercises');

        foreach ($exercises as $name => $exercise) {
            if (! is_array($exercise)) {
                throw new RuntimeException("Recovery exercise {$name} evidence is invalid.");
            }

            $this->exactKeys($exercise, ['passed', 'duration_seconds', 'artifact'], "recovery exercise {$name}");

            if ($exercise['passed'] !== true) {
                throw new RuntimeException("Recovery exercise {$name} did not pass.");
            }

            if ($this->positiveNumber($exercise['duration_seconds'], "{$name} duration") > $rto) {
                throw new RuntimeException("Recovery exercise {$name} exceeded the accepted recovery time objective.");
            }
        }
    }

    /**
     * HIR5 item 7. What replaced `foreign_before_cleanup_preserved => true`.
     *
     * The exercise has to have run against the HIR7 release implementation, and
     * the proof is that its ledger holds two things no earlier implementation
     * could produce: an object a foreign writer put at a claimed destination,
     * recorded `preexisting` and verified rather than overwritten; and an object
     * this programme created, failed on, and retained as an orphan rather than
     * deleting by path. Both are read from the ledger here, not asserted in JSON.
     *
     * @param  array<string, mixed>  $recovery
     * @return array<string, mixed>
     */
    private function verifyObjectRecovery(HistoricImportOperation $operation, array $recovery): array
    {
        $this->exactKeys($recovery, [
            'release_implementation', 'exact_version_delete_supported',
            'foreign_object', 'retained_orphan', 'receipts',
        ], 'object recovery');

        if ($recovery['release_implementation'] !== HistoricSermonPublicationService::ReleaseImplementation) {
            throw new RuntimeException(
                'Object recovery evidence was produced against a different release implementation than the one '
                .'deployed, so it proves nothing about how this code handles a destination race.',
            );
        }

        /**
         * HIR-D1 measured that neither store can delete an exact version, which
         * is *why* compensation retains. Evidence claiming otherwise describes a
         * capability the operator does not have.
         */
        $observedDeleteSupport = $this->objects->supportsExactVersionDelete(
            (string) config('media-processing.storage.sermon_disk'),
        );

        if ($recovery['exact_version_delete_supported'] !== false
            || $observedDeleteSupport !== false) {
            throw new RuntimeException(
                'Object recovery evidence must record that exact-version deletion is unavailable, which is why a '
                .'failed attempt retains its objects rather than deleting them.',
            );
        }

        [$foreign, $foreignAttempt] = $this->releaseAsset($operation, $recovery['foreign_object'], 'foreign object');
        [$orphan, $orphanAttempt] = $this->releaseAsset($operation, $recovery['retained_orphan'], 'retained orphan');

        if ($foreign->destination_identity === $orphan->destination_identity) {
            throw new RuntimeException(
                'The foreign object and the retained orphan are one destination, so a single ledger row is standing '
                .'in for both halves of the exercise.',
            );
        }

        if ($foreign->create_result !== 'preexisting'
            || $foreign->verified_at === null
            || ! in_array($foreign->state, [
                HistoricImportReleaseAsset::StatePreexistingVerified,
                HistoricImportReleaseAsset::StatePublished,
            ], true)) {
            throw new RuntimeException(
                'The release ledger does not record a foreign object that was observed, verified and left alone.',
            );
        }

        if ($orphan->create_result !== 'created'
            || $orphan->state !== HistoricImportReleaseAsset::StateOrphaned
            || $orphan->compensated_at === null
            || $orphanAttempt->state !== HistoricImportReleaseAttempt::StateOrphaned) {
            throw new RuntimeException(
                'The release ledger does not record an object this programme created, failed on and retained; '
                .'without it nothing shows compensation refusing to delete.',
            );
        }

        return [
            'release_implementation' => HistoricSermonPublicationService::ReleaseImplementation,
            'exact_version_delete_supported' => false,
            'foreign_object' => [
                'attempt_id' => $foreignAttempt->attempt_id,
                'destination_identity' => $foreign->destination_identity,
                'create_result' => $foreign->create_result,
                'state' => $foreign->state,
            ],
            'retained_orphan' => [
                'attempt_id' => $orphanAttempt->attempt_id,
                'destination_identity' => $orphan->destination_identity,
                'create_result' => $orphan->create_result,
                'state' => $orphan->state,
                'attempt_state' => $orphanAttempt->state,
            ],
        ];
    }

    /** @return array{0: HistoricImportReleaseAsset, 1: HistoricImportReleaseAttempt} */
    private function releaseAsset(
        HistoricImportOperation $operation,
        mixed $entry,
        string $label,
    ): array {
        if (! is_array($entry)) {
            throw new RuntimeException("Object recovery {$label} evidence is invalid.");
        }

        $this->exactKeys($entry, ['attempt_id', 'destination_identity', 'artifact'], "object recovery {$label}");
        $this->hash($entry['destination_identity'], "object recovery {$label} destination");

        $asset = HistoricImportReleaseAsset::query()
            ->with('attempt')
            ->where('destination_identity', $entry['destination_identity'])
            ->first();

        if (! $asset instanceof HistoricImportReleaseAsset) {
            throw new RuntimeException(
                "The release ledger holds no {$label} at the destination the recovery evidence names.",
            );
        }

        $attempt = $asset->attempt;

        if (! $attempt instanceof HistoricImportReleaseAttempt
            || $attempt->attempt_id !== $entry['attempt_id']
            || $attempt->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException(
                "The {$label} belongs to a different release attempt or operation than the evidence claims.",
            );
        }

        if (! $attempt->isFinished()) {
            throw new RuntimeException("The {$label} release attempt has not finished, so its outcome is not evidence.");
        }

        return [$asset, $attempt];
    }

    /**
     * The bytes behind an already-verified observation.
     *
     * Re-hashed rather than trusted, because the observation was taken a few
     * statements earlier and this class must never parse content it has not
     * itself just confirmed.
     */
    private function contentsOf(HistoricRecoveryArtifactObservation $observation): string
    {
        $contents = @file_get_contents($observation->path);

        if (! is_string($contents) || ! hash_equals($observation->sha256, hash('sha256', $contents))) {
            throw new RuntimeException(
                "Recovery artifact {$observation->artifactId} changed while it was being verified; nothing was accepted.",
            );
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("The {$label} has missing or unknown fields.");
        }
    }

    private function hash(mixed $value, string $label): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("The {$label} digest must be a SHA-256 digest.");
        }
    }

    private function positiveNumber(mixed $value, string $label): float
    {
        if ((! is_int($value) && ! is_float($value)) || $value < 0) {
            throw new RuntimeException("The {$label} must be a non-negative numeric value.");
        }

        return (float) $value;
    }
}
