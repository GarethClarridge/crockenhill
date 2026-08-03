<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchServiceProposalClassReview;

/**
 * Evaluates §9.4.6's stopping condition over a census.
 *
 * The gate is deliberately not a percentage. It asks three things: is every class
 * accounted for, is nothing marked irreducible while still carrying a candidate
 * resolution a tier change would settle, and is the residual hand-review cost
 * observed from the census rather than estimated.
 */
class ChurchServiceProposalCensusGate
{
    /**
     * @param  list<array<string, mixed>>  $classes
     * @return array{
     *     passes: bool,
     *     class_count: int,
     *     proposal_count: int,
     *     unclassified: list<string>,
     *     irreducible_with_candidates: list<string>,
     *     unmeasured_irreducible: list<string>,
     *     residual_decisions: int,
     *     residual_seconds: int|null,
     * }
     */
    public function evaluate(array $classes): array
    {
        $unclassified = [];
        $irreducibleWithCandidates = [];
        $unmeasured = [];
        $residualDecisions = 0;
        $residualSeconds = 0;
        $proposalCount = 0;

        foreach ($classes as $class) {
            $classKey = (string) $class['class_key'];
            $occurrences = (int) $class['occurrence_count'];
            $proposalCount += $occurrences;

            if ($class['status'] === ChurchServiceProposalClassReview::UNCLASSIFIED) {
                $unclassified[] = $classKey;

                continue;
            }

            if ($class['status'] !== ChurchServiceProposalClassReview::IRREDUCIBLE) {
                continue;
            }

            $residualDecisions += $occurrences;

            // Marking a class irreducible while it still names a candidate the matcher
            // could have chosen is exactly the shortcut the gate exists to catch.
            if ($class['candidate_resolutions'] !== []) {
                $irreducibleWithCandidates[] = $classKey;
            }

            $seconds = $class['seconds_per_decision'] ?? null;

            if (! is_int($seconds)) {
                $unmeasured[] = $classKey;

                continue;
            }

            $residualSeconds += $seconds * $occurrences;
        }

        return [
            'passes' => $unclassified === [] && $irreducibleWithCandidates === [] && $unmeasured === [],
            'class_count' => count($classes),
            'proposal_count' => $proposalCount,
            'unclassified' => $unclassified,
            'irreducible_with_candidates' => $irreducibleWithCandidates,
            'unmeasured_irreducible' => $unmeasured,
            'residual_decisions' => $residualDecisions,
            'residual_seconds' => $unmeasured === [] ? $residualSeconds : null,
        ];
    }
}
