<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ChurchServiceProjection;
use App\Data\ChurchServiceSourceIngestionResult;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class IngestChurchServiceSourceRevision
{
    public function __construct(
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceProjectionPersister $persister,
    ) {}

    public function execute(
        ChurchService $churchService,
        ChurchServiceSourceRevision $revision,
    ): ChurchServiceSourceIngestionResult {
        $revisionHash = CanonicalJson::hash([
            'assertions' => $this->portableAssertions($revision->assertions),
            'service_content' => $revision->serviceContent,
        ]);

        try {
            return DB::transaction(function () use ($churchService, $revision, $revisionHash): ChurchServiceSourceIngestionResult {
                $lockedService = ChurchService::query()
                    ->whereKey($churchService->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = ChurchServiceSourceRecord::query()
                    ->where('source', $revision->source->value)
                    ->where('source_key', $revision->sourceKey)
                    ->where('revision_hash', $revisionHash)
                    ->first();

                if ($existing instanceof ChurchServiceSourceRecord) {
                    return new ChurchServiceSourceIngestionResult($existing, false);
                }

                $superseded = ChurchServiceSourceRecord::query()
                    ->whereBelongsTo($lockedService)
                    ->where('source', $revision->source->value)
                    ->where('source_key', $revision->sourceKey)
                    ->latest('id')
                    ->first();

                $sourceRecord = $lockedService->sourceRecords()->create([
                    'source' => $revision->source,
                    'source_key' => $revision->sourceKey,
                    'revision_hash' => $revisionHash,
                    'input_hash' => $revision->inputHash,
                    'supersedes_id' => $superseded?->id,
                    'batch_hash' => $revision->batchHash,
                    'processing_fingerprint' => $revision->processingFingerprint,
                    'service_content' => $revision->serviceContent,
                    'payload_complete' => $revision->payloadComplete,
                    'captured_at' => $revision->capturedAt ?? now(),
                    'created_by_user_id' => $revision->createdByUserId,
                ]);

                $sourceRecord->assertions()->createMany($revision->assertions);
                $this->dualWriteSourceEvidence($lockedService, $revision);
                $records = $lockedService->sourceRecords()
                    ->with(['assertions', 'assertions.sourceRecord'])
                    ->get();
                $normalizedSources = $records->pluck('source')->map->value->unique();
                $hasUnnormalizedLegacyItems = $lockedService->items()
                    ->whereNotNull('source')
                    ->whereNotIn('source', $normalizedSources)
                    ->exists();

                $projection = $this->projector->project($records);
                $stagingReasons = $this->stagingReasons(
                    $lockedService,
                    $revision,
                    $projection,
                    $hasUnnormalizedLegacyItems,
                );

                if ($stagingReasons !== []) {
                    $this->stageProposal($lockedService, $sourceRecord, $records, $stagingReasons);
                } else {
                    $this->persister->apply($lockedService, $projection);
                }

                return new ChurchServiceSourceIngestionResult(
                    $sourceRecord->load('assertions'),
                    true,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ChurchServiceSourceRecord::query()
                ->where('source', $revision->source->value)
                ->where('source_key', $revision->sourceKey)
                ->where('revision_hash', $revisionHash)
                ->first();

            if (! $existing instanceof ChurchServiceSourceRecord) {
                throw $exception;
            }

            return new ChurchServiceSourceIngestionResult($existing, false);
        }
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
     * @return list<array<string, mixed>>
     */
    private function stagingReasons(
        ChurchService $churchService,
        ChurchServiceSourceRevision $revision,
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

        if ($churchService->canonical_hash === $projection->hash) {
            return;
        }

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

    private function dualWriteSourceEvidence(
        ChurchService $churchService,
        ChurchServiceSourceRevision $revision,
    ): void {
        $assertionsByPosition = collect($revision->assertions)->keyBy('source_position');

        foreach ($churchService->items()->get() as $item) {
            $assertion = $assertionsByPosition->get($item->position);

            if (! is_array($assertion)) {
                continue;
            }

            $metadata = $item->metadata ?? [];
            $existingTitles = $metadata['source_evidence'][$revision->source->value]['titles'] ?? [];
            $titles = is_array($existingTitles) ? $existingTitles : [];
            $titles[] = $assertion['title'];

            if (is_string($assertion['source_title'] ?? null)) {
                $titles[] = $assertion['source_title'];
            }

            $metadata['source_evidence'][$revision->source->value] = [
                'titles' => array_values(array_unique(array_filter($titles, is_string(...)))),
            ];

            $item->forceFill(['metadata' => $metadata])->saveQuietly();
        }
    }
}
