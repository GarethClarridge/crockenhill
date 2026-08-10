<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use App\Models\ImportDeferredInboundEmail;
use App\Models\ImportIngressLock;
use App\Support\CanonicalJson;
use RuntimeException;

final class HistoricImportOperationalCloseoutEvidence
{
    public function __construct(private readonly HistoricImportTargetFingerprint $target) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function verify(HistoricImportOperation $operation, array $evidence, string $signingKey): array
    {
        $this->exactKeys($evidence, [
            'format', 'version', 'operation_id', 'target_fingerprint', 'release_identifier',
            'audit_report_sha256', 'ingress_release', 'smoke', 'runtime_recovery',
            'monitoring', 'deferred_inbound', 'verified_by', 'verified_at', 'signature',
        ], 'operational closeout evidence');

        if ($evidence['format'] !== 'crockenhill-historic-import-operational-closeout'
            || $evidence['version'] !== 1
            || $evidence['operation_id'] !== $operation->operation_id
            || $evidence['target_fingerprint'] !== $operation->target_fingerprint
            || $operation->target_fingerprint !== $this->target->hash()
            || $evidence['release_identifier'] !== config('app.release_identifier')) {
            throw new RuntimeException('Operational closeout evidence is not bound to this operation, target and release.');
        }

        $this->hash($evidence['audit_report_sha256'], 'exact audit report');
        $this->verifyIngressRelease($operation, $evidence['ingress_release']);
        $this->verifyPassedEvidenceGroup($evidence['smoke'], ['public', 'admin'], 'smoke');
        $this->verifyPassedEvidenceGroup(
            $evidence['runtime_recovery'],
            ['workers', 'scheduler', 'queues'],
            'runtime recovery',
        );
        $this->verifyMonitoring($evidence['monitoring']);
        $this->verifyDeferredInbound($operation, $evidence['deferred_inbound']);

        if (! is_string($evidence['verified_by']) || trim($evidence['verified_by']) === ''
            || ! is_string($evidence['verified_at']) || trim($evidence['verified_at']) === '') {
            throw new RuntimeException('Operational closeout evidence requires an independent verifier and timestamp.');
        }

        $signature = $evidence['signature'];
        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($evidence, ['signature' => true])),
            $signingKey,
        );

        if ($signingKey === '' || ! is_array($signature)
            || ($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('Operational closeout evidence signature is invalid.');
        }

        return $evidence;
    }

    private function verifyIngressRelease(HistoricImportOperation $operation, mixed $evidence): void
    {
        if (! is_array($evidence)) {
            throw new RuntimeException('Operational closeout has no exact ingress release evidence.');
        }

        $this->exactKeys($evidence, [
            'blocked_at', 'released_at', 'queue_pause_accounting_sha256',
        ], 'ingress release');
        $lock = ImportIngressLock::query()
            ->where('operation_id', $operation->operation_id)
            ->latest('id')
            ->first();

        if (! $lock instanceof ImportIngressLock
            || $lock->released_at === null
            || $lock->is_active !== null
            || $evidence['blocked_at'] !== $lock->blocked_at->toIso8601String()
            || $evidence['released_at'] !== $lock->released_at->toIso8601String()
            || ! is_array($lock->queue_pause_accounting)
            || ! array_key_exists('collateral_depth_at_release', $lock->queue_pause_accounting)
            || $evidence['queue_pause_accounting_sha256'] !== CanonicalJson::hash($lock->queue_pause_accounting)) {
            throw new RuntimeException('Operational closeout ingress/queue release does not match durable accounting.');
        }
    }

    /** @param list<string> $required */
    private function verifyPassedEvidenceGroup(mixed $group, array $required, string $label): void
    {
        if (! is_array($group)) {
            throw new RuntimeException("Operational closeout has no {$label} evidence.");
        }

        $this->exactKeys($group, $required, $label);

        foreach ($group as $name => $evidence) {
            if (! is_array($evidence)) {
                throw new RuntimeException("Operational closeout {$label} {$name} evidence is invalid.");
            }

            $this->exactKeys($evidence, ['passed', 'evidence_sha256'], "{$label} {$name}");

            if ($evidence['passed'] !== true) {
                throw new RuntimeException("Operational closeout {$label} {$name} did not pass.");
            }

            $this->hash($evidence['evidence_sha256'], "{$label} {$name}");
        }
    }

    private function verifyMonitoring(mixed $monitoring): void
    {
        if (! is_array($monitoring)) {
            throw new RuntimeException('Operational closeout has no retained monitoring evidence.');
        }

        $this->exactKeys($monitoring, [
            'watchboard_sha256', 'unresolved_exceptions', 'failed_jobs', 'timed_out_jobs',
        ], 'monitoring');
        $this->hash($monitoring['watchboard_sha256'], 'external monitoring watchboard');

        foreach (['unresolved_exceptions', 'failed_jobs', 'timed_out_jobs'] as $metric) {
            if ($monitoring[$metric] !== 0) {
                throw new RuntimeException("Operational closeout monitoring has non-zero {$metric}.");
            }
        }
    }

    private function verifyDeferredInbound(HistoricImportOperation $operation, mixed $evidence): void
    {
        if (! is_array($evidence)) {
            throw new RuntimeException('Operational closeout has no deferred-inbound reconciliation evidence.');
        }

        $this->exactKeys($evidence, ['pending', 'failed', 'reconciled_at'], 'deferred inbound');

        if ($evidence['pending'] !== 0 || $evidence['failed'] !== 0
            || ! is_string($evidence['reconciled_at']) || trim($evidence['reconciled_at']) === ''
            || ImportDeferredInboundEmail::query()
                ->where('operation_id', $operation->operation_id)
                ->whereNotIn('state', ['dispatched', 'processed'])
                ->exists()) {
            throw new RuntimeException('Operational closeout has unresolved operation-scoped deferred inbound email.');
        }
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
}
