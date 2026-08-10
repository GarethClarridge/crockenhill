<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Support\CanonicalJson;
use RuntimeException;

final class HistoricConvergenceCloseout
{
    public function __construct(
        private readonly HistoricConvergenceLedger $ledger,
        private readonly HistoricImportTargetFingerprint $target,
    ) {}

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array<string, mixed>
     */
    public function binding(string $operationId, array $mediaBundle, array $convergenceBundle): array
    {
        $operation = HistoricImportOperation::query()->where('operation_id', $operationId)->first();

        if (! $operation instanceof HistoricImportOperation
            || $operation->state !== HistoricImportOperationState::CloseoutRequired) {
            throw new RuntimeException('Exact closeout requires the matching closeout-ready durable operation.');
        }

        $expected = [
            'batch_hash' => $mediaBundle['batch_hash'] ?? null,
            'media_bundle_hash' => $mediaBundle['bundle_hash'] ?? null,
            'convergence_bundle_hash' => $convergenceBundle['bundle_hash'] ?? null,
            'processing_fingerprint_hash' => CanonicalJson::hash($mediaBundle['processing_fingerprint'] ?? null),
            'target_fingerprint' => $this->target->hash(),
        ];

        if ($operation->target_fingerprint !== $expected['target_fingerprint']) {
            throw new RuntimeException('Exact closeout durable operation target does not match the resolved target.');
        }
        $entries = $this->ledger->entries($operationId);
        $alreadyClosed = collect($entries)->contains(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        );

        if ($alreadyClosed) {
            throw new RuntimeException('Exact audit already closed this operation; verify the retained report instead.');
        }

        $prepared = collect($entries)->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'prepared',
        );

        if (! is_array($prepared)) {
            throw new RuntimeException('Exact closeout requires a prepared ledger operation.');
        }

        $manifestHashes = $operation->manifest_hashes;

        if (! in_array($expected['media_bundle_hash'], $manifestHashes, true)
            || ! in_array($expected['convergence_bundle_hash'], $manifestHashes, true)
            || ! is_string($prepared['plan_hash'] ?? null)
            || ! hash_equals($operation->plan_hash, $prepared['plan_hash'])) {
            throw new RuntimeException('Exact closeout durable operation does not bind both bundles and the applied plan.');
        }

        foreach ($expected as $field => $value) {
            if (! is_string($value)
                || ! is_string($prepared[$field] ?? null)
                || ! hash_equals($value, $prepared[$field])) {
                throw new RuntimeException("Exact closeout operation {$field} does not match the supplied bundles or target.");
            }
        }

        $services = $prepared['summary']['services'] ?? null;

        if (! is_array($services) || ! array_is_list($services) || $services === []) {
            throw new RuntimeException('Exact closeout operation has no prepared service membership.');
        }

        $identities = [];

        foreach ($services as $service) {
            $identity = is_array($service) ? ($service['identity'] ?? null) : null;

            if (! is_string($identity) || $identity === '') {
                throw new RuntimeException('Exact closeout operation has invalid service membership.');
            }

            $identities[] = $identity;
        }

        $completed = collect($entries)
            ->filter(static fn (array $entry): bool => ($entry['event'] ?? null) === 'service_completed')
            ->pluck('identity')
            ->filter(static fn (mixed $identity): bool => is_string($identity))
            ->unique()
            ->values()
            ->all();

        sort($completed, SORT_STRING);
        sort($identities, SORT_STRING);

        if ($completed !== $identities) {
            throw new RuntimeException('Exact closeout operation is not fully applied for its prepared service membership.');
        }

        $contentHash = $prepared['content_hash'] ?? null;

        if (! is_string($contentHash)) {
            throw new RuntimeException('Exact closeout prepared ledger has no durable content hash.');
        }

        return [
            ...$expected,
            'plan_hash' => $operation->plan_hash,
            'content_hash' => $contentHash,
            'identity_hash' => CanonicalJson::hash($identities),
            'service_count' => count($identities),
        ];
    }

    /** @param array<string, mixed> $binding */
    public function verifyRecordedReport(
        string $operationId,
        array $binding,
        string $privateRoot,
        string $suppliedPath,
    ): void {
        if (($binding['target_fingerprint'] ?? null) !== $this->target->hash()) {
            throw new RuntimeException('Recorded exact closeout target no longer matches the resolved target.');
        }

        $event = collect($this->ledger->entries($operationId))->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        );

        if (! is_array($event)) {
            throw new RuntimeException('Exact closeout has no passed durable audit event.');
        }

        foreach ($binding as $field => $value) {
            if (($event[$field] ?? null) !== $value) {
                throw new RuntimeException("Recorded exact closeout {$field} no longer matches the operation.");
            }
        }

        $locator = $event['report_locator'] ?? null;
        $digest = $event['report_digest'] ?? null;

        if (! is_string($locator) || $locator === '' || str_contains($locator, '..')
            || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new RuntimeException('Recorded exact closeout report binding is invalid.');
        }

        $path = rtrim($privateRoot, '/').'/'.$locator;

        if (realpath($path) !== realpath($suppliedPath)) {
            throw new RuntimeException('Supplied exact closeout report is not the report retained by the operation.');
        }

        $observedDigest = is_file($path) ? hash_file('sha256', $path) : false;

        if (! is_string($observedDigest) || ! hash_equals($digest, $observedDigest)) {
            throw new RuntimeException('Recorded exact closeout report is missing or differs from its durable digest.');
        }
    }
}
