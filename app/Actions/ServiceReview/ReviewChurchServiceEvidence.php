<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceEvidenceReviewResult;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceReviewState;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\ChurchServiceSourceRecord;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\SourceAdapters\ManualSourceAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReviewChurchServiceEvidence
{
    public function __construct(
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ManualSourceAdapter $manualSourceAdapter,
        private readonly ChurchServiceProjector $projector,
    ) {}

    /**
     * @param  list<int>  $proposalIds
     * @param  array<int|string, string|null>  $resolutions
     * @param  list<array<string, mixed>>  $manualItems  Includes both included and excluded review decisions.
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $serviceContent
     * @param  int|null  $decisionRuleId  Set when one authoring act dispositioned this service's
     *                                    proposals alongside others in the same class.
     */
    public function execute(
        ChurchService $churchService,
        array $proposalIds,
        array $resolutions,
        array $manualItems,
        array $serviceContent,
        int $userId,
        int $expectedCanonicalRevision,
        ?string $expectedCanonicalHash,
        ?int $decisionRuleId = null,
    ): ChurchServiceEvidenceReviewResult {
        return DB::transaction(function () use (
            $churchService,
            $proposalIds,
            $resolutions,
            $manualItems,
            $serviceContent,
            $userId,
            $expectedCanonicalRevision,
            $expectedCanonicalHash,
            $decisionRuleId,
        ): ChurchServiceEvidenceReviewResult {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedService->canonical_revision !== $expectedCanonicalRevision
                || $lockedService->canonical_hash !== $expectedCanonicalHash
            ) {
                return new ChurchServiceEvidenceReviewResult(
                    $lockedService,
                    false,
                    'This service changed since you opened it. Reload the page before reviewing the evidence.',
                );
            }

            $proposalIds = collect($proposalIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $activeProposals = ChurchServiceMergeProposal::query()
                ->whereBelongsTo($lockedService)
                ->where('status', '!=', ChurchServiceProposalStatus::Stale)
                ->with('decisionRule')
                ->lockForUpdate()
                ->get();
            $proposals = $activeProposals
                ->whereIn('id', $proposalIds)
                ->where('status', ChurchServiceProposalStatus::Pending)
                ->values();

            // An empty selection is legitimate: a service can reach review with no
            // proposal at all, and its manual revision must still be recordable. Only
            // a selection naming a proposal that is no longer pending is a stale read.
            if ($proposals->count() !== count($proposalIds)) {
                return new ChurchServiceEvidenceReviewResult(
                    $lockedService,
                    false,
                    'One or more selected proposals are no longer available. Reload the page before reviewing.',
                );
            }

            $activeSourceRecords = $this->projector->activeSourceRecords(
                $lockedService->sourceRecords()->get(),
            );
            $activeSourceRecordIds = $activeSourceRecords
                ->map(fn (ChurchServiceSourceRecord $record): int => (int) $record->getKey())
                ->all();
            $assertionIds = [];
            $songIds = [];

            foreach ($manualItems as $item) {
                if (is_numeric($item['selected_assertion_id'] ?? null)) {
                    $assertionIds[] = (int) $item['selected_assertion_id'];
                }

                if (is_numeric($item['song_id'] ?? null)) {
                    $songIds[] = (int) $item['song_id'];
                }
            }

            $assertionIds = array_values(array_unique($assertionIds));
            $songIds = array_values(array_unique($songIds));

            if (
                ($assertionIds !== [] && ChurchServiceItemAssertion::query()
                    ->whereIn('id', $assertionIds)
                    ->whereIn('source_record_id', $activeSourceRecordIds)
                    ->count() !== count($assertionIds))
                || ($songIds !== [] && Song::query()->whereIn('id', $songIds)->count() !== count($songIds))
            ) {
                return new ChurchServiceEvidenceReviewResult(
                    $lockedService,
                    false,
                    'One or more selected assertions or songs do not belong to the active evidence for this service.',
                );
            }

            $proposalDispositions = [];

            foreach ($proposals as $proposal) {
                $resolution = $this->resolutionFor($resolutions, (int) $proposal->getKey());

                if ($resolution === null) {
                    return new ChurchServiceEvidenceReviewResult(
                        $lockedService,
                        false,
                        'Every selected proposal needs an explicit accept, reject or replace decision.',
                    );
                }

                $proposalDispositions[] = [
                    'proposal_id' => $proposal->getKey(),
                    'disposition' => $resolution,
                    'rationale' => $this->rationaleFor($resolution),
                ];
            }

            foreach ($activeProposals->where('status', '!=', ChurchServiceProposalStatus::Pending) as $proposal) {
                if ($proposal->resolved_by_user_id === null || $proposal->resolved_at === null) {
                    return new ChurchServiceEvidenceReviewResult(
                        $lockedService,
                        false,
                        'An active proposal has no recorded resolver. Resolve the proposal again before reviewing this service.',
                    );
                }

                $decisionRule = $proposal->decisionRule;
                $proposalDispositions[] = [
                    'proposal_id' => $proposal->getKey(),
                    'disposition' => $proposal->status->value,
                    'rationale' => $decisionRule instanceof ChurchServiceProposalDecisionRule
                        ? $decisionRule->rationale
                        : 'Previously resolved explicitly.',
                ];
            }

            $reviewedProposalIds = collect($proposalDispositions)
                ->pluck('proposal_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $reviewUuid = (string) Str::uuid();
            $reviewSession = $lockedService->reviewSessions()->create([
                'review_uuid' => $reviewUuid,
                'base_canonical_revision' => $lockedService->canonical_revision,
                'base_canonical_hash' => $lockedService->canonical_hash,
                'included_proposal_ids' => $reviewedProposalIds,
                'proposal_dispositions' => $proposalDispositions,
                'service_field_decisions' => $serviceContent,
                'reviewed_by_user_id' => $userId,
            ]);

            $includedManualItems = array_values(array_filter(
                $manualItems,
                static fn (array $item): bool => (bool) ($item['included'] ?? true),
            ));

            $ingestion = $this->ingestSourceRevision->execute(
                $lockedService,
                $this->manualSourceAdapter->adapt(
                    $includedManualItems,
                    $userId,
                    $serviceContent,
                    $reviewUuid,
                ),
            );

            $includedPosition = 0;

            foreach ($manualItems as $item) {
                $included = (bool) ($item['included'] ?? true);

                if ($included) {
                    $includedPosition++;
                }

                $reviewSession->decisions()->create([
                    'selected_assertion_id' => is_numeric($item['selected_assertion_id'] ?? null)
                        ? (int) $item['selected_assertion_id']
                        : null,
                    'included' => $included,
                    'final_position' => $included ? $includedPosition : null,
                    'custom_value' => [
                        'type' => $item['type'] ?? 'custom',
                        'section_type' => $item['section_type'] ?? null,
                        'title' => $item['title'] ?? '',
                        'source_title' => $item['source_title'] ?? null,
                    ],
                    'song_id' => is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null,
                    'song_canonical_key' => $item['song_canonical_key'] ?? null,
                    'scripture_reference' => $item['scripture_reference'] ?? null,
                    'occurrence_decision' => $item['occurrence_state'] ?? null,
                    'rationale' => filled($item['rationale'] ?? null)
                        ? trim((string) $item['rationale'])
                        : ($included
                            ? 'Included through the multi-source service workbench.'
                            : 'Excluded from the Manual canonical revision.'),
                ]);
            }

            foreach ($proposals as $proposal) {
                $proposal->forceFill([
                    'status' => $this->statusForDisposition(
                        $this->resolutionFor($resolutions, (int) $proposal->getKey())
                    ),
                    'resolved_by_user_id' => $userId,
                    'resolved_at' => now(),
                    'decision_rule_id' => $decisionRuleId,
                ])->save();
            }

            $freshService = $lockedService->fresh() ?? $lockedService;
            $hasPendingProposals = $freshService->mergeProposals()
                ->where('status', ChurchServiceProposalStatus::Pending->value)
                ->exists();
            $isBlockedByLegacyMerge = $freshService->pending_structure_merge_source !== null;
            $hadCompletedReview = $freshService->manual_reviewed_at !== null;
            $isPartialReview = $hasPendingProposals || $isBlockedByLegacyMerge;

            $freshService->forceFill([
                'reviewed_canonical_revision' => $isPartialReview
                    ? null
                    : $freshService->canonical_revision,
                'source_summary' => ChurchServiceItemSource::Manual->value,
                'source' => ChurchServiceItemSource::Manual->value,
                'needs_review' => $isPartialReview,
                'review_reason' => $isPartialReview
                    ? 'pending_evidence_review'
                    : null,
                'canonical_finalization' => $isPartialReview
                    ? null
                    : ChurchServiceCanonicalFinalization::Manual,
                'review_state' => $isPartialReview
                    ? ($hadCompletedReview ? ChurchServiceReviewState::Reopened : ChurchServiceReviewState::NotReviewed)
                    : ChurchServiceReviewState::Reviewed,
                'manual_reviewed_at' => $isPartialReview
                    ? $freshService->manual_reviewed_at
                    : now(),
                'manual_reviewed_by_user_id' => $isPartialReview
                    ? $freshService->manual_reviewed_by_user_id
                    : $userId,
                'manual_review_reopened_at' => $isPartialReview && $hadCompletedReview
                    ? now()
                    : null,
                'manual_review_reopened_by_source' => $isPartialReview && $hadCompletedReview
                    ? 'evidence_review'
                    : null,
            ])->saveQuietly();

            $reviewSession->forceFill([
                'manual_source_record_id' => $ingestion->sourceRecord->id,
                'resulting_canonical_revision' => $freshService->canonical_revision,
                'resulting_canonical_hash' => $freshService->canonical_hash,
                'completed_at' => $isPartialReview ? null : now(),
            ])->save();

            return new ChurchServiceEvidenceReviewResult(
                $freshService,
                true,
                $isPartialReview
                    ? 'Selected evidence saved. Remaining proposals still need review.'
                    : 'Selected evidence reviewed.',
            );
        });
    }

    /** @param array<int|string, string|null> $resolutions */
    private function resolutionFor(array $resolutions, int $proposalId): ?string
    {
        $resolution = $resolutions[$proposalId] ?? $resolutions[(string) $proposalId] ?? null;

        return is_string($resolution) && in_array($resolution, ['accepted', 'rejected', 'replaced'], true)
            ? $resolution
            : null;
    }

    /**
     * Deliberately exhaustive with no default arm. Defaulting an unrecognised
     * disposition to `accepted` is the B13 defect itself: it turns the absence of a
     * decision into a positive one. Callers validate the disposition before reaching
     * here, so an unmatched value is a programming error and must be loud.
     */
    private function statusForDisposition(?string $disposition): ChurchServiceProposalStatus
    {
        return match ($disposition) {
            'accepted' => ChurchServiceProposalStatus::Accepted,
            'rejected' => ChurchServiceProposalStatus::Rejected,
            'replaced' => ChurchServiceProposalStatus::Replaced,
            default => throw new RuntimeException(
                'A proposal reached status assignment without an explicit disposition.',
            ),
        };
    }

    private function rationaleFor(string $resolution): string
    {
        return match ($resolution) {
            'rejected' => 'Rejected through the multi-source service workbench.',
            'replaced' => 'Replaced with an explicit Manual canonical value.',
            default => 'Accepted through the multi-source service workbench.',
        };
    }
}
