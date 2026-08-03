<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceReviewState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChurchServiceConvergenceBackfillService
{
    public function __construct(
        private ChurchServiceAssertionNormalizer $assertionNormalizer,
        private ChurchServiceProjector $projector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function backfill(ChurchService $churchService, bool $shadowOnly = false): array
    {
        return DB::transaction(function () use ($churchService, $shadowOnly): array {
            $service = ChurchService::query()
                ->whereKey($churchService->getKey())
                ->with(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')])
                ->lockForUpdate()
                ->firstOrFail();

            $expectedAssertions = $this->backfillLegacyEvidence($service);
            $proposalBackfill = $this->backfillLegacyProposals($service);
            $expectedAssertions += $proposalBackfill['assertions'];

            if (! $shadowOnly) {
                $this->initializeCanonicalState($service);
            }

            $records = $service->sourceRecords()->with(['assertions.sourceRecord'])->get();
            $legacyRecords = $records->filter(
                fn (ChurchServiceSourceRecord $record): bool => ($record->processing_fingerprint['format'] ?? null) === 'legacy-wp6-backfill-v1',
            );
            $projection = $records->isEmpty() ? null : $this->projector->project($records);
            $canonicalItems = $this->canonicalItems($service->fresh('items') ?? $service);
            $differences = $projection === null
                ? [['explanation' => 'no_normalized_source_evidence', 'canonical' => $canonicalItems, 'projected' => []]]
                : $this->projectionDifferences($canonicalItems, $service, $projection->items, $projection->serviceContent);

            return [
                'church_service_id' => $service->id,
                'expected_proposals' => $proposalBackfill['proposals'],
                'normalized_proposals' => $service->mergeProposals()
                    ->whereIn('trigger_source_record_id', $legacyRecords->pluck('id'))
                    ->count(),
                'expected_assertions' => $expectedAssertions,
                'normalized_assertions' => $legacyRecords->sum(fn (ChurchServiceSourceRecord $record): int => $record->assertions->count()),
                'duplicate_source_records' => $this->duplicateSourceRecordCount($service),
                'differences' => $differences,
            ];
        });
    }

    private function backfillLegacyEvidence(ChurchService $service): int
    {
        $assertionsBySource = [];

        foreach ($service->items as $item) {
            $sourceEvidence = $item->metadata['source_evidence'] ?? null;

            if (! is_array($sourceEvidence)) {
                continue;
            }

            foreach ($sourceEvidence as $sourceValue => $evidence) {
                $source = ChurchServiceSource::tryFrom((string) $sourceValue);

                if (! $source instanceof ChurchServiceSource || $source === ChurchServiceSource::Manual) {
                    continue;
                }

                $titles = is_array($evidence) && is_array($evidence['titles'] ?? null)
                    ? array_values(array_filter($evidence['titles'], 'is_string'))
                    : [];

                foreach ($titles as $titleIndex => $title) {
                    $assertionsBySource[$source->value][] = $this->legacyEvidenceAssertion(
                        $item,
                        $source,
                        $title,
                        $titleIndex,
                    );
                }
            }
        }

        foreach ($assertionsBySource as $sourceValue => $assertions) {
            $source = ChurchServiceSource::from($sourceValue);
            $this->createLegacySourceRecord(
                service: $service,
                source: $source,
                sourceKey: "legacy-evidence:{$service->id}:{$source->value}",
                assertions: $assertions,
                capturedAt: $service->updated_at,
                payloadComplete: false,
            );
        }

        return collect($assertionsBySource)->sum(fn (array $assertions): int => count($assertions));
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyEvidenceAssertion(
        ChurchServiceItem $item,
        ChurchServiceSource $source,
        string $title,
        int $titleIndex,
    ): array {
        $evidenceKind = $source === ChurchServiceSource::Livestream
            ? ChurchServiceEvidenceKind::Observed
            : ChurchServiceEvidenceKind::Planned;

        return $this->assertionNormalizer->normalize([[
            'assertion_key' => "legacy-item:{$item->id}:{$titleIndex}",
            'position' => $item->position,
            'type' => $item->type,
            'title' => $title,
        ]], $evidenceKind)[0];
    }

    /**
     * @return array{proposals: int, assertions: int}
     */
    private function backfillLegacyProposals(ChurchService $service): array
    {
        $pending = $service->import_metadata?->toArray()['pending_structure_merge'] ?? null;

        if (! is_array($pending)) {
            return ['proposals' => 0, 'assertions' => 0];
        }

        $superseded = is_array($pending['superseded_proposals'] ?? null)
            ? array_values(array_filter($pending['superseded_proposals'], 'is_array'))
            : [];
        unset($pending['superseded_proposals']);

        $assertionCount = 0;

        foreach ([...$superseded, $pending] as $index => $legacyProposal) {
            $sourceValue = $legacyProposal['incoming_source']
                ?? ($index === count($superseded) ? $service->pending_structure_merge_source : null);
            $source = ChurchServiceSource::tryFrom(is_string($sourceValue) ? $sourceValue : '')
                ?? ChurchServiceSource::Livestream;
            $proposedItems = is_array($legacyProposal['proposed_items'] ?? null)
                ? array_values(array_filter($legacyProposal['proposed_items'], 'is_array'))
                : [];
            $assertions = $this->assertionNormalizer->normalize(
                $proposedItems,
                $source === ChurchServiceSource::Livestream
                    ? ChurchServiceEvidenceKind::Observed
                    : ChurchServiceEvidenceKind::Planned,
            );
            $assertionCount += count($assertions);
            $legacyHash = CanonicalJson::hash($legacyProposal);
            $record = $this->createLegacySourceRecord(
                service: $service,
                source: $source,
                sourceKey: "legacy-proposal:{$service->id}:{$legacyHash}",
                assertions: $assertions,
                capturedAt: $this->capturedAt($legacyProposal, $service),
                payloadComplete: false,
            );
            $proposedHash = CanonicalJson::hash($proposedItems);

            ChurchServiceMergeProposal::query()->firstOrCreate(
                [
                    'church_service_id' => $service->id,
                    'trigger_source_record_id' => $record->id,
                    'proposed_hash' => $proposedHash,
                ],
                [
                    'base_canonical_revision' => $service->canonical_revision,
                    'base_canonical_hash' => $service->canonical_hash,
                    'included_source_hashes' => [$record->revision_hash],
                    'proposed_items' => $proposedItems,
                    'field_decisions' => [],
                    'conflicts' => $this->legacyConflicts($legacyProposal),
                    'status' => ChurchServiceProposalStatus::Pending,
                ],
            );
        }

        return [
            'proposals' => count($superseded) + 1,
            'assertions' => $assertionCount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assertions
     */
    private function createLegacySourceRecord(
        ChurchService $service,
        ChurchServiceSource $source,
        string $sourceKey,
        array $assertions,
        ?Carbon $capturedAt,
        bool $payloadComplete,
    ): ChurchServiceSourceRecord {
        $revisionHash = CanonicalJson::hash($assertions);
        $record = ChurchServiceSourceRecord::query()->firstOrCreate(
            [
                'source' => $source,
                'source_key' => $sourceKey,
                'revision_hash' => $revisionHash,
            ],
            [
                'church_service_id' => $service->id,
                'input_hash' => CanonicalJson::hash(['source_key' => $sourceKey, 'assertions' => $assertions]),
                'processing_fingerprint' => ['format' => 'legacy-wp6-backfill-v1'],
                'payload_complete' => $payloadComplete,
                'captured_at' => $capturedAt ?? $service->updated_at ?? now(),
            ],
        );

        if ($record->assertions()->doesntExist()) {
            $record->assertions()->createMany($assertions);
        }

        return $record;
    }

    private function initializeCanonicalState(ChurchService $service): void
    {
        foreach ($service->items as $item) {
            $item->forceFill([
                'canonical_identity' => $item->canonical_identity ?? $this->legacyCanonicalIdentity($item),
                'occurrence_state' => $this->occurrenceState($item),
            ])->saveQuietly();
        }

        $service->refresh()->load(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')]);
        $revision = max(1, $service->canonical_revision);
        $hash = CanonicalJson::hash([
            'items' => $this->canonicalItems($service),
            'service_content' => $this->serviceContent($service),
        ]);
        $reviewed = $this->provesCompletedManualReview($service) ? $revision : null;

        $service->forceFill([
            'canonical_revision' => $revision,
            'canonical_hash' => $hash,
            'reviewed_canonical_revision' => $service->reviewed_canonical_revision ?? $reviewed,
            'source_summary' => $service->source_summary ?? $this->sourceSummary($service),
        ])->saveQuietly();
    }

    /**
     * A projected item already carries its occurrence state as a column, so trust
     * that over any reconstruction. Only legacy rows — written before the
     * projector existed — need their state inferred from provenance metadata.
     */
    private function occurrenceState(ChurchServiceItem $item): ChurchServiceOccurrenceState
    {
        if ($item->occurrence_state instanceof ChurchServiceOccurrenceState) {
            return $item->occurrence_state;
        }

        if ($item->manual_occurrence_decision !== null || $item->source?->value === ChurchServiceSource::Manual->value) {
            return ChurchServiceOccurrenceState::ManuallyConfirmed;
        }

        $sources = $this->legacyProvenanceSources($item);
        $planned = in_array(ChurchServiceSource::Email->value, $sources, true)
            || in_array(ChurchServiceSource::OpenLp->value, $sources, true);
        $observed = in_array(ChurchServiceSource::Livestream->value, $sources, true);

        return match (true) {
            $planned && $observed => ChurchServiceOccurrenceState::PlannedAndObserved,
            $observed => ChurchServiceOccurrenceState::ObservedOnly,
            default => ChurchServiceOccurrenceState::PlannedOnly,
        };
    }

    /**
     * WP1 replaced the per-source `source_evidence` bag with a flat list of the
     * sources that asserted the item. Read both so the auditor does not report a
     * difference on every item the new projector has written.
     *
     * @return list<string>
     */
    private function legacyProvenanceSources(ChurchServiceItem $item): array
    {
        $assertionSources = $item->metadata['source_assertion_sources'] ?? null;

        if (is_array($assertionSources)) {
            return array_values(array_filter($assertionSources, is_string(...)));
        }

        $evidence = $item->metadata['source_evidence'] ?? null;

        return is_array($evidence) ? array_map(strval(...), array_keys($evidence)) : [];
    }

    private function provesCompletedManualReview(ChurchService $service): bool
    {
        return $service->review_state === ChurchServiceReviewState::Reviewed
            && $service->manual_reviewed_at !== null
            && $service->manual_review_reopened_at === null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalItems(ChurchService $service): array
    {
        return array_values($service->items
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (ChurchServiceItem $item): array => [
                'canonical_identity' => $item->canonical_identity ?? $this->legacyCanonicalIdentity($item),
                'type' => $item->type,
                'section_type' => $item->section_type?->value,
                'source' => $item->source?->value,
                'title' => $item->title,
                'source_title' => $item->source_title,
                'openlp_search_title' => $item->openlp_search_title,
                'song_id' => $item->song_id,
                'occurrence_state' => $this->occurrenceState($item)->value,
                'manual_occurrence_decision' => $item->manual_occurrence_decision,
                'livestream_processing_id' => $item->livestream_processing_id,
                'livestream_service_section_id' => $item->livestream_service_section_id,
                'metadata' => $this->canonicalMetadata($item->metadata),
                'position' => $item->position,
            ])
            ->all());
    }

    private function legacyCanonicalIdentity(ChurchServiceItem $item): string
    {
        if ($item->song_id !== null) {
            return "song-id:{$item->song_id}";
        }

        $title = $item->source_title ?? $item->title;

        return Str::of("{$item->type}:{$title}")->lower()->squish()->value();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function canonicalMetadata(?array $metadata): array
    {
        $metadata ??= [];
        unset($metadata['source_evidence'], $metadata['source_assertion_hashes']);

        return $metadata;
    }

    /**
     * @return array{summary: mixed, notices: mixed, chapter_markers: mixed}
     */
    private function serviceContent(ChurchService $service): array
    {
        return [
            'summary' => $service->summary,
            'notices' => $service->notices,
            'chapter_markers' => $service->chapter_markers,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $canonicalItems
     * @param  list<array<string, mixed>>  $projectedItems
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $projectedContent
     * @return list<array<string, mixed>>
     */
    private function projectionDifferences(
        array $canonicalItems,
        ChurchService $service,
        array $projectedItems,
        array $projectedContent,
    ): array {
        $differences = [];

        if (CanonicalJson::hash($canonicalItems) !== CanonicalJson::hash($projectedItems)) {
            $differences[] = [
                'explanation' => 'canonical_and_projected_items_differ',
                'canonical' => $canonicalItems,
                'projected' => $projectedItems,
            ];
        }

        $canonicalContent = $this->serviceContent($service);

        if (CanonicalJson::hash($canonicalContent) !== CanonicalJson::hash($projectedContent)) {
            $differences[] = [
                'explanation' => 'canonical_and_projected_service_content_differ',
                'canonical' => $canonicalContent,
                'projected' => $projectedContent,
            ];
        }

        return $differences;
    }

    private function duplicateSourceRecordCount(ChurchService $service): int
    {
        return $service->sourceRecords()
            ->select(['source', 'source_key', 'revision_hash'])
            ->get()
            ->groupBy(fn (ChurchServiceSourceRecord $record): string => "{$record->source->value}\0{$record->source_key}\0{$record->revision_hash}")
            ->sum(fn ($records): int => max(0, $records->count() - 1));
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function capturedAt(array $proposal, ChurchService $service): ?Carbon
    {
        $value = $proposal['created_at'] ?? $proposal['superseded_at'] ?? null;

        return is_string($value) ? Carbon::parse($value) : $service->updated_at;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<array<string, mixed>>
     */
    private function legacyConflicts(array $proposal): array
    {
        $conflicts = is_array($proposal['conflicts'] ?? null)
            ? array_values(array_filter($proposal['conflicts'], 'is_array'))
            : [];

        if ($conflicts !== []) {
            return $conflicts;
        }

        return [[
            'reason' => 'ambiguous_legacy_proposal',
            'explanation' => 'Legacy proposal did not record a complete deterministic match decision.',
        ]];
    }

    private function sourceSummary(ChurchService $service): string
    {
        $sources = $service->sourceRecords()
            ->where('source', '!=', ChurchServiceSource::Manual)
            ->distinct()
            ->pluck('source');

        if ($sources->count() === 1) {
            return (string) $sources->first();
        }

        return $sources->isEmpty() ? $service->source : 'mixed';
    }
}
