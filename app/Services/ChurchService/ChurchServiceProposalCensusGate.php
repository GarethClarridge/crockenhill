<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchServiceProposalClassReview;

/**
 * Evaluates §9.4.6's stopping condition over a census.
 *
 * The gate is deliberately not a percentage. It asks four things: did the corpus
 * the census claims to describe actually stage and project, is every class
 * accounted for, is nothing marked irreducible while still carrying a candidate
 * resolution a tier change would settle, and is the residual hand-review cost
 * observed from the census rather than estimated.
 *
 * The first question is the one the class list cannot answer. An empty census is
 * produced both by a corpus that projected cleanly and by a corpus that never
 * projected at all, so the gate takes independent completeness evidence
 * (`ChurchServiceCorpusCompleteness`) as a required argument rather than inferring
 * a clean run from silence.
 */
class ChurchServiceProposalCensusGate
{
    public const string EXPECTED_CORPUS_SIZE_UNAPPROVED = 'expected_corpus_size_unapproved';

    public const string STAGED_BELOW_EXPECTED = 'staged_below_expected';

    public const string STAGED_ABOVE_EXPECTED = 'staged_above_expected';

    public const string PROJECTION_INCOMPLETE = 'projection_incomplete';

    public const string CENSUS_SOURCE_KINDS_UNDECLARED = 'census_source_kinds_undeclared';

    public const string DECLARED_SOURCE_KIND_UNSTAGED = 'declared_source_kind_unstaged';

    public const string MEMBERSHIP_UNAPPROVED = 'membership_unapproved';

    public const string MEMBERSHIP_MISMATCH = 'membership_mismatch';

    public const string MEMBERSHIP_SOURCE_KIND_UNAPPROVED = 'membership_source_kind_unapproved';

    public const string EXPECTATION_UNAPPROVED = 'expectation_unapproved';

    public const string EXPECTATION_MISMATCH = 'expectation_mismatch';

    /**
     * @param  list<array<string, mixed>>  $classes
     * @param  array<string, mixed>  $corpus  Evidence from ChurchServiceCorpusCompleteness.
     * @return array{
     *     passes: bool,
     *     class_count: int,
     *     proposal_count: int,
     *     unclassified: list<string>,
     *     irreducible_with_candidates: list<string>,
     *     unmeasured_irreducible: list<string>,
     *     residual_decisions: int,
     *     residual_seconds: int|null,
     *     corpus: array<string, mixed>,
     *     corpus_blockers: list<string>,
     * }
     */
    public function evaluate(array $classes, array $corpus): array
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

        $corpusBlockers = $this->corpusBlockers($corpus);

