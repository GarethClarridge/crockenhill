<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\ServiceReview\ReviewChurchServiceEvidence;
use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalDecisionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Authors one disposition against an enumerated set of proposals and then settles
 * every service it touched.
 *
 * Flipping proposal rows is not enough on its own. A staged proposal leaves its
 * service with `needs_review` set and `canonical_finalization` retracted, and only a
 * manual revision puts those back — so a rule that resolved every proposal but
 * stopped there would leave the service permanently in the inbox and unexportable.
 * Each affected service is therefore finalised through the same review action the
 * per-service workbench uses, which keeps one implementation of the review
 * invariants rather than a second, weaker one for bulk work.
 */
class ChurchServiceProposalRuleService
{
    /**
     * `replaced` is deliberately absent. Replacing a proposal means substituting a
     * canonical value the reviewer authored for that specific service, and there is
     * no such value to share across a class.
     */
    private const RULE_DISPOSITIONS = ['accepted', 'rejected'];

    public function __construct(
        private readonly ChurchServiceProposalCensus $census,
        private readonly ChurchServiceProposalIdentity $proposalIdentity,
        private readonly ReviewChurchServiceEvidence $reviewEvidence,
    ) {}

    /**
     * @param  list<int>  $proposalIds
     */
    public function apply(
        string $classKey,
        array $proposalIds,
        string $disposition,
        string $rationale,
        int $userId,
    ): ChurchServiceProposalDecisionRule {
        if ($proposalIds === []) {
            throw new RuntimeException('A decision rule must enumerate at least one proposal.');
        }

        if (! in_array($disposition, self::RULE_DISPOSITIONS, true)) {
            throw new RuntimeException(
                'A decision rule may only accept or reject. Replacing a proposal needs a canonical '
                .'value authored for one service, so it belongs in that service\'s workbench.',
            );
        }

        $rationale = trim($rationale);

        if ($rationale === '') {
            throw new RuntimeException('A decision rule must include a rationale.');
        }

        return DB::transaction(function () use ($classKey, $proposalIds, $disposition, $rationale, $userId): ChurchServiceProposalDecisionRule {
            $uniqueIds = array_values(array_unique($proposalIds));
            $proposals = ChurchServiceMergeProposal::query()
                ->with(['churchService', 'triggerSourceRecord.assertions'])
                ->whereIn('id', $uniqueIds)
                ->where('status', ChurchServiceProposalStatus::Pending)
                ->lockForUpdate()
                ->get();

            if ($proposals->count() !== count($uniqueIds)) {
                throw new RuntimeException('Every rule proposal must be pending and available.');
            }

            foreach ($proposals as $proposal) {
                if ($this->census->classKey($proposal) !== $classKey) {
                    throw new RuntimeException('A decision rule may only enumerate proposals from its declared class.');
                }
            }

            $rule = ChurchServiceProposalDecisionRule::query()->create([
                'class_key' => $classKey,
                'match_tier' => $this->census->matchTier($proposals->firstOrFail()),
                'disposition' => $disposition,
                'proposal_identities' => $proposals
                    ->map(fn (ChurchServiceMergeProposal $proposal): string => $this->proposalIdentity->for($proposal))
                    ->sort()
                    ->values()
                    ->all(),
                'rationale' => $rationale,
                'reviewed_by_user_id' => $userId,
                'applied_at' => now(),
            ]);

            foreach ($proposals->groupBy('church_service_id') as $serviceProposals) {
                $this->settleService($serviceProposals, $rule, $disposition, $rationale, $userId);
            }

            return $rule;
        });
    }

    /**
     * @param  Collection<int, ChurchServiceMergeProposal>  $proposals  All belong to one service.
     */
    private function settleService(
        Collection $proposals,
        ChurchServiceProposalDecisionRule $rule,
        string $disposition,
        string $rationale,
        int $userId,
    ): void {
        $service = $proposals->firstOrFail()->churchService;

        if (! $service instanceof ChurchService) {
            throw new RuntimeException('A rule proposal has no church service.');
        }

        $proposalIds = [];
        $resolutions = [];

        foreach ($proposals as $proposal) {
            $proposalId = (int) $proposal->getKey();
            $proposalIds[] = $proposalId;
            $resolutions[$proposalId] = $disposition;
        }

        $result = $this->reviewEvidence->execute(
            $service,
            $proposalIds,
            $resolutions,
            $this->manualItems($service, $proposals, $disposition, $rationale),
            [
                'summary' => $service->summary,
                'notices' => $service->notices ?? [],
                'chapter_markers' => $service->chapter_markers ?? [],
            ],
            $userId,
            $service->canonical_revision,
            $service->canonical_hash,
            (int) $rule->getKey(),
        );

        if (! $result->applied) {
            throw new RuntimeException(
                "Rule could not settle {$service->date->toDateString()} {$service->service->value}: {$result->reason}",
            );
        }
    }

    /**
     * Accepting adopts the machine's proposed projection; rejecting leaves the
     * service's existing canonical items standing. Either way the outcome is pinned
     * as an explicit manual revision rather than left implicit, so the service has a
     * recorded final position that Bundle B can carry.
     *
     * @param  Collection<int, ChurchServiceMergeProposal>  $proposals
     * @return list<array<string, mixed>>
     */
    private function manualItems(
        ChurchService $service,
        Collection $proposals,
        string $disposition,
        string $rationale,
    ): array {
        $operative = $proposals->sortBy('id')->last();
        $items = $disposition === 'accepted' && $operative instanceof ChurchServiceMergeProposal
            ? $operative->proposed_items
            : $service->items()->orderBy('position')->get()->map->toArray()->all();

        $assertions = $proposals
            ->flatMap(fn (ChurchServiceMergeProposal $proposal): array => $proposal->triggerSourceRecord?->assertions->all() ?? [])
            ->keyBy(fn (ChurchServiceItemAssertion $assertion): string => mb_strtolower($assertion->title));

        return array_values(array_map(
            fn (array $item): array => [
                ...$item,
                'included' => true,
                'rationale' => $rationale,
                'selected_assertion_id' => $assertions->get(mb_strtolower((string) ($item['title'] ?? '')))?->id,
            ],
            $items,
        ));
    }
}
