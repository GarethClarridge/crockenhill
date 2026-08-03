<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Groups every pending proposal into classes keyed by the matching tier that
 * hesitated plus the normalized subject of the disagreement, so one human judgement
 * can settle a class rather than each of its members.
 *
 * The census is also the working document for the §9.4.6 gate, so it carries each
 * class's recorded status and reason alongside its counts.
 *
 * @phpstan-type CensusClass array{
 *     class_key: string,
 *     subject: string,
 *     conflict_kinds: list<string>,
 *     match_tier: int|null,
 *     occurrence_count: int,
 *     service_count: int,
 *     proposal_ids: list<int>,
 *     service_identities: list<string>,
 *     representative_proposals: list<string>,
 *     representative_evidence: list<array{proposal_id: int, identity: string, conflicts: list<mixed>, proposed_items: list<mixed>}>,
 *     candidate_resolutions: list<string>,
 *     status: string,
 *     reason: string|null,
 *     seconds_per_decision: int|null,
 * }
 */
class ChurchServiceProposalCensus
{
    /** Proposals are read in batches so a corpus-wide census never holds the table in memory. */
    private const CHUNK = 500;

    private const REPRESENTATIVE_SAMPLE = 3;

    public function __construct(
        private readonly ChurchServiceProposalIdentity $proposalIdentity,
    ) {}

    /** @return list<CensusClass> */
    public function build(): array
    {
        $classes = [];

        ChurchServiceMergeProposal::query()
            ->with(['churchService', 'triggerSourceRecord'])
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->chunkById(self::CHUNK, function (Collection $proposals) use (&$classes): void {
                $this->accumulate($proposals, $classes);
            });

        return $this->finish($classes);
    }

    /**
     * @param  Collection<int, ChurchServiceMergeProposal>  $proposals
     * @return list<CensusClass>
     */
    public function fromProposals(Collection $proposals): array
    {
        $classes = [];
        $this->accumulate($proposals, $classes);

        return $this->finish($classes);
    }

    public function classKey(ChurchServiceMergeProposal $proposal): string
    {
        return CanonicalJson::hash([
            'match_tier' => $this->matchTier($proposal),
            'conflict_kinds' => $this->conflictKinds($proposal),
            'subject' => $this->subject($proposal),
        ]);
    }

    /**
     * The lowest tier that hesitated. A class is named by the weakest match it had to
     * fall back on, because that is the rule a matcher improvement would change.
     */
    public function matchTier(ChurchServiceMergeProposal $proposal): ?int
    {
        $tiers = [];

        foreach ($this->fieldDecisions($proposal) as $decision) {
            if (is_numeric($decision['match_tier'] ?? null)) {
                $tiers[] = (int) $decision['match_tier'];
            }
        }

        return $tiers === [] ? null : min($tiers);
    }

    /**
     * The normalized thing the sources disagree about, in readable form. This is what
     * makes a class judgeable — `Be Thou My Vision` against `Be thou my vision` is one
     * decision only if the reviewer can see that is what it is.
     */
    public function subject(ChurchServiceMergeProposal $proposal): string
    {
        $subject = null;

        foreach ($this->conflicts($proposal) as $conflict) {
            $candidate = $conflict['canonical_identity'] ?? $conflict['assertion_key'] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                $subject = $candidate;

                break;
            }
        }