        return [
            'passes' => $corpusBlockers === []
                && $unclassified === []
                && $irreducibleWithCandidates === []
                && $unmeasured === [],
            'class_count' => count($classes),
            'proposal_count' => $proposalCount,
            'unclassified' => $unclassified,
            'irreducible_with_candidates' => $irreducibleWithCandidates,
            'unmeasured_irreducible' => $unmeasured,
            'residual_decisions' => $residualDecisions,
            'residual_seconds' => $unmeasured === [] ? $residualSeconds : null,
            'corpus' => $corpus,
            'corpus_blockers' => $corpusBlockers,
        ];
    }

    public function describeCorpusBlocker(string $blocker): string
    {
        return match ($blocker) {
            self::EXPECTED_CORPUS_SIZE_UNAPPROVED => 'No approved corpus size is recorded, so the census cannot be shown to cover the corpus. Set church.historic_corpus.expected_services from the approved manifest.',
            self::STAGED_BELOW_EXPECTED => 'Fewer services are staged than the approved manifest declares, so the census describes part of the corpus.',
            self::STAGED_ABOVE_EXPECTED => 'More services are staged than the approved manifest declares, so the staged corpus and the manifest disagree.',
            self::PROJECTION_INCOMPLETE => 'Some staged services carry no projection at the current policy version, so their proposals — or absence of proposals — are not yet evidence.',
            self::CENSUS_SOURCE_KINDS_UNDECLARED => 'No valid source kinds are declared, so the census does not say which evidence it covers. Set church.historic_corpus.census_source_kinds (for example "email,openlp").',
            self::DECLARED_SOURCE_KIND_UNSTAGED => 'A declared source kind has no staged services at all, so the census cannot have converged the population it claims to cover.',
            self::MEMBERSHIP_UNAPPROVED => 'No hash-verified, item-level corpus membership is supplied, so scalar counts cannot certify this census.',
            self::MEMBERSHIP_MISMATCH => 'One or more approved source items are missing, stale, mismatched or unexpected. Inspect the membership certificate before claiming the census.',
            self::MEMBERSHIP_SOURCE_KIND_UNAPPROVED => 'The membership certificate has no approved items for a source kind this census claims to cover.',
            self::EXPECTATION_UNAPPROVED => 'No manifest-derived corpus expectation is supplied, so nothing states what the corpus was meant to contain and an entry that never staged cannot be detected. Produce one with oos:generate-corpus-expectation.',
            self::EXPECTATION_MISMATCH => 'The staged corpus and the approved manifest disagree: an approved entry did not stage, or an identity staged that no approved entry explains. Inspect the expectation reconciliation.',
            default => $blocker,
        };
    }

    /**
     * A census over an unstaged or unprojected corpus is not evidence of anything,
     * however tidy its class list looks.
     *
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    private function corpusBlockers(array $corpus): array
    {
        $blockers = [];
        $membership = $corpus['membership'] ?? null;

        $hasApprovedMembership = is_array($membership) && ($membership['approved'] ?? false);

        if (! $hasApprovedMembership) {
            $blockers[] = self::MEMBERSHIP_UNAPPROVED;
        } elseif (($membership['blockers'] ?? []) !== []) {
            $blockers[] = self::MEMBERSHIP_MISMATCH;
        }

        if (is_array($membership) && ($corpus['declared_source_kinds'] ?? null) !== null) {
            $membershipSources = $membership['source_kinds'] ?? [];
            foreach ($corpus['declared_source_kinds'] as $source) {
                if (! in_array($source, $membershipSources, true)) {
                    $blockers[] = self::MEMBERSHIP_SOURCE_KIND_UNAPPROVED;
                    break;
                }
            }
        }

        /**
         * The F1 check. Membership certification describes the set it is handed
         * and its only producer read that set out of the staged database, so on
         * its own it cannot fail for an approved entry that never staged. The
         * expectation is derived from the manifest instead, and is required for
         * the same reason membership is: a completeness claim with no independent
         * statement of what "complete" means is not evidence.
         */
        $expectation = $corpus['expectation'] ?? null;
        $hasApprovedExpectation = is_array($expectation) && ($expectation['approved'] ?? false);

        if (! $hasApprovedExpectation) {
            $blockers[] = self::EXPECTATION_UNAPPROVED;
        } elseif (($expectation['blockers'] ?? []) !== []) {
            $blockers[] = self::EXPECTATION_MISMATCH;
        }

        $expected = $corpus['expected_services'] ?? null;
        $staged = (int) ($corpus['staged_services'] ?? 0);
        $projected = (int) ($corpus['projected_services'] ?? 0);

        /**
         * Scalar counts are the fallback instrument, not a second opinion. An
         * approved expectation makes them actively wrong to enforce: one approved
         * email legitimately stages both that Sunday's morning and evening orders,
         * so `staged` exceeds the manifest's identity count by design and
         * `staged_above_expected` would fire on a correct corpus. The per-identity
         * reconciliation above covers the same ground exactly.
         */
        if (! $hasApprovedMembership && ! $hasApprovedExpectation) {
            if (! is_int($expected) || $expected < 1) {
                $blockers[] = self::EXPECTED_CORPUS_SIZE_UNAPPROVED;
            } elseif ($staged < $expected) {
                $blockers[] = self::STAGED_BELOW_EXPECTED;
            } elseif ($staged > $expected) {
                $blockers[] = self::STAGED_ABOVE_EXPECTED;
            }
        }

        if (! $hasApprovedMembership && $projected < $staged) {
            $blockers[] = self::PROJECTION_INCOMPLETE;
        }

        // Counting services answers "is the corpus staged"; it cannot answer "staged
        // from what". Email staging is drive-free and OpenLP staging is not, so an
        // Email-only corpus satisfies every count above while the Email x OpenLP
        // population §9.4.2 wants converged has not been generated at all.
        if ($corpus['declared_source_kinds'] === null) {
            $blockers[] = self::CENSUS_SOURCE_KINDS_UNDECLARED;
        } elseif (($corpus['unstaged_source_kinds'] ?? []) !== []) {
            $blockers[] = self::DECLARED_SOURCE_KIND_UNSTAGED;
        }

        return $blockers;
    }
}
