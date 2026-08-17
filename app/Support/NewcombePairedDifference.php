<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Newcombe's score-based confidence interval for the difference between two paired proportions.
 *
 * Newcombe (1998), *Improved confidence intervals for the difference between binomial proportions
 * based on paired data*, Statistics in Medicine 17, 2635–2650, method 10: a MOVER construction that
 * combines each arm's Wilson score interval and corrects for the pairing with the observed
 * tetrachoric-style correlation `φ̂`.
 *
 * Two alternatives were considered and rejected for the OoS parser evaluation:
 *
 * - **A paired Wald interval.** Its coverage degrades exactly where this evaluation expects to
 *   sit — small or lopsided discordance between two same-tier models. Wald survives only as the
 *   planning heuristic that sizes the labelling work, never as the decision rule.
 * - **McNemar's exact test.** It tests `b = c`. A non-inferiority question asks whether the
 *   difference is above a non-zero `−δ`, which McNemar cannot express at all.
 *
 * The 2×2 cells follow the evaluation's own vocabulary: `a` both arms correct, `b` candidate only,
 * `c` baseline only, `d` neither. The difference is always **candidate − baseline**, so a negative
 * bound is a candidate regression.
 */
class NewcombePairedDifference
{
    /** One-sided 95% normal quantile — the decision quantile for a non-inferiority bound. */
    public const Z95OneSided = 1.6448536269514722;

    /** Two-sided 95% normal quantile, reported only for description. */
    public const Z95TwoSided = 1.959963984540054;

    /**
     * @return array{
     *     n:int, a:int, b:int, c:int, d:int,
     *     baseline_rate:float, candidate_rate:float, difference:float, phi:float,
     *     lower:float, upper:float
     * }
     */
    public static function interval(int $a, int $b, int $c, int $d, float $z): array
    {
        foreach ([$a, $b, $c, $d] as $cell) {
            if ($cell < 0) {
                throw new RuntimeException('A paired 2x2 table cannot hold a negative cell.');
            }
        }

        $n = $a + $b + $c + $d;

        if ($n === 0) {
            throw new RuntimeException('A paired interval needs at least one observation.');
        }

        $candidateSuccesses = $a + $b;
        $baselineSuccesses = $a + $c;

        $p1 = $candidateSuccesses / $n;
        $p2 = $baselineSuccesses / $n;

        [$l1, $u1] = self::wilson($candidateSuccesses, $n, $z);
        [$l2, $u2] = self::wilson($baselineSuccesses, $n, $z);

        $phi = self::correlation($a, $b, $c, $d);
        $difference = $p1 - $p2;

        $lowerTerm = (($p1 - $l1) ** 2) - (2 * $phi * ($p1 - $l1) * ($u2 - $p2)) + (($u2 - $p2) ** 2);
        $upperTerm = (($u1 - $p1) ** 2) - (2 * $phi * ($u1 - $p1) * ($p2 - $l2)) + (($p2 - $l2) ** 2);

        return [
            'n' => $n,
            'a' => $a,
            'b' => $b,
            'c' => $c,
            'd' => $d,
            'baseline_rate' => $p2,
            'candidate_rate' => $p1,
            'difference' => $difference,
            'phi' => $phi,
            'lower' => max(-1.0, $difference - sqrt(max(0.0, $lowerTerm))),
            'upper' => min(1.0, $difference + sqrt(max(0.0, $upperTerm))),
        ];
    }

    /**
     * The interval under the least favourable allocation of the sources nobody labelled.
     *
     * Concordant sources — both arms produced the same extraction and the same routing — are never
     * adjudicated, so they are known to be *either* both-correct or both-wrong but not which. They
     * cannot move `b` or `c`, and so cannot move the point difference; they do move `a` and `d`,
     * which enter both Wilson intervals and `φ̂`.
     *
     * Rather than assume a split, every feasible split is evaluated and the **worst** lower bound
     * is returned. That is the same worst-case-allocation discipline the evaluation plan demands of
     * any partial-labelling rule: a conclusion may only stand if no allocation of the unlabelled
     * sources could overturn it.
     *
     * @param  int  $knownBoth  concordant sources adjudicated anyway — a routing-only disagreement
     *                          is raw discordance, so its label lands in `a` or `d`
     * @param  int  $knownNeither  concordant sources known wrong without adjudication, which is
     *                             only ever an unusable parse in both arms
     * @return array{
     *     n:int, a:int, b:int, c:int, d:int,
     *     baseline_rate:float, candidate_rate:float, difference:float, phi:float,
     *     lower:float, upper:float, unlabelled_concordant:int, worst_case_both_correct:int
     * }
     */
    public static function worstCaseInterval(
        int $b,
        int $c,
        int $knownBoth,
        int $knownNeither,
        int $unlabelledConcordant,
        float $z,
    ): array {
        if ($unlabelledConcordant < 0) {
            throw new RuntimeException('The unlabelled concordant count cannot be negative.');
        }

        $worst = self::interval($knownBoth, $b, $c, $knownNeither + $unlabelledConcordant, $z);
        $worstAllocation = 0;

        for ($allocatedToBoth = 1; $allocatedToBoth <= $unlabelledConcordant; $allocatedToBoth++) {
            $interval = self::interval(
                $knownBoth + $allocatedToBoth,
                $b,
                $c,
                $knownNeither + ($unlabelledConcordant - $allocatedToBoth),
                $z,
            );

            if ($interval['lower'] < $worst['lower']) {
                $worst = $interval;
                $worstAllocation = $allocatedToBoth;
            }
        }

        return $worst + [
            'unlabelled_concordant' => $unlabelledConcordant,
            'worst_case_both_correct' => $worstAllocation,
        ];
    }

    /**
     * Wilson score bounds, which stay inside `[0, 1]` where these rates actually sit.
     *
     * @return array{0: float, 1: float}
     */
    public static function wilson(int $successes, int $n, float $z): array
    {
        if ($n <= 0) {
            return [0.0, 1.0];
        }

        $p = $successes / $n;
        $z2 = $z ** 2;
        $denominator = 1 + ($z2 / $n);
        $centre = ($p + ($z2 / (2 * $n))) / $denominator;
        $half = ($z * sqrt((($p * (1 - $p)) / $n) + ($z2 / (4 * ($n ** 2))))) / $denominator;

        return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
    }

    /**
     * `φ̂ = (ad − bc) / sqrt((a+b)(c+d)(a+c)(b+d))`, and zero when a margin is empty.
     *
     * A zero margin means one arm was right — or wrong — everywhere, and no correlation is
     * estimable from a degenerate table. Newcombe's own prescription is to fall back to zero
     * there, which drops the correction rather than inventing one.
     */
    private static function correlation(int $a, int $b, int $c, int $d): float
    {
        $denominator = ($a + $b) * ($c + $d) * ($a + $c) * ($b + $d);

        if ($denominator === 0) {
            return 0.0;
        }

        return (($a * $d) - ($b * $c)) / sqrt((float) $denominator);
    }
}
