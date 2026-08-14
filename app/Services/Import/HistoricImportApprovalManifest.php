<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Verifies the signed artifact that authorises one historic import command
 * against one immutable operation on one resolved production target.
 *
 * **The four roles are accountability records, not four people.** Crockenhill is
 * a single-maintainer project, so D10 removed every multi-person control from
 * this programme: one person may hold `incident_commander`, `operator`,
 * `independent_verifier` and `monitoring_owner` simultaneously, and this class
 * deliberately does not compare the names for uniqueness. Do not reinstate a
 * distinctness check — an approval the only maintainer cannot sign is not a
 * safety control, it is an unreachable gate that would be worked around by
 * inventing names, which is strictly worse evidence than one honest name.
 * The roles are still required and still non-blank because the artifact is the
 * durable record of who to call and who owns rollback.
 *
 * @see docs/archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md — D10
 */
final class HistoricImportApprovalManifest
{
    /** @return array<string, mixed> */
    public function authorize(string $path, string $command, string $targetFingerprint, string $signingKey): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The historic import production approval artifact is missing.');
        }

        try {
            $approval = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The historic import production approval artifact is invalid JSON.', previous: $exception);
        }

        if (! is_array($approval)) {
            throw new RuntimeException('The historic import production approval artifact must be an object.');
        }

        $this->exactKeys($approval, [
            'format', 'version', 'approval_id', 'operation_id', 'binding_hash',
            'target_fingerprint', 'release_identifier', 'expires_at', 'permitted_commands',
            'freeze', 'roles', 'abort_thresholds', 'monitoring', 'signature',
        ], 'production approval');

        if ($approval['format'] !== 'crockenhill-historic-import-approval' || $approval['version'] !== 1) {
            throw new RuntimeException('The historic import production approval format is unsupported.');
        }

        if (! is_string($approval['approval_id']) || trim($approval['approval_id']) === '') {
            throw new RuntimeException('The historic import production approval id is missing.');
        }

        $signature = $approval['signature'];
        $expectedSignature = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($approval, ['signature' => true])),
            $signingKey,
        );

        if ($signingKey === '' || ! is_array($signature)
            || ($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expectedSignature, $signature['digest'])) {
            throw new RuntimeException('The historic import production approval signature is invalid.');
        }

        $operation = HistoricImportOperation::query()
            ->where('operation_id', $approval['operation_id'])
            ->first();

        if (! $operation instanceof HistoricImportOperation
            || $approval['binding_hash'] !== $operation->binding_hash
            || $approval['target_fingerprint'] !== $operation->target_fingerprint
            || $approval['target_fingerprint'] !== $targetFingerprint) {
            throw new RuntimeException('The production approval is not bound to the resolved operation and target.');
        }

        if ($operation->state === HistoricImportOperationState::Complete) {
            throw new RuntimeException('The production approval operation is no longer in an applicable state.');
        }

        $release = config('app.release_identifier');

        if (! is_string($release) || $approval['release_identifier'] !== trim($release)) {
            throw new RuntimeException('The production approval does not authorize this deployed release.');
        }

        try {
            $expiresAt = new DateTimeImmutable((string) $approval['expires_at']);
        } catch (\Throwable) {
            throw new RuntimeException('The production approval expiry is invalid.');
        }

        if ($expiresAt <= new DateTimeImmutable) {
            throw new RuntimeException('The production approval has expired.');
        }

        if ($operation->accepted_deadline === null
            || $operation->accepted_deadline->toDateTimeImmutable() < $expiresAt) {
            throw new RuntimeException('The production approval outlives the operation accepted deadline.');
        }

        if (! is_array($approval['permitted_commands'])
            || ! in_array($command, $approval['permitted_commands'], true)) {
            throw new RuntimeException("The production approval does not permit command/phase: {$command}.");
        }

        $freeze = $approval['freeze'];

        if (! is_array($freeze)
            || ($freeze['deploy'] ?? null) !== true
            || ($freeze['rollback'] ?? null) !== true
            || ($freeze['configuration'] ?? null) !== true
            || ($freeze['manifests'] ?? null) !== true
            || ($freeze['targeted_mutations'] ?? null) !== true
            || ! is_string($freeze['started_at'] ?? null)) {
            throw new RuntimeException('The production approval freeze is incomplete.');
        }

        $this->exactKeys($freeze, [
            'deploy', 'rollback', 'configuration', 'manifests',
            'targeted_mutations', 'started_at',
        ], 'production approval freeze');

        $roles = $approval['roles'];
        $roleNames = ['incident_commander', 'operator', 'independent_verifier', 'monitoring_owner'];

        if (! is_array($roles)) {
            throw new RuntimeException('The production approval has no named operational roles.');
        }

        $this->exactKeys($roles, $roleNames, 'production approval roles');

        foreach ($roles as $person) {
            if (! is_string($person) || trim($person) === '') {
                throw new RuntimeException('Every production approval role must name its owner.');
            }
        }

        $thresholds = $approval['abort_thresholds'];
        $requiredThresholds = [
            'failed_services', 'max_job_age_seconds', 'max_db_connections',
            'min_free_bytes', 'max_http_429', 'max_http_5xx', 'max_cost_minor_units',
        ];

        if (! is_array($thresholds)) {
            throw new RuntimeException('The production approval has no numeric abort thresholds.');
        }

        $this->exactKeys($thresholds, $requiredThresholds, 'production abort thresholds');

        foreach ($thresholds as $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Every production abort threshold must be a non-negative integer.');
            }
        }

        if ($thresholds['max_cost_minor_units'] !== $operation->max_cost_minor_units) {
            throw new RuntimeException('The production approval cost threshold does not match the immutable operation.');
        }

        $monitoring = $approval['monitoring'];

        if (! is_array($monitoring)
            || ! is_string($monitoring['provider'] ?? null)
            || trim($monitoring['provider']) === ''
            || ! is_string($monitoring['external_watchboard'] ?? null)
            || trim($monitoring['external_watchboard']) === ''
            || ($monitoring['retained'] ?? null) !== true) {
            throw new RuntimeException('The production approval has no retained external monitoring/watchboard.');
        }

        $this->exactKeys($monitoring, [
            'provider', 'external_watchboard', 'retained',
        ], 'production approval monitoring');

        return $approval;
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
}
