<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceEvidenceReviewResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Services\ChurchService\SourceAdapters\ManualSourceAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewChurchServiceEvidence
{
    public function __construct(
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ManualSourceAdapter $manualSourceAdapter,
    ) {}

    /**
     * @param  list<int>  $proposalIds
     * @param  array<int, string>  $resolutions
     * @param  list<array<string, mixed>>  $manualItems
     * @param  array{summary: mixed, notices: mixed, chapter_markers: mixed}  $serviceContent
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

            $proposals = ChurchServiceMergeProposal::query()
                ->whereBelongsTo($lockedService)
                ->whereIn('id', $proposalIds)
                ->whereIn('status', [
                    ChurchServiceProposalStatus::Pending,
                    ChurchServiceProposalStatus::Stale,
                ])
                ->lockForUpdate()
                ->get();

            if ($proposalIds === [] || $proposals->count() !== count($proposalIds)) {
                return new ChurchServiceEvidenceReviewResult(
                    $lockedService,
                    false,
                    'One or more selected proposals are no longer available. Reload the page before reviewing.',
                );
            }

            $reviewUuid = (string) Str::uuid();
            $reviewSession = $lockedService->reviewSessions()->create([
                'review_uuid' => $reviewUuid,
                'base_canonical_revision' => $lockedService->canonical_revision,
                'base_canonical_hash' => $lockedService->canonical_hash,
                'included_proposal_ids' => $proposalIds,
                'service_field_decisions' => $serviceContent,
                'reviewed_by_user_id' => $userId,
            ]);

            $ingestion = $this->ingestSourceRevision->execute(
                $lockedService,
                $this->manualSourceAdapter->adapt(
                    $manualItems,
                    $userId,
                    $serviceContent,
                    $reviewUuid,
                ),
            );

            foreach ($manualItems as $position => $item) {
                $reviewSession->decisions()->create([
                    'selected_assertion_id' => is_numeric($item['selected_assertion_id'] ?? null)
                        ? (int) $item['selected_assertion_id']
                        : null,
                    'included' => true,
                    'final_position' => $position + 1,
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
                    'rationale' => 'Reviewed through the multi-source service workbench.',
                ]);
            }

            foreach ($proposals as $proposal) {
                $resolution = $resolutions[$proposal->id] ?? 'accepted';
                $proposal->forceFill([
                    'status' => $resolution === 'rejected'
                        ? ChurchServiceProposalStatus::Rejected
                        : ChurchServiceProposalStatus::Accepted,
                    'resolved_by_user_id' => $userId,
                    'resolved_at' => now(),
                ])->save();
            }

            $freshService = $lockedService->fresh() ?? $lockedService;
            $freshService->forceFill([
                'reviewed_canonical_revision' => $freshService->canonical_revision,
                'source_summary' => ChurchServiceItemSource::Manual->value,
                'source' => ChurchServiceItemSource::Manual->value,
                'needs_review' => false,
                'review_reason' => null,
            ])->saveQuietly();

            $reviewSession->forceFill([
                'manual_source_record_id' => $ingestion->sourceRecord->id,
                'resulting_canonical_revision' => $freshService->canonical_revision,
                'resulting_canonical_hash' => $freshService->canonical_hash,
                'completed_at' => now(),
            ])->save();

            return new ChurchServiceEvidenceReviewResult(
                $freshService,
                true,
                'Selected evidence reviewed.',
            );
        });
    }
}
