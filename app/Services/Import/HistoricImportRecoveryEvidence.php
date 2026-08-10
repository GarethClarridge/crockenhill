<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use RuntimeException;

final class HistoricImportRecoveryEvidence
{
    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function verify(HistoricImportOperation $operation, array $evidence): array
    {
        $this->exactKeys($evidence, [
            'format', 'version', 'operation_id', 'target_fingerprint', 'accepted_rpo_seconds',
            'accepted_rto_seconds', 'database_backups', 'object_recovery', 'preserved_artifacts',
            'exercises', 'verified_by', 'verified_at',
        ], 'recovery evidence');

        if ($evidence['format'] !== 'crockenhill-historic-import-recovery'
            || $evidence['version'] !== 1
            || $evidence['operation_id'] !== $operation->operation_id
            || $evidence['target_fingerprint'] !== $operation->target_fingerprint) {
            throw new RuntimeException('Recovery evidence is not bound to this exact import operation and target.');
        }

        $rpo = $this->positiveNumber($evidence['accepted_rpo_seconds'], 'accepted RPO');
        $rto = $this->positiveNumber($evidence['accepted_rto_seconds'], 'accepted RTO');
        $backups = $evidence['database_backups'];

        if (! is_array($backups)) {
            throw new RuntimeException('Recovery evidence requires on-host and independent off-host database backups.');
        }

        $this->exactKeys($backups, ['on_host', 'off_host'], 'database backups');

        $rowManifest = null;

        foreach ($backups as $role => $backup) {
            if (! is_array($backup)) {
                throw new RuntimeException("{$role} database backup evidence is invalid.");
            }

            $this->exactKeys($backup, [
                'artifact_sha256', 'transaction_snapshot', 'table_row_manifest_sha256',
                'restored_at', 'restored_target_fingerprint', 'observed_rpo_seconds',
                'observed_rto_seconds', 'restore_passed',
            ], "{$role} database backup");
            $this->hash($backup['artifact_sha256'], "{$role} backup artifact");
            $this->hash($backup['table_row_manifest_sha256'], "{$role} row manifest");

            if (($backup['restore_passed'] ?? null) !== true
                || ! is_string($backup['transaction_snapshot'] ?? null)
                || ! is_string($backup['restored_at'] ?? null)
                || ! is_string($backup['restored_target_fingerprint'] ?? null)
                || $backup['restored_target_fingerprint'] === $operation->target_fingerprint
                || $this->positiveNumber($backup['observed_rpo_seconds'], "{$role} observed RPO") > $rpo
                || $this->positiveNumber($backup['observed_rto_seconds'], "{$role} observed RTO") > $rto) {
                throw new RuntimeException("{$role} database backup has no acceptable disposable restore proof.");
            }

            $rowManifest ??= $backup['table_row_manifest_sha256'];

            if ($rowManifest !== $backup['table_row_manifest_sha256']) {
                throw new RuntimeException('On-host and off-host restores produced different table/row manifests.');
            }
        }

        $objectRecovery = $evidence['object_recovery'];

        if (! is_array($objectRecovery)
            || ! in_array($objectRecovery['mode'] ?? null, ['versioned_snapshot', 'operation_owned_create_only'], true)
            || ! is_string($objectRecovery['evidence_sha256'] ?? null)
            || ($objectRecovery['foreign_between_check_write_preserved'] ?? null) !== true
            || ($objectRecovery['foreign_before_cleanup_preserved'] ?? null) !== true
            || ($objectRecovery['ownership_reverified_before_delete'] ?? null) !== true) {
            throw new RuntimeException('Object recovery lacks version/create-only ownership and race evidence.');
        }

        $this->hash($objectRecovery['evidence_sha256'], 'object recovery evidence');

        $preserved = $evidence['preserved_artifacts'];
        $requiredArtifacts = ['source', 'bundles', 'results', 'private_staging', 'journal'];

        if (! is_array($preserved)) {
            throw new RuntimeException('Recovery evidence does not preserve every required operation artifact.');
        }

        $this->exactKeys($preserved, $requiredArtifacts, 'preserved artifacts');

        foreach ($preserved as $kind => $artifact) {
            if (! is_array($artifact)
                || ($artifact['independent_copy_count'] ?? 0) < 2
                || ! is_string($artifact['sha256'] ?? null)) {
                throw new RuntimeException("Recovery evidence does not independently preserve {$kind}.");
            }

            $this->hash($artifact['sha256'], "{$kind} preservation");
        }

        $exercises = $evidence['exercises'];
        $requiredExercises = [
            'mid_service_failure', 'cross_service_failure', 'asset_compensation',
            'full_restore', 'repeat_apply',
        ];

        if (! is_array($exercises)) {
            throw new RuntimeException('Recovery evidence omits a mandatory rollback exercise.');
        }

        $this->exactKeys($exercises, $requiredExercises, 'recovery exercises');

        foreach ($exercises as $name => $exercise) {
            if (! is_array($exercise)
                || ($exercise['passed'] ?? null) !== true
                || $this->positiveNumber($exercise['duration_seconds'] ?? null, "{$name} duration") > $rto
                || ! is_string($exercise['evidence_sha256'] ?? null)) {
                throw new RuntimeException("Recovery exercise {$name} did not pass within the accepted RTO.");
            }

            $this->hash($exercise['evidence_sha256'], "{$name} exercise evidence");
        }

        if (! is_string($evidence['verified_by']) || trim($evidence['verified_by']) === ''
            || ! is_string($evidence['verified_at']) || trim($evidence['verified_at']) === '') {
            throw new RuntimeException('Recovery evidence requires an independent verifier and timestamp.');
        }

        return $evidence;
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
            throw new RuntimeException("{$label} has missing or unknown fields.");
        }
    }

    private function hash(mixed $value, string $label): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("{$label} must be a SHA-256 digest.");
        }
    }

    private function positiveNumber(mixed $value, string $label): float
    {
        if ((! is_int($value) && ! is_float($value)) || $value < 0) {
            throw new RuntimeException("{$label} must be a non-negative numeric value.");
        }

        return (float) $value;
    }
}
