<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportItemOutcome;
use App\Models\HistoricImportOperation;
use App\Models\ImportIngressLock;
use App\Services\ChurchService\HistoricConvergenceLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class HistoricImportOperationCloseout
{
    public function __construct(
        private readonly HistoricConvergenceLedger $convergenceLedger,
        private readonly HistoricImportJournal $journal,
        private readonly ImportIngressGate $ingress,
        private readonly HistoricImportArtifactWriter $artifacts,
        private readonly HistoricImportTargetFingerprint $target,
    ) {}

    /** @param array<string, mixed> $binding */
    public function complete(string $operationId, array $binding): HistoricImportOperation
    {
        return DB::transaction(function () use ($operationId, $binding): HistoricImportOperation {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if (! $operation instanceof HistoricImportOperation
                || $operation->state !== HistoricImportOperationState::CloseoutRequired
                || $operation->target_fingerprint !== ($binding['target_fingerprint'] ?? null)
                || $operation->target_fingerprint !== $this->target->hash()) {
                throw new RuntimeException('Exact closeout is not bound to a closeout-ready durable operation.');
            }

            $checkpoints = $operation->checkpoints()->with(['itemOutcomes', 'itemOutcomes.sourceSnapshot'])->get();

            if ($checkpoints->isEmpty()) {
                throw new RuntimeException('Exact closeout operation has no durable checkpoint membership.');
            }

            foreach ($checkpoints as $checkpoint) {
                if ($checkpoint->state !== HistoricImportCheckpointState::Complete) {
                    throw new RuntimeException("Checkpoint {$checkpoint->checkpoint_key} is not exact-complete.");
                }

                $outcomes = $checkpoint->itemOutcomes->keyBy('item_key');
                $expectedItemKeys = $checkpoint->item_keys;
                $observedItemKeys = $outcomes->keys()->all();
                sort($expectedItemKeys, SORT_STRING);
                sort($observedItemKeys, SORT_STRING);

                if ($expectedItemKeys !== $observedItemKeys) {
                    throw new RuntimeException("Checkpoint {$checkpoint->checkpoint_key} outcome membership is not exact.");
                }

                foreach ($checkpoint->item_keys as $itemKey) {
                    $outcome = $outcomes->get($itemKey);

                    if (! $outcome instanceof HistoricImportItemOutcome
                        || ! $outcome->disposition->satisfiesCloseout($outcome->expectation)
                        || $outcome->settled_at === null
                        || $outcome->sourceSnapshot === null
                        || ! is_string($outcome->approved_source_sha256)
                        || ! is_string($outcome->observed_source_sha256)
                        || ! hash_equals($outcome->approved_source_sha256, $outcome->observed_source_sha256)) {
                        throw new RuntimeException("Checkpoint item {$itemKey} lacks exact source/output closeout evidence.");
                    }
                }
            }

            if ($operation->nestedJobs()->where(function (Builder $query): void {
                $query->where('state', '!=', 'completed')->orWhereNull('settled_at');
            })->exists()) {
                throw new RuntimeException('Exact closeout operation still has unsettled nested jobs.');
            }

            /**
             * HIR5: version 1 recovery evidence was unsigned, and none of the
             * backups, manifests or exercises it named were ever opened. The v2
             * key is what a repaired gate accepts; the v1 artifact stays
             * retained as superseded evidence and can no longer satisfy this.
             */
            $this->assertOperationArtifact(
                $operation,
                'recovery-rehearsal-v2',
                HistoricImportRecoveryEvidence::Format,
                HistoricImportRecoveryEvidence::Version,
            );
            /**
             * HIR6: a new artifact key, so a version 1 document signed against
             * the gate that accepted a queue handoff as completion cannot
             * satisfy the repaired closeout. The v1 artifact stays retained.
             */
            $operationalEvidence = $this->assertOperationArtifact(
                $operation,
                'operational-closeout-readiness-v2',
                'crockenhill-historic-import-operational-closeout',
                HistoricImportOperationalCloseoutEvidence::Version,
            );

            if (($operationalEvidence['audit_report_sha256'] ?? null) !== ($binding['report_digest'] ?? null)) {
                throw new RuntimeException('Operational closeout evidence does not bind the retained exact audit report.');
            }

            if ($this->ingress->active() instanceof ImportIngressLock) {
                throw new RuntimeException('Exact closeout cannot complete while an ingress window remains active.');
            }

            $this->assertExactAudit($operationId, $binding);
            $this->assertExactNoOp($operationId, $binding);
            $this->ingress->assertDeferredInboundEmailReconciled($operationId);
            $this->journal->verify($operation);
            $this->journal->append($operation, 'operation_closeout_complete', [
                'report_digest' => $binding['report_digest'] ?? null,
                'media_bundle_hash' => $binding['media_bundle_hash'] ?? null,
                'convergence_bundle_hash' => $binding['convergence_bundle_hash'] ?? null,
                'identity_hash' => $binding['identity_hash'] ?? null,
            ]);
            $operation->transitionTo(HistoricImportOperationState::Complete);

            return $operation->fresh() ?? $operation;
        });
    }

    /** @param array<string, mixed> $binding */
    private function assertExactAudit(string $operationId, array $binding): void
    {
        $event = collect($this->convergenceLedger->entries($operationId))->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        );

        if (! is_array($event) || ($event['passed'] ?? null) !== true) {
            throw new RuntimeException('Exact closeout operation has no matching durable audit report event.');
        }

        foreach ([
            'batch_hash', 'media_bundle_hash', 'convergence_bundle_hash',
            'processing_fingerprint_hash', 'target_fingerprint', 'plan_hash',
            'content_hash', 'identity_hash', 'service_count', 'report_digest',
        ] as $field) {
            if (($event[$field] ?? null) !== ($binding[$field] ?? null)) {
                throw new RuntimeException("Exact audit {$field} does not match the durable operation closeout.");
            }
        }
    }

    /** @param array<string, mixed> $binding */
    private function assertExactNoOp(string $operationId, array $binding): void
    {
        $event = collect($this->convergenceLedger->entries($operationId))->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_noop_rerun',
        );

        if (! is_array($event) || ($event['passed'] ?? null) !== true) {
            throw new RuntimeException('Exact closeout operation has no complete exact no-op rerun.');
        }

        foreach ([
            'batch_hash', 'media_bundle_hash', 'convergence_bundle_hash',
            'processing_fingerprint_hash', 'target_fingerprint', 'identity_hash', 'service_count',
        ] as $field) {
            if (($event[$field] ?? null) !== ($binding[$field] ?? null)) {
                throw new RuntimeException("Exact no-op rerun {$field} does not match the durable audit.");
            }
        }
    }

    /** @return array<string, mixed> */
    private function assertOperationArtifact(
        HistoricImportOperation $operation,
        string $artifactKey,
        string $format,
        int $version = 1,
    ): array {
        $artifact = $operation->artifacts()->where('artifact_key', $artifactKey)->first();

        if ($artifact === null) {
            throw new RuntimeException("Exact closeout operation has no verified {$artifactKey} artifact.");
        }

        try {
            $evidence = json_decode($this->artifacts->read($artifact), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Exact closeout {$artifactKey} artifact is invalid JSON.", previous: $exception);
        }

        if (! is_array($evidence)
            || ($evidence['format'] ?? null) !== $format
            || ($evidence['version'] ?? null) !== $version
            || ($evidence['operation_id'] ?? null) !== $operation->operation_id
            || ($evidence['target_fingerprint'] ?? null) !== $operation->target_fingerprint) {
            throw new RuntimeException("Exact closeout {$artifactKey} artifact lost its operation/target binding.");
        }

        return $evidence;
    }
}
