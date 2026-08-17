<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NewcombePairedDifference;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NewcombePairedDifferenceTest extends TestCase
{
    #[Test]
    public function a_wilson_interval_stays_inside_the_unit_range_at_the_boundary(): void
    {
        [$lower, $upper] = NewcombePairedDifference::wilson(500, 500, NewcombePairedDifference::Z95OneSided);

        $this->assertGreaterThan(0.99, $lower);
        $this->assertLessThan(1.0, $lower);
        $this->assertEqualsWithDelta(1.0, $upper, 1.0e-12);

        [$zeroLower, $zeroUpper] = NewcombePairedDifference::wilson(0, 500, NewcombePairedDifference::Z95OneSided);

        $this->assertSame(0.0, $zeroLower);
        $this->assertGreaterThan(0.0, $zeroUpper);
    }

    #[Test]
    public function an_exact_tie_clears_a_three_point_margin_at_the_planned_corpus_size(): void
    {
        // b = c = 41 is the largest tie the plan's Wald sizing heuristic admits at N = 500.
        $interval = NewcombePairedDifference::interval(a: 209, b: 41, c: 41, d: 209, z: NewcombePairedDifference::Z95OneSided);

        $this->assertSame(500, $interval['n']);
        $this->assertSame(0.0, $interval['difference']);
        $this->assertGreaterThan(-0.03, $interval['lower']);
    }

    #[Test]
    public function a_lopsided_split_against_the_candidate_breaches_the_margin(): void
    {
        $interval = NewcombePairedDifference::interval(a: 400, b: 5, c: 45, d: 50, z: NewcombePairedDifference::Z95OneSided);

        $this->assertEqualsWithDelta(-0.08, $interval['difference'], 0.000001);
        $this->assertLessThan(-0.03, $interval['lower']);
    }

    #[Test]
    public function very_small_discordance_still_produces_a_usable_bound(): void
    {
        $interval = NewcombePairedDifference::interval(a: 498, b: 1, c: 1, d: 0, z: NewcombePairedDifference::Z95OneSided);

        $this->assertSame(0.0, $interval['difference']);
        $this->assertGreaterThan(-0.03, $interval['lower']);
        $this->assertLessThanOrEqual(1.0, $interval['upper']);
    }

    #[Test]
    public function a_degenerate_margin_drops_the_correlation_rather_than_inventing_one(): void
    {
        $interval = NewcombePairedDifference::interval(a: 0, b: 0, c: 10, d: 90, z: NewcombePairedDifference::Z95OneSided);

        $this->assertSame(0.0, $interval['phi']);
        $this->assertEqualsWithDelta(-0.1, $interval['difference'], 0.000001);
    }

    #[Test]
    public function the_worst_case_bound_is_never_more_favourable_than_any_feasible_allocation(): void
    {
        $worst = NewcombePairedDifference::worstCaseInterval(
            b: 10,
            c: 10,
            knownBoth: 0,
            knownNeither: 0,
            unlabelledConcordant: 80,
            z: NewcombePairedDifference::Z95OneSided,
        );

        $this->assertSame(100, $worst['n']);
        $this->assertSame(80, $worst['unlabelled_concordant']);

        for ($allocation = 0; $allocation <= 80; $allocation++) {
            $candidate = NewcombePairedDifference::interval(
                a: $allocation,
                b: 10,
                c: 10,
                d: 80 - $allocation,
                z: NewcombePairedDifference::Z95OneSided,
            );

            $this->assertLessThanOrEqual($candidate['lower'], $worst['lower']);
        }
    }

    #[Test]
    public function known_concordant_outcomes_are_carried_into_the_table(): void
    {
        $worst = NewcombePairedDifference::worstCaseInterval(
            b: 2,
            c: 2,
            knownBoth: 6,
            knownNeither: 4,
            unlabelledConcordant: 0,
            z: NewcombePairedDifference::Z95OneSided,
        );

        $this->assertSame(6, $worst['a']);
        $this->assertSame(4, $worst['d']);
        $this->assertSame(14, $worst['n']);
        $this->assertSame(0, $worst['worst_case_both_correct']);
    }

    #[Test]
    public function it_refuses_an_impossible_table(): void
    {
        $this->expectException(RuntimeException::class);

        NewcombePairedDifference::interval(a: 0, b: 0, c: 0, d: 0, z: NewcombePairedDifference::Z95OneSided);
    }

    #[Test]
    public function it_refuses_a_negative_cell(): void
    {
        $this->expectException(RuntimeException::class);

        NewcombePairedDifference::interval(a: 10, b: -1, c: 0, d: 0, z: NewcombePairedDifference::Z95OneSided);
    }
}
