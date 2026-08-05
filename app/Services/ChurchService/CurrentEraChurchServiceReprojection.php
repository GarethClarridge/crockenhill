<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceReviewState;
use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\Song;
use Illuminate\Support\Facades\DB;

class CurrentEraChurchServiceReprojection
{
    public function __construct(
        private readonly ChurchServiceCanonicalManifest $manifests,
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceProjectionPersister $persister,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reproject(ChurchService $churchService, bool $apply): array
    {
        return DB::transaction(function () use ($churchService, $apply): array {
            $service = ChurchService::query()
                ->with([
                    'items.song',
                    'sourceRecords.assertions',
                    'reviewSessions',
                ])
                ->whereKey($churchService->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $before = $this->manifests->build($service);
            $falseAcceptanceIds = $this->unprovenLegacyAcceptanceIds($service);
            $projection = null;
            $classification = HistoricImportClassification::Conflict;
            $reason = 'The service has no normalized source evidence to re-project.';
            $itemDifferences = [];
            $serviceDifferences = [];
            $prospective = null;

            if ($service->sourceRecords->isNotEmpty()) {
                $projection = $this->projector->project($service->sourceRecords);
                $prospective = $this->projectionManifest($service, $projection->items, $projection->serviceContent);
                $itemDifferences = $this->itemDifferences($before['items'], $prospective['items']);
                $serviceDifferences = $this->serviceDifferences($before, $prospective);

                if ($projection->conflicts !== []) {
                    $reason = 'The repaired projector found unresolved evidence conflicts.';
                } else {
                    [$classification, $reason] = $this->classify($before, $prospective, $itemDifferences, $serviceDifferences);
                }
            }

            $applied = false;

            if ($apply && $projection !== null && $projection->conflicts === []) {
                // The rows are known to have drifted from the projection that produced
                // the stored canonical hash, so the persister's hash short-circuit would
                // skip exactly the repairs this operation exists to make.
                $service = $this->persister->apply(
                    $service,
                    $projection,
                    dispatchEvents: false,
                    force: $itemDifferences !== [] || $serviceDifferences !== [],
                );
                $applied = true;
            }

            $reopened = $apply && $falseAcceptanceIds !== [];

            if ($reopened) {
                $this->reopenFalseAcceptances($service, $falseAcceptanceIds);
            }

            if ($applied || $reopened) {
                $service = $service->fresh(['items.song', 'reviewSessions']) ?? $service;
            }

            // The repair diff above answers "what does the repaired projector change?".
            // The residual diff answers "did persistence write exactly that?", so the
            // classification and the differences it was derived from never disagree.
            $residualItemDifferences = [];
            $residualServiceDifferences = [];

            if ($applied && $prospective !== null) {
                $after = $this->manifests->build($service);
                $residualItemDifferences = $this->itemDifferences($prospective['items'], $after['items']);
                $residualServiceDifferences = $this->serviceDifferences($prospective, $after);
            }

            return [
                'church_service_id' => $service->getKey(),
                'identity' => "{$service->date->toDateString()}|{$service->service->value}",
                'classification' => $classification->value,
                'reason' => $reason,
                'applied' => $applied,
                'projection_policy' => $projection?->policyFingerprint,
                'projection_conflict_count' => count($projection->conflicts ?? []),
                'item_differences' => $itemDifferences,
                'service_differences' => $serviceDifferences,
                'residual_item_differences' => $residualItemDifferences,
                'residual_service_differences' => $residualServiceDifferences,
                'b13_proposals_reopened' => $apply ? count($falseAcceptanceIds) : 0,
                'b13_proposals_pending_reopening' => $apply
                    ? count($this->unprovenLegacyAcceptanceIds($service))
                    : count($falseAcceptanceIds),
            ];
        });
    }

    /**
     * A legacy review session recorded only selected proposal IDs. Before B13 was
     * fixed, an omitted resolution was silently stored as accepted, so a completed
     * session with null dispositions cannot prove that its acceptance was authored.
     *
     * @return list<int>
     */
    private function unprovenLegacyAcceptanceIds(ChurchService $service): array
    {
        $legacyIncludedIds = $service->reviewSessions
            ->filter(fn (ChurchServiceReviewSession $session): bool => $session->completed_at !== null
                && $session->proposal_dispositions === null)
            ->flatMap(fn (ChurchServiceReviewSession $session): array => $session->included_proposal_ids ?? [])
            ->map(static fn (mixed $proposalId): int => (int) $proposalId)
            ->unique()
            ->values();

        // A proposal a reviewer dispositioned explicitly once B13 was fixed is authored
        // evidence, even when an earlier legacy session also named it. Reversing those
        // would destroy the very human decisions this reversal exists to protect.
        $authoredIds = $service->reviewSessions
            ->flatMap(fn (ChurchServiceReviewSession $session): array => $session->proposal_dispositions ?? [])
            ->map(static fn (array $disposition): int => (int) $disposition['proposal_id'])
            ->unique()
            ->all();

        $candidateIds = $legacyIncludedIds
            ->reject(fn (int $proposalId): bool => in_array($proposalId, $authoredIds, true))
            ->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        return array_values(ChurchServiceMergeProposal::query()
            ->whereBelongsTo($service)
            ->whereIn('id', $candidateIds)
            ->where('status', ChurchServiceProposalStatus::Accepted)
            ->whereNull('decision_rule_id')
            ->pluck('id')
            ->map(static fn (mixed $proposalId): int => (int) $proposalId)
            ->all());
    }

    /**
     * @param  list<int>  $proposalIds
     */
    private function reopenFalseAcceptances(ChurchService $service, array $proposalIds): void
    {
        ChurchServiceMergeProposal::query()
            ->whereBelongsTo($service)
            ->whereIn('id', $proposalIds)
            ->update([
                'status' => ChurchServiceProposalStatus::Pending->value,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
                'decision_rule_id' => null,
            ]);

        // Legacy reviews recorded no manual_reviewed_at, and the review_state check
        // constraint requires a completed manual review behind every reopening. A
        // service nobody manually reviewed cannot be reopened, so it returns to the
        // inbox through needs_review alone and its state stays not_reviewed.
        $reopening = $service->manual_reviewed_at === null ? [] : [
            'review_state' => ChurchServiceReviewState::Reopened,
            'manual_review_reopened_at' => now(),
            'manual_review_reopened_by_source' => 'current_era_reprojection',
        ];

        $service->forceFill([
            'needs_review' => true,
            'review_reason' => 'pending_evidence_review',
            'reviewed_canonical_revision' => null,
            'canonical_finalization' => null,
            ...$reopening,
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<array<string, mixed>>  $itemDifferences
     * @param  list<array<string, mixed>>  $serviceDifferences
     * @return array{HistoricImportClassification, string}
     */
    private function classify(array $before, array $after, array $itemDifferences, array $serviceDifferences): array
    {
        if ($itemDifferences === [] && $serviceDifferences === []) {
            return [HistoricImportClassification::AlreadyPresent, 'The current canonical result already exactly matches the repaired projector.'];
        }

        if ($before['items'] === []) {
            return [HistoricImportClassification::Create, 'The service has normalized evidence but no existing canonical occurrences.'];
        }

        if ($this->isSafeEnrichment($itemDifferences, $serviceDifferences)) {
            return [HistoricImportClassification::SafeEnrichment, 'The repaired projector only fills missing durable canonical data.'];
        }

        return [HistoricImportClassification::BlockedDifference, 'The repaired projector changes existing canonical data and requires the explicit --apply operation.'];
    }

    /**
     * @param  list<array<string, mixed>>  $itemDifferences
     * @param  list<array<string, mixed>>  $serviceDifferences
     */
    private function isSafeEnrichment(array $itemDifferences, array $serviceDifferences): bool
    {
        foreach ($itemDifferences as $difference) {
            if ($difference['type'] === 'removed_item') {
                return false;
            }

            if ($difference['type'] === 'updated_item') {
                foreach ($difference['fields'] as $field) {
                    if (! $this->isMissing($field['before']) || $this->isMissing($field['after'])) {
                        return false;
                    }
                }
            }
        }

        foreach ($serviceDifferences as $difference) {
            if (! $this->isMissing($difference['before']) || $this->isMissing($difference['after'])) {
                return false;
            }
        }

        return true;
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $serviceContent
     * @return array<string, mixed>
     */
    private function projectionManifest(ChurchService $service, array $items, array $serviceContent): array
    {
        $canonicalKeys = $this->songCanonicalKeys($items);

        return [
            'date' => $service->date->toDateString(),
            'service' => $service->service->value,
            'summary' => $serviceContent['summary'],
            'notices' => $serviceContent['notices'] ?? [],
            'chapter_markers' => $serviceContent['chapter_markers'] ?? [],
            'items' => array_map(fn (array $item): array => [
                'position' => $item['position'],
                'canonical_identity' => $item['canonical_identity'],
                'type' => $item['type'],
                'section_type' => $item['section_type'],
                'source' => $item['source'],
                'title' => $item['title'],
                'source_title' => $item['source_title'],
                'song_canonical_key' => $canonicalKeys[$item['song_id']] ?? null,
                'occurrence_state' => $item['occurrence_state'],
                'manual_occurrence_decision' => $item['manual_occurrence_decision'],
                'source_assertion_hashes' => $item['metadata']['source_assertion_hashes'] ?? [],
            ], $items),
        ];
    }

    /**
     * The projector carries the authoritative song as a database identifier, while the
     * persisted manifest reads the catalogue's portable canonical key. Resolving one to
     * the other keeps both sides of the diff describing the same field, instead of
     * reporting every song item as a change to null.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function songCanonicalKeys(array $items): array
    {
        $songIds = array_values(array_unique(array_filter(
            array_column($items, 'song_id'),
            static fn (mixed $songId): bool => is_int($songId),
        )));

        if ($songIds === []) {
            return [];
        }

        /** @var array<int, string> $canonicalKeys */
        $canonicalKeys = Song::query()
            ->whereIn('id', $songIds)
            ->pluck('canonical_key', 'id')
            ->all();

        return $canonicalKeys;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private function itemDifferences(array $before, array $after): array
    {
        $beforeByIdentity = collect($before)->keyBy('canonical_identity');
        $afterByIdentity = collect($after)->keyBy('canonical_identity');
        $differences = [];

        foreach ($afterByIdentity as $identity => $afterItem) {
            $beforeItem = $beforeByIdentity->get($identity);

            if (! is_array($beforeItem)) {
                $differences[] = ['type' => 'added_item', 'canonical_identity' => $identity, 'after' => $afterItem];

                continue;
            }

            $fields = $this->fieldDifferences($beforeItem, $afterItem);

            if ($fields !== []) {
                $differences[] = [
                    'type' => 'updated_item',
                    'canonical_identity' => $identity,
                    'fields' => $fields,
                ];
            }
        }

        foreach ($beforeByIdentity as $identity => $beforeItem) {
            if (! $afterByIdentity->has($identity)) {
                $differences[] = ['type' => 'removed_item', 'canonical_identity' => $identity, 'before' => $beforeItem];
            }
        }

        usort($differences, fn (array $left, array $right): int => strcmp((string) $left['canonical_identity'], (string) $right['canonical_identity']));

        return $differences;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    private function serviceDifferences(array $before, array $after): array
    {
        return $this->fieldDifferences($before, $after, ['summary', 'notices', 'chapter_markers']);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>|null  $fields
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    private function fieldDifferences(array $before, array $after, ?array $fields = null): array
    {
        $fields ??= [
            'position',
            'type',
            'section_type',
            'source',
            'title',
            'source_title',
            'song_canonical_key',
            'occurrence_state',
            'manual_occurrence_decision',
            'source_assertion_hashes',
        ];

        $differences = [];

        foreach ($fields as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }

            $differences[] = [
                'field' => $field,
                'before' => $before[$field] ?? null,
                'after' => $after[$field] ?? null,
            ];
        }

        return $differences;
    }
}
