<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportItemOutcome;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportSourceSnapshot;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricImportCheckpointRuntime
{
    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    public function admit(HistoricImportCheckpoint $checkpoint, string $runtimeFingerprint): void
    {
        DB::transaction(function () use ($checkpoint, $runtimeFingerprint): void {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->assertRuntimeBinding($operation, $runtimeFingerprint);

            if ($checkpoint->state !== HistoricImportCheckpointState::Planned) {
                throw new RuntimeException('Only a planned historic import checkpoint can be admitted.');
            }

            if ($operation->accepted_deadline === null || $operation->accepted_deadline->isPast()) {
                throw new RuntimeException('Historic import checkpoint admission requires a live accepted deadline.');
            }

            $priorIncomplete = $operation->checkpoints()
                ->where('ordinal', '<', $checkpoint->ordinal)
                ->where('state', '!=', HistoricImportCheckpointState::Complete->value)
                ->exists();

            if ($priorIncomplete) {
                throw new RuntimeException('Historic import checkpoints must be admitted in immutable order.');
            }

            if ($operation->state === HistoricImportOperationState::Planned) {
                $operation->transitionTo(HistoricImportOperationState::Running);
            }

            if ($operation->state !== HistoricImportOperationState::Running) {
                throw new RuntimeException('Historic import operation is not admitting work.');
            }

            $checkpoint->runtime_fingerprint = $runtimeFingerprint;
            $checkpoint->admitted_at = now();
            $checkpoint->deadline_at = $operation->accepted_deadline;
            $checkpoint->save();
            $checkpoint->transitionTo(HistoricImportCheckpointState::Admitted);

            $this->journal->append($operation, 'checkpoint_admitted', [
                'checkpoint_key' => $checkpoint->checkpoint_key,
                'runtime_fingerprint' => $runtimeFingerprint,
                'deadline_at' => $checkpoint->deadline_at?->toIso8601String(),
            ], $checkpoint);
        });
    }

    public function beforeDispatch(HistoricImportCheckpoint $checkpoint, string $itemKey): string
    {
        $checkpoint->refresh();

        if ($checkpoint->deadline_at === null || $checkpoint->deadline_at->isPast()) {
            $this->recordAnomaly($checkpoint, 'checkpoint_timeout', ['item_key' => $itemKey]);

            throw new RuntimeException('Historic import checkpoint deadline elapsed; reconciliation is required.');
        }

        return DB::transaction(function () use ($checkpoint, $itemKey): string {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->assertRunnable($operation, $checkpoint, $itemKey);

            if ($this->dispatchEventExists($checkpoint, 'dispatch_started', $itemKey)) {
                throw new RuntimeException("Historic import item {$itemKey} already has a dispatch attempt requiring reconciliation.");
            }

            if ($checkpoint->state === HistoricImportCheckpointState::Admitted) {
                $checkpoint->transitionTo(HistoricImportCheckpointState::Running);
            }

            $deduplicationKey = CanonicalJson::hash([
                'contract_version' => 1,
                'operation_binding_hash' => $operation->binding_hash,
                'checkpoint_membership_hash' => $checkpoint->membership_hash,
                'item_key' => $itemKey,
            ]);

            $this->journal->append($operation, 'dispatch_started', [
                'item_key' => $itemKey,
                'deduplication_key' => $deduplicationKey,
            ], $checkpoint, HistoricImportDisposition::InFlight);

            return $deduplicationKey;
        });
    }

    public function afterDispatch(
        HistoricImportCheckpoint $checkpoint,
        string $itemKey,
        string $processingId,
        string $deduplicationKey,
    ): void {
        DB::transaction(function () use ($checkpoint, $itemKey, $processingId, $deduplicationKey): void {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->assertRunnable($operation, $checkpoint, $itemKey);

            if (! $this->dispatchEventExists($checkpoint, 'dispatch_started', $itemKey)
                || $this->dispatchEventExists($checkpoint, 'dispatch_accepted', $itemKey)) {
                throw new RuntimeException('Historic import dispatch acknowledgement does not match one unacknowledged pre-dispatch record.');
            }

            $this->journal->append($operation, 'dispatch_accepted', [
                'item_key' => $itemKey,
                'processing_id' => $processingId,
                'deduplication_key' => $deduplicationKey,
            ], $checkpoint, HistoricImportDisposition::InFlight);
        });
    }

    /**
     * @param  array<string, string>  $outputHashes
     */
    public function settle(
        HistoricImportCheckpoint $checkpoint,
        string $sourceKind,
        string $itemKey,
        HistoricImportItemExpectation $expectation,
        HistoricImportDisposition $disposition,
        ?string $approvedSourceSha256,
        ?string $observedSourceSha256,
        array $outputHashes = [],
        ?string $reasonCode = null,
        ?HistoricImportSourceSnapshot $sourceSnapshot = null,
    ): HistoricImportItemOutcome {
        if (! $disposition->isTerminal()) {
            throw new RuntimeException('Historic import item settlement requires a terminal disposition.');
        }

        return DB::transaction(function () use (
            $checkpoint,
            $sourceKind,
            $itemKey,
            $expectation,
            $disposition,
            $approvedSourceSha256,
            $observedSourceSha256,
            $outputHashes,
            $reasonCode,
            $sourceSnapshot,
        ): HistoricImportItemOutcome {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->assertMember($checkpoint, $itemKey);

            if ($sourceSnapshot !== null && $sourceSnapshot->historic_import_operation_id !== $operation->id) {
                throw new RuntimeException('Historic import outcome source snapshot belongs to another operation.');
            }

            $outcome = HistoricImportItemOutcome::query()->create([
                'historic_import_operation_id' => $operation->id,
                'historic_import_checkpoint_id' => $checkpoint->id,
                'historic_import_source_snapshot_id' => $sourceSnapshot?->id,
                'source_kind' => $sourceKind,
                'item_key' => $itemKey,
                'expectation' => $expectation,
                'disposition' => $disposition,
                'approved_source_sha256' => $approvedSourceSha256,
                'observed_source_sha256' => $observedSourceSha256,
                'output_hashes' => $outputHashes,
                'reason_code' => $reasonCode,
                'settled_at' => now(),
            ]);

            $this->journal->append($operation, 'item_settled', [
                'source_kind' => $sourceKind,
                'item_key' => $itemKey,
                'expectation' => $expectation->value,
                'output_hashes' => $outputHashes,
                'reason_code' => $reasonCode,
            ], $checkpoint, $disposition);

            if (! $disposition->satisfiesCloseout($expectation)) {
                $this->requireReconciliation($operation, $checkpoint, 'terminal_disposition', [
                    'item_key' => $itemKey,
                    'disposition' => $disposition->value,
                ]);
            }

            return $outcome;
        });
    }

    /** @param array<string, mixed> $facts */
    public function recordAnomaly(HistoricImportCheckpoint $checkpoint, string $reason, array $facts = []): void
    {
        DB::transaction(function () use ($checkpoint, $reason, $facts): void {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->requireReconciliation($operation, $checkpoint, $reason, $facts);
        });
    }

    /** @param list<string> $liveProcessingIds */
    public function resumeAfterReconciliation(
        HistoricImportCheckpoint $checkpoint,
        string $runtimeFingerprint,
        array $liveProcessingIds,
    ): void {
        DB::transaction(function () use ($checkpoint, $runtimeFingerprint, $liveProcessingIds): void {
            [$operation, $checkpoint] = $this->locked($checkpoint);
            $this->assertRuntimeBinding($operation, $runtimeFingerprint);

            if ($operation->state !== HistoricImportOperationState::ReconciliationRequired
                || $checkpoint->state !== HistoricImportCheckpointState::ReconciliationRequired) {
                throw new RuntimeException('Historic import resume requires an operation and checkpoint held for reconciliation.');
            }

            if ($liveProcessingIds !== []) {
                throw new RuntimeException('Historic import cannot resume while timed-out or interrupted work remains live.');
            }

            $operation->transitionTo(HistoricImportOperationState::Running);
            $checkpoint->last_reconciled_at = now();
            $checkpoint->save();
            $checkpoint->transitionTo(HistoricImportCheckpointState::Running);

            $this->journal->append($operation, 'checkpoint_reconciled', [
                'runtime_fingerprint' => $runtimeFingerprint,
                'live_processing_ids' => [],
            ], $checkpoint);
        });
    }

    public function complete(HistoricImportCheckpoint $checkpoint): void
    {
        DB::transaction(function () use ($checkpoint): void {
            [$operation, $checkpoint] = $this->locked($checkpoint);

            if ($operation->state !== HistoricImportOperationState::Running
                || $checkpoint->state !== HistoricImportCheckpointState::Running) {
                throw new RuntimeException('Historic import checkpoint is not eligible for completion.');
            }

            $outcomes = $checkpoint->itemOutcomes()->get()->keyBy('item_key');

            foreach ($checkpoint->item_keys as $itemKey) {
                $outcome = $outcomes->get($itemKey);

                if (! $outcome instanceof HistoricImportItemOutcome
                    || ! $outcome->disposition->satisfiesCloseout($outcome->expectation)) {
                    throw new RuntimeException("Historic import checkpoint item {$itemKey} lacks exact closeout evidence.");
                }
            }

            if ($outcomes->count() !== count($checkpoint->item_keys)) {
                throw new RuntimeException('Historic import checkpoint contains outcomes outside its immutable membership.');
            }

            $checkpoint->settled_at = now();
            $checkpoint->save();
            $checkpoint->transitionTo(HistoricImportCheckpointState::Complete);
            $this->journal->append($operation, 'checkpoint_completed', [
                'membership_hash' => $checkpoint->membership_hash,
                'item_count' => count($checkpoint->item_keys),
            ], $checkpoint);

            if (! $operation->checkpoints()->where('state', '!=', HistoricImportCheckpointState::Complete->value)->exists()) {
                $operation->transitionTo(HistoricImportOperationState::CloseoutRequired);
            }
        });
    }

    /** @return array{HistoricImportOperation, HistoricImportCheckpoint} */
    private function locked(HistoricImportCheckpoint $checkpoint): array
    {
        $lockedCheckpoint = HistoricImportCheckpoint::query()->whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();
        $operation = HistoricImportOperation::query()
            ->whereKey($lockedCheckpoint->historic_import_operation_id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$operation, $lockedCheckpoint];
    }

    private function assertRuntimeBinding(HistoricImportOperation $operation, string $runtimeFingerprint): void
    {
        if (! hash_equals($operation->runtime_fingerprint, $runtimeFingerprint)) {
            throw new RuntimeException('Historic import runtime fingerprint differs from the accepted operation binding.');
        }

        $this->journal->verify($operation);
    }

    private function assertRunnable(
        HistoricImportOperation $operation,
        HistoricImportCheckpoint $checkpoint,
        string $itemKey,
    ): void {
        if ($operation->state !== HistoricImportOperationState::Running
            || ! in_array($checkpoint->state, [HistoricImportCheckpointState::Admitted, HistoricImportCheckpointState::Running], true)) {
            throw new RuntimeException('Historic import checkpoint is not admitting dispatches.');
        }

        $this->assertMember($checkpoint, $itemKey);

        if ($checkpoint->itemOutcomes()->where('item_key', $itemKey)->exists()) {
            throw new RuntimeException("Historic import item {$itemKey} is already terminal.");
        }
    }

    private function assertMember(HistoricImportCheckpoint $checkpoint, string $itemKey): void
    {
        if (! in_array($itemKey, $checkpoint->item_keys, true)) {
            throw new RuntimeException("Historic import item {$itemKey} is outside checkpoint membership.");
        }
    }

    private function dispatchEventExists(HistoricImportCheckpoint $checkpoint, string $event, string $itemKey): bool
    {
        return $checkpoint->journalEntries()
            ->where('event', $event)
            ->get()
            ->contains(fn ($entry): bool => ($entry->payload['item_key'] ?? null) === $itemKey);
    }

    /** @param array<string, mixed> $facts */
    private function requireReconciliation(
        HistoricImportOperation $operation,
        HistoricImportCheckpoint $checkpoint,
        string $reason,
        array $facts,
    ): void {
        if (in_array($checkpoint->state, [
            HistoricImportCheckpointState::Planned,
            HistoricImportCheckpointState::Admitted,
            HistoricImportCheckpointState::Running,
        ], true)) {
            $checkpoint->transitionTo(HistoricImportCheckpointState::ReconciliationRequired);
        }

        if (in_array($operation->state, [HistoricImportOperationState::Planned, HistoricImportOperationState::Running], true)) {
            $operation->transitionTo(HistoricImportOperationState::ReconciliationRequired);
        }

        $this->journal->append($operation, 'reconciliation_required', [
            'reason' => $reason,
            'facts' => $facts,
        ], $checkpoint);
    }
}
