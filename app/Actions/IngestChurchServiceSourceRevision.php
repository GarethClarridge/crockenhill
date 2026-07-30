<?php

declare(strict_types=1);

namespace App\Actions;

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

                $isMachineRevisionAfterReview = $revision->source !== ChurchServiceSource::Manual
                    && $lockedService->reviewed_canonical_revision !== null;
                $isStagedMachineRevision = $revision->source !== ChurchServiceSource::Manual
                    && $lockedService->pending_structure_merge_source !== null;

                if ($isMachineRevisionAfterReview || $isStagedMachineRevision) {
                    $this->stageProposal($lockedService, $sourceRecord, $records);
                } elseif (! $hasUnnormalizedLegacyItems) {
                    $this->persister->apply($lockedService, $this->projector->project($records));
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
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     */
    private function stageProposal(
        ChurchService $churchService,
        ChurchServiceSourceRecord $triggerSourceRecord,
        Collection $records,
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
            'field_decisions' => [],
            'conflicts' => [],
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
