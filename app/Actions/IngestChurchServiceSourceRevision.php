<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ChurchServiceProjection;
use App\Data\ChurchServiceSourceIngestionResult;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceSourceRevisionLineageInspector;
use App\Support\CanonicalJson;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IngestChurchServiceSourceRevision
{
    public function __construct(
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceProjectionPersister $persister,
        private readonly ChurchServiceSourceRevisionLineageInspector $lineageInspector,
    ) {}

    public function execute(
        ChurchService $churchService,
        ChurchServiceSourceRevision $revision,
        bool $project = true,
        bool $dispatchEvents = true,
    ): ChurchServiceSourceIngestionResult {
        $revisionHash = CanonicalJson::hash([
            'assertions' => $this->portableAssertions($revision->assertions),
            'service_content' => $revision->serviceContent,
            // Content equality does not make a source authority replay-safe. A
            // changed archive, batch or parser can yield identical assertions;
            // retain that immutable provenance as a new linked revision without
            // requiring the canonical projection itself to change.
            'input_hash' => $revision->inputHash,
            'batch_hash' => $revision->batchHash,
            'processing_fingerprint' => $revision->processingFingerprint,
            'payload_complete' => $revision->payloadComplete,
        ]);

        try {
            return DB::transaction(function () use ($churchService, $revision, $revisionHash, $project, $dispatchEvents): ChurchServiceSourceIngestionResult {
                $lockedService = ChurchService::query()
                    ->whereKey($churchService->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $claimedElsewhere = ChurchServiceSourceRecord::query()
                    ->where('source', $revision->source->value)
                    ->where('source_key_hash', ChurchServiceSourceKey::identity($revision->sourceKey))
                    ->where('church_service_id', '!=', $lockedService->getKey())
                    ->exists();

                if ($claimedElsewhere) {
                    throw new RuntimeException('A source revision key is already attached to a different church service.');
                }

                $serviceRevisions = $lockedService->sourceRecords()->lockForUpdate()->get();
                $this->lineageInspector->assertNoCrossLineageSupersession($serviceRevisions);

                $revisions = $serviceRevisions
                    ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === $revision->source
                        && $record->source_key === $revision->sourceKey)
                    ->values();
                // A manifest-authorised Email correction explicitly supersedes a record under a
                // different source key. The service-wide assertion above validates that edge;
                // within this key it is already-pruned ancestry, so a replay must resolve to its
                // current leaf rather than mistake the predecessor for foreign corruption.
                $superseded = $this->lineageInspector->activeLeaf($revisions, $revisions);

                if ($superseded?->revision_hash === $revisionHash) {
                    return new ChurchServiceSourceIngestionResult($superseded, false);
                }

                $this->assertPayloadIsNotSuperseded($revisions, $revisionHash);

                $manifestPredecessor = $this->resolveManifestPredecessor(
                    $lockedService,
                    $serviceRevisions,
                    $revision,
                );

                $sourceRecord = $lockedService->sourceRecords()->create([
                    'source' => $revision->source,
                    'source_key' => ChurchServiceSourceKey::canonical($revision->sourceKey),
                    'source_key_hash' => ChurchServiceSourceKey::identity($revision->sourceKey),
                    'revision_hash' => $revisionHash,
                    'input_hash' => $revision->inputHash,
                    'supersedes_id' => $manifestPredecessor === null ? $superseded?->id : $manifestPredecessor->id,
                    'batch_hash' => $revision->batchHash,
                    'processing_fingerprint' => $revision->processingFingerprint,
                    'service_content' => $revision->serviceContent,
                    'payload_complete' => $revision->payloadComplete,
                    'captured_at' => $revision->capturedAt ?? now(),
                    'created_by_user_id' => $revision->createdByUserId,
                ]);

                $sourceRecord->assertions()->createMany($revision->assertions);

                if (! $project) {
                    return new ChurchServiceSourceIngestionResult(
                        $sourceRecord->load('assertions'),
                        true,
                    );
                }

                $records = $lockedService->sourceRecords()
                    ->with(['assertions', 'assertions.sourceRecord'])
                    ->get();
                $hasUnnormalizedLegacyItems = $lockedService->items()
                    ->get(['id', 'metadata'])
                    ->contains(fn (ChurchServiceItem $item): bool => ! $item->hasNormalizedEvidence());

                $projection = $this->projector->project($records);
                $stagingReasons = $this->stagingReasons(
                    $lockedService,
                    $revision,
                    $records,
                    $projection,
                    $hasUnnormalizedLegacyItems,
                );

                if ($stagingReasons !== []) {
                    $this->stageProposal($lockedService, $sourceRecord, $records, $stagingReasons);
                } else {
                    $this->persister->apply(
                        $lockedService,
                        $projection,
                        $revision->source->value,
                        $dispatchEvents,
                    );
                }

                return new ChurchServiceSourceIngestionResult(
                    $sourceRecord->load('assertions'),
                    true,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent writer committed this exact payload between our read and
            // insert. Re-resolve the lineage so the outcome matches the in-transaction
            // path: the current leaf is an idempotent no-op, anything else is a revert.
            $revisions = ChurchServiceSourceRecord::query()
                ->where('source', $revision->source->value)
                ->where('source_key_hash', ChurchServiceSourceKey::identity($revision->sourceKey))
                ->get();
            $existing = $revisions->firstWhere('revision_hash', $revisionHash);

            if (! $existing instanceof ChurchServiceSourceRecord) {
                throw $exception;
            }

            if ($existing->church_service_id !== $churchService->id) {
                throw new RuntimeException('A source revision key is already attached to a different church service.', previous: $exception);
            }

            $leaf = $this->lineageInspector->activeLeaf($revisions, $revisions);

            if (! $existing->is($leaf)) {
                $this->assertPayloadIsNotSuperseded($revisions, $revisionHash);
            }

            return new ChurchServiceSourceIngestionResult($existing, false);
        }
    }

    /**
     * A curated Email correction names its predecessor by the portable source
     * key for one service plan. It never relies on a local database id or on
     * arrival order. Resolve it while the service is locked so a correction
     * cannot retire absent, ambiguous, or already-replaced evidence.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $serviceRevisions
     */
    private function resolveManifestPredecessor(
        ChurchService $churchService,
        Collection $serviceRevisions,
        ChurchServiceSourceRevision $revision,
    ): ?ChurchServiceSourceRecord {
        if ($revision->supersedesSourceKey === null) {
            return null;
        }

        $elsewhere = ChurchServiceSourceRecord::query()
            ->where('source', $revision->source->value)
            ->where('source_key_hash', ChurchServiceSourceKey::identity($revision->supersedesSourceKey))
            ->where('church_service_id', '!=', $churchService->getKey())
            ->exists();

        if ($elsewhere) {
            throw new RuntimeException('The declared Email predecessor belongs to a different church service.');
        }

        $predecessors = $serviceRevisions
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source === $revision->source
                && $record->source_key === $revision->supersedesSourceKey)
            ->values();

        if ($predecessors->isEmpty()) {
            throw new RuntimeException('The declared Email predecessor is absent from this church service.');
        }

        if ($predecessors->count() !== 1) {
            throw new RuntimeException('The declared Email predecessor is ambiguous because its source identity has multiple revisions.');
        }

        $predecessor = $predecessors->firstOrFail();
        $alreadySuperseded = $serviceRevisions->contains(
            fn (ChurchServiceSourceRecord $record): bool => $record->supersedes_id === $predecessor->id,
        );

        if ($alreadySuperseded) {
            throw new RuntimeException('The declared Email predecessor has already been superseded incompatibly.');
        }

        return $predecessor;
    }

    /**
     * Revision identity within a lineage is the payload hash, so a lineage cannot
     * express a revert to a payload it has already superseded. Refusing loudly
     * keeps the operator's options open; silently returning the superseded record
     * would drop the incoming evidence and leave canonical state on the
     * correction the source has just withdrawn.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $revisions
     */
    private function assertPayloadIsNotSuperseded(Collection $revisions, string $revisionHash): void
    {
        $superseded = $revisions->firstWhere('revision_hash', $revisionHash);

        if (! $superseded instanceof ChurchServiceSourceRecord) {
            return;
        }

        throw new RuntimeException(
            "This payload is identical to source revision {$superseded->id}, which has already been superseded in "
            .'this lineage. Record the intended content through a manual revision instead of replaying a withdrawn one.',
        );
    }

    /**
     * Database IDs are persisted for live relationships but cannot participate
     * in immutable source identity because bundles cross databases.
     *
     * @param  list<array<string, mixed>>  $assertions
     * @return list<array<string, mixed>>
     */
    private function portableAssertions(array $assertions): array
    {
        return array_map(function (array $assertion): array {
            unset($assertion['song_id']);

            if (is_array($assertion['metadata'] ?? null)) {
                unset(
                    $assertion['metadata']['livestream_service_section_id'],
                    $assertion['metadata']['oos_item_id'],
                );
            }

            return $assertion;
        }, $assertions);
    }

    /**
     * Every reason a machine revision must not write canonical rows on its own. An
     * empty list is the only licence to project directly; anything else has to reach a
     * reviewer, so no ingress may end without either applying or staging.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function stagingReasons(
        ChurchService $churchService,
        ChurchServiceSourceRevision $revision,
        Collection $records,
        ChurchServiceProjection $projection,
        bool $hasUnnormalizedLegacyItems,
    ): array {
        if ($revision->source === ChurchServiceSource::Manual) {
            return [];
        }

        $reasons = [];

        if ($churchService->reviewed_canonical_revision !== null) {
            $reasons[] = [
                'kind' => 'reviewed_service',
                'reason' => 'A person has reviewed this service, so later machine evidence stages for review instead of writing canonical items.',
            ];
        }

        if ($churchService->pending_structure_merge_source !== null) {
            $reasons[] = [
                'kind' => 'pending_structure_merge',
                'reason' => 'An earlier structure merge is still awaiting resolution.',
            ];
        }

        if ($hasUnnormalizedLegacyItems) {
            $reasons[] = [
                'kind' => 'unnormalized_legacy_items',
                'reason' => 'This service still holds legacy items from a source with no normalized evidence, so projecting would delete items no source can account for.',
            ];
        }

        if (! $this->projector->hasCompleteAudit($records, $projection)) {
            $reasons[] = [
                'kind' => 'incomplete_projection_audit',
                'reason' => 'The active evidence set does not have a complete portable projection audit, so canonical finalisation must wait for review.',
            ];
        }

        return [...$reasons, ...$projection->conflicts];
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @param  list<array<string, mixed>>  $stagingReasons
     */
    private function stageProposal(
        ChurchService $churchService,
        ChurchServiceSourceRecord $triggerSourceRecord,
        Collection $records,
        array $stagingReasons,
    ): void {
        $machineRecords = $records
            ->reject(fn (ChurchServiceSourceRecord $record): bool => $record->source === ChurchServiceSource::Manual)
            ->values();
        $projection = $this->projector->project($machineRecords);

        // Automatic finality must be retracted when machine evidence starts
        // staging. Manual finality is a human authority boundary: identical
        // evidence preserves it, while changed evidence stages a proposal
        // without silently erasing or superseding the reviewed decision.
        if ($churchService->canonical_finalization === ChurchServiceCanonicalFinalization::Automatic) {
            $churchService->forceFill(['canonical_finalization' => null])->saveQuietly();
        }

        if ($churchService->canonical_hash === $projection->hash && $projection->conflicts === []) {
            return;
        }

        $churchService->forceFill([
            'needs_review' => true,
            'review_reason' => 'projection_requires_review',
        ])->saveQuietly();

        ChurchServiceMergeProposal::query()
            ->whereBelongsTo($churchService)
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->update(['status' => ChurchServiceProposalStatus::Stale->value]);

        $churchService->mergeProposals()->create([
            'trigger_source_record_id' => $triggerSourceRecord->id,
            'base_canonical_revision' => $churchService->canonical_revision,
            'base_canonical_hash' => $churchService->canonical_hash,
            'included_source_hashes' => $machineRecords->pluck('revision_hash')->sort()->values()->all(),
            'proposed_items' => $projection->items,
            'proposed_hash' => $projection->hash,
            'field_decisions' => $projection->fieldDecisions,
            'conflicts' => $stagingReasons,
            'status' => ChurchServiceProposalStatus::Pending,
        ]);
    }
}
