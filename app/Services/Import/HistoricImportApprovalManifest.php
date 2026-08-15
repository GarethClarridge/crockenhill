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
 *
 * **IC2 re-scoped the artifact from a one-shot G8 window to a per-round
 * approval.** The old schema's `freeze` (deploy/rollback/configuration/
 * manifests/targeted_mutations), `abort_thresholds` (a window cost/error
 * budget) and `monitoring` (a retained external watchboard) fields evidenced a
 * windowed operation that no longer exists — REV-D1 retires window budgets and
 * split thresholds as gates, and F46/F58 are recorded lapsed with it
 * (docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §4).
 * `round` replaces them: the label an operator gave this round, the approved
 * corpus manifest hash and the exact reviewed plan hash it is bound to, and
 * the backup receipt §7.1 requires before RG-B apply. `manifestHash`/
 * `planHash` passed to {@see self::authorize()} are checked against `round`
 * when the caller supplies them, so an approval signed for one round's corpus
 * can never authorise a different one.
 * @see docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §6 IC2, §7.1
 */
final class HistoricImportApprovalManifest
{
    /** @return array<string, mixed> */
    public function authorize(
        string $path,
        string $command,
        string $targetFingerprint,
        string $signingKey,
        ?string $manifestHash = null,
        ?string $planHash = null,
    ): array {
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
            'round', 'roles', 'signature',
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

        $round = $approval['round'];

        if (! is_array($round)
            || ! is_string($round['label'] ?? null) || trim($round['label']) === ''
            || ! is_string($round['manifest_hash'] ?? null) || trim($round['manifest_hash']) === ''
            || ! is_string($round['plan_hash'] ?? null) || trim($round['plan_hash']) === ''
            || ! is_string($round['backup_receipt'] ?? null) || trim($round['backup_receipt']) === '') {
            throw new RuntimeException('The production approval round is incomplete.');
        }

        $this->exactKeys($round, [
            'label', 'manifest_hash', 'plan_hash', 'backup_receipt',
        ], 'production approval round');

        /**
         * The approval binds to one round's exact corpus and reviewed plan. A
         * caller with no hashes of its own to check (a lane IC2 has not reached
         * yet) skips this; every IC2 caller supplies both, so an approval signed
         * for a different manifest or plan is refused here rather than reused.
         */
        if ($manifestHash !== null && ! hash_equals($round['manifest_hash'], $manifestHash)) {
            throw new RuntimeException('The production approval round does not match this manifest.');
        }

        if ($planHash !== null && ! hash_equals($round['plan_hash'], $planHash)) {
            throw new RuntimeException('The production approval round does not match this plan.');
        }

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
