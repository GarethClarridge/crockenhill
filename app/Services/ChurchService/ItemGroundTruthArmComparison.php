<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use RuntimeException;

/**
 * Compare two item-level ground-truth artifacts produced by different parser settings.
 *
 * Two accuracy figures side by side hide the interesting thing. The identities are the same services
 * in both arms, so what matters is how each identity *moved*, and the discordant pairs alone carry
 * that information. Two arms can post the same match rate while disagreeing on a third of the corpus.
 *
 * Three properties of this comparison are deliberate:
 *
 * - **The denominator is evidence availability**, taken from the artifacts' own `scoring` block,
 *   and never a verdict class. `indeterminate` is produced by the staged side — a parse that
 *   returned no songs, or a title the catalogue could not resolve — so filtering it out selects
 *   the population using the parse under test. That is the one mistake that makes a model
 *   evaluation report the opposite of the truth: the arm that extracted nothing has its failures
 *   removed from its own denominator.
 * - **Every transition is reported**, so "fixed a total extraction failure"
 *   (indeterminate → match) is visible as its own cell rather than folded into a rate.
 * - **These three dimensions are descriptive, and carry no p-value.** The hymn workbook and the
 *   OpenLP decks answer whether the email plan agrees with what was later *sung or projected*, and a
 *   service can change after the email was written — so a disagreement here is not automatically a
 *   parser error and cannot decide which arm to adopt. That decision rests on source-faithful
 *   extraction against a declared non-inferiority margin, which is not computed here. Reporting
 *   unadjusted p-values across three dimensions that decide nothing would create a multiplicity
 *   obligation for no inferential gain, so neither the exact McNemar p-values nor the Holm
 *   correction that adjusted them survive. Removing only the correction would have been worse than
 *   keeping both.
 */
class ItemGroundTruthArmComparison
{
    /**
     * Report format version.
     *
     * Bumped to 2 when the per-dimension p-values and their Holm adjustment were removed and
     * `mcnemar` became `discordance`. A comparison is create-once evidence, so a reader that quoted
     * a figure from a version 1 report must be able to tell that its shape no longer exists.
     */
    private const Version = 2;

    /** Outcome classes a scored identity can hold, in report order. */
    private const Outcomes = ['match', 'mismatch', 'indeterminate', 'circular'];

    /**
     * The curation tier a comparison is decided on.
     *
     * A `partial` source is curated to evidence-only retention, so both arms correctly hold no
     * items and every pair is concordant; a `no_source` identity had nothing to parse. Including
     * them cannot change McNemar — concordant pairs carry no evidence — but it does dilute both
     * arms' match rates by identities no model can move, and invites reading an acquisition gap as
     * a model deficiency. The primary comparison is therefore the `full` tier alone.
     */
    private const PrimaryTier = 'full';

    /** The evidence each dimension is scored against; absent evidence is the only valid exclusion. */
    private const EvidenceFor = [
        'song_membership' => 'hymn_workbook',
        'song_count' => 'openlp',
        'song_order' => 'openlp',
    ];

