<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ChurchServiceSourceIngestionResult;
use App\Data\ChurchServiceSourceRevision;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
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
            'assertions' => $revision->assertions,
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

                if (! $hasUnnormalizedLegacyItems) {
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