        if ($subject === null) {
            foreach ($this->proposedItems($proposal) as $item) {
                $candidate = $item['canonical_identity'] ?? $item['title'] ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    $subject = $candidate;

                    break;
                }
            }
        }

        return Str::of($subject ?? 'unclassified')->lower()->squish()->value();
    }

    /**
     * @param  Collection<int, ChurchServiceMergeProposal>  $proposals
     * @param  array<string, CensusClass>  $classes
     */
    private function accumulate(Collection $proposals, array &$classes): void
    {
        foreach ($proposals as $proposal) {
            $classKey = $this->classKey($proposal);
            $service = $proposal->churchService;
            $serviceIdentity = $service === null
                ? null
                : $service->date->toDateString().'|'.$service->service->value;
            $class = $classes[$classKey] ?? [
                'class_key' => $classKey,
                'subject' => $this->subject($proposal),
                'conflict_kinds' => $this->conflictKinds($proposal),
                'match_tier' => $this->matchTier($proposal),
                'occurrence_count' => 0,
                'service_count' => 0,
                'proposal_ids' => [],
                'service_identities' => [],
                'representative_proposals' => [],
                'representative_evidence' => [],
                'candidate_resolutions' => [],
                'status' => ChurchServiceProposalClassReview::UNCLASSIFIED,
                'reason' => null,
                'seconds_per_decision' => null,
            ];

            $class['occurrence_count']++;
            $class['proposal_ids'][] = (int) $proposal->getKey();

            if (is_string($serviceIdentity) && ! in_array($serviceIdentity, $class['service_identities'], true)) {
                $class['service_identities'][] = $serviceIdentity;
                $class['service_count']++;
            }

            if (count($class['representative_proposals']) < self::REPRESENTATIVE_SAMPLE) {
                $identity = $this->proposalIdentity->for($proposal);
                $class['representative_proposals'][] = $identity;
                $class['representative_evidence'][] = [
                    'proposal_id' => (int) $proposal->getKey(),
                    'identity' => $identity,
                    'conflicts' => $this->conflicts($proposal),
                    'proposed_items' => $this->proposedItems($proposal),
                ];
            }

            foreach ($this->candidateResolutions($proposal) as $candidate) {
                if (! in_array($candidate, $class['candidate_resolutions'], true)) {
                    $class['candidate_resolutions'][] = $candidate;
                }
            }

            $classes[$classKey] = $class;
        }
    }

    /**
     * @param  array<string, CensusClass>  $classes
     * @return list<CensusClass>
     */
    private function finish(array $classes): array
    {
        if ($classes === []) {
            return [];
        }

        $marks = ChurchServiceProposalClassReview::query()
            ->whereIn('class_key', array_keys($classes))
            ->get()
            ->keyBy('class_key');

        foreach ($classes as $classKey => $class) {
            $mark = $marks->get($classKey);

            if ($mark instanceof ChurchServiceProposalClassReview) {
                $class['status'] = $mark->status;
                $class['reason'] = $mark->reason;
                $class['seconds_per_decision'] = $mark->seconds_per_decision;
                $classes[$classKey] = $class;
            }
        }

        uasort($classes, static function (array $left, array $right): int {
            $countCompare = $right['occurrence_count'] <=> $left['occurrence_count'];

            return $countCompare !== 0
                ? $countCompare
                : strcmp($left['class_key'], $right['class_key']);
        });

        return array_values($classes);
    }

    /** @return list<string> */
    private function conflictKinds(ChurchServiceMergeProposal $proposal): array
    {
        $kinds = [];

        foreach ($this->conflicts($proposal) as $conflict) {
            $kinds[] = (string) ($conflict['kind'] ?? 'unknown');
        }

        sort($kinds);

        return $kinds;
    }

    /** @return list<string> */
    private function candidateResolutions(ChurchServiceMergeProposal $proposal): array
    {
        $candidates = [];

        foreach ($this->conflicts($proposal) as $conflict) {
            $identities = $conflict['candidate_identities'] ?? null;

            if (! is_array($identities)) {
                continue;
            }

            foreach ($identities as $identity) {
                if (is_string($identity) && $identity !== '' && ! in_array($identity, $candidates, true)) {
                    $candidates[] = $identity;
                }
            }
        }

        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    private function conflicts(ChurchServiceMergeProposal $proposal): array
    {
        return $this->arrayRows($proposal->getAttribute('conflicts'));
    }

    /** @return list<array<string, mixed>> */
    private function proposedItems(ChurchServiceMergeProposal $proposal): array
    {
        return $this->arrayRows($proposal->getAttribute('proposed_items'));
    }

    /** @return list<array<string, mixed>> */
    private function fieldDecisions(ChurchServiceMergeProposal $proposal): array
    {
        return $this->arrayRows($proposal->getAttribute('field_decisions'));
    }

    /** @return list<array<string, mixed>> */
    private function arrayRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }
}