    /** Two-sided normal quantile for a 95% Wilson interval. */
    private const Z95 = 1.959963984540054;

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function compare(array $baseline, array $candidate, int $exampleLimit = 10): array
    {
        $baselineById = $this->indexIdentities($baseline, 'baseline');
        $candidateById = $this->indexIdentities($candidate, 'candidate');

        $dimensions = [];

        foreach (self::EvidenceFor as $dimension => $evidence) {
            $dimensions[$dimension] = $this->compareDimension($dimension, $evidence, $baselineById, $candidateById, $exampleLimit);
        }

        return [
            'format' => 'historic-item-ground-truth-arm-comparison',
            'version' => self::Version,
            'dimension_role' => 'secondary descriptive diagnostic; these dimensions carry no inferential '
                .'test, because agreement with what was later sung or projected is not the same question '
                .'as faithfulness to the email, and a service can change after the email was written',
            'population_rule' => 'identities present in both artifacts whose corroborating evidence exists '
                .'and whose curated source presents a complete running order; no verdict class is excluded, '
                .'because only evidence availability and curation scope are independent of the parse',
            'shared_identities' => count(array_intersect_key($baselineById, $candidateById)),
            'baseline_only_identities' => count(array_diff_key($baselineById, $candidateById)),
            'candidate_only_identities' => count(array_diff_key($candidateById, $baselineById)),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $baselineById
     * @param  array<string, array<string, mixed>>  $candidateById
     * @return array<string, mixed>
     */
    private function compareDimension(
        string $dimension,
        string $evidence,
        array $baselineById,
        array $candidateById,
        int $exampleLimit,
    ): array {
        $matrix = $this->emptyMatrix();
        $population = 0;
        $baselineMatches = 0;
        $candidateMatches = 0;
        $onlyBaselineCorrect = 0;
        $onlyCandidateCorrect = 0;
        $evidenceDrift = 0;
        $tierDrift = 0;
        $withheldByTier = [];
        $examples = [];

        foreach ($baselineById as $key => $baselineIdentity) {
            $candidateIdentity = $candidateById[$key] ?? null;

            if ($candidateIdentity === null) {
                continue;
            }

            $baselineHasEvidence = ($baselineIdentity[$evidence] ?? null) !== null;
            $candidateHasEvidence = ($candidateIdentity[$evidence] ?? null) !== null;

            /*
             * Evidence availability is a fact about the hymn workbook and the OpenLP decks, so it
             * must be identical in both arms. If it is not, the two artifacts were built against
             * different evidence and no paired comparison between them is meaningful.
             */
            if ($baselineHasEvidence !== $candidateHasEvidence) {
                $evidenceDrift++;

                continue;
            }

            if (! $baselineHasEvidence) {
                continue;
            }

            $baselineTier = (string) ($baselineIdentity['staged']['curation_tier'] ?? self::PrimaryTier);
            $candidateTier = (string) ($candidateIdentity['staged']['curation_tier'] ?? self::PrimaryTier);

            /*
             * A tier is a curation decision, not a parse output, so it must be identical in both
             * arms. A change means the corpus was re-curated between them and the two artifacts
             * are not measuring the same sources.
             */
            if ($baselineTier !== $candidateTier) {
                $tierDrift++;

                continue;
            }

            if ($baselineTier !== self::PrimaryTier) {
                $withheldByTier[$baselineTier] = ($withheldByTier[$baselineTier] ?? 0) + 1;

                continue;
            }

            $from = (string) $baselineIdentity['verdicts'][$dimension];
            $to = (string) $candidateIdentity['verdicts'][$dimension];

            if (! isset($matrix[$from][$to])) {
                continue;
            }

            $population++;
            $matrix[$from][$to]++;

            $fromMatch = $from === 'match';
            $toMatch = $to === 'match';
            $baselineMatches += $fromMatch ? 1 : 0;
            $candidateMatches += $toMatch ? 1 : 0;

            if ($fromMatch && ! $toMatch) {
                $onlyBaselineCorrect++;
            }

            if (! $fromMatch && $toMatch) {
                $onlyCandidateCorrect++;
            }

            if ($from !== $to && count($examples) < $exampleLimit) {
                $examples[] = [
                    'date' => $baselineIdentity['date'],
                    'service' => $baselineIdentity['service'],
                    'baseline' => $from,
                    'candidate' => $to,
                    'baseline_song_item_count' => $baselineIdentity['staged']['song_item_count'],
                    'candidate_song_item_count' => $candidateIdentity['staged']['song_item_count'],
                ];
            }
        }

        ksort($withheldByTier);

        return [
            'evidence' => $evidence,
            'tier' => self::PrimaryTier,
            'population' => $population,
            'withheld_by_tier' => $withheldByTier,
            'evidence_drift_identities' => $evidenceDrift,
            'tier_drift_identities' => $tierDrift,
            'transitions' => $matrix,
            'total_extraction_failures_fixed' => $matrix['indeterminate']['match'] + $matrix['indeterminate']['mismatch'],
            'total_extraction_failures_introduced' => $matrix['match']['indeterminate'] + $matrix['mismatch']['indeterminate'],
            'match_rate' => [
                'baseline' => $this->rate($baselineMatches, $population),
                'candidate' => $this->rate($candidateMatches, $population),
            ],
            'discordance' => [
                'only_baseline_correct' => $onlyBaselineCorrect,
                'only_candidate_correct' => $onlyCandidateCorrect,
                'discordant' => $onlyBaselineCorrect + $onlyCandidateCorrect,
            ],
            'changed_examples' => $examples,
        ];
    }

    /** @return array<string, array<string, int>> */
    private function emptyMatrix(): array
    {
        $row = array_fill_keys(self::Outcomes, 0);

        return array_fill_keys(self::Outcomes, $row);
    }

    /**
     * A proportion with a 95% Wilson score interval.
     *
     * Wilson rather than the textbook normal interval because these rates sit near 0 and 1 often
     * enough — a total-extraction-failure arm has a match rate close to zero — that a normal
     * interval would run outside [0, 1] and read as precision it does not have.
     *
     * Each interval describes one arm on its own. Two of them side by side are not a paired test:
     * the arms are correlated, so overlapping or disjoint intervals say nothing about the paired
     * difference. Read `discordance` for that, and the source-level non-inferiority bound for a
     * decision.
     *
     * @return array{matches: int, population: int, rate: float|null, ci_lower: float|null, ci_upper: float|null}
     */
    private function rate(int $matches, int $population): array
    {
        if ($population === 0) {
            return ['matches' => 0, 'population' => 0, 'rate' => null, 'ci_lower' => null, 'ci_upper' => null];
        }

        $p = $matches / $population;
        $z = self::Z95;
        $z2 = $z ** 2;
        $denominator = 1 + ($z2 / $population);
        $centre = ($p + ($z2 / (2 * $population))) / $denominator;
        $half = ($z * sqrt(($p * (1 - $p) / $population) + ($z2 / (4 * $population ** 2)))) / $denominator;

        return [
            'matches' => $matches,
            'population' => $population,
            'rate' => round($p, 6),
            'ci_lower' => round(max(0.0, $centre - $half), 6),
            'ci_upper' => round(min(1.0, $centre + $half), 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, mixed>>
     */
    private function indexIdentities(array $artifact, string $label): array
    {
        $identities = $artifact['identities'] ?? null;

        if (! is_array($identities)) {
            throw new RuntimeException("The {$label} artifact carries no identities array.");
        }

        $indexed = [];

        foreach ($identities as $identity) {
            if (! is_array($identity) || ! isset($identity['date'], $identity['service'], $identity['verdicts'])) {
                continue;
            }

            $indexed[$identity['date'].'|'.$identity['service']] = $identity;
        }

        if ($indexed === []) {
            throw new RuntimeException("The {$label} artifact carries no usable identities.");
        }

        return $indexed;
    }
}
