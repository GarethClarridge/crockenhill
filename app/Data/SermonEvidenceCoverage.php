<?php

declare(strict_types=1);

namespace App\Data;

/**
 * How much of the sermon media a run actually cut has transcript evidence
 * behind it.
 *
 * `CreateSermonTranscriptFromService` only ever asked whether the selected
 * spans yielded *any* text. That is a presence check standing in for a
 * sufficiency one, and sermon 1148 (2023-04-30, run 1229) walked through it: a
 * recorded unobservable window covered 1,793 of its 1,858-second span — 96.5% —
 * leaving 81 words of closing prayer. Non-empty, so the run completed clean,
 * banked the title "Preserved by God's amazing grace" from that prayer, and
 * carried a null reference into analysis.
 *
 * The measure is the *fraction* of the delivered span with no evidence, never
 * the raw seconds. Ranking the 2026-09-07 corpus of 406 completed sermons both
 * ways disagreed sharply: sermon 1299 holds the second-largest blind interval of
 * any run (710 seconds) and is entirely healthy — 4,639 words across a long
 * span, reading complete at both ends — while sermon 1043 is materially damaged
 * on less than half that (407 seconds of an 826-second span).
 *
 * Density of the surviving speech was evaluated as an alternative and rejected:
 * measured against observable seconds alone the whole corpus is uniform (minimum
 * 69.2 words/minute, median 122.5), and even 1148's surviving fragment runs at a
 * normal 75.5. ASR emits ordinary-rate text wherever it emits any, so a
 * words-per-minute rule only re-expresses this fraction less legibly.
 */
final class SermonEvidenceCoverage
{
    /**
     * Above this share of blind span the evidence cannot support a sermon, and
     * the run fails rather than paying for analysis of what survived.
     *
     * Set in the gap between the two runs it holds — 1148 at 96.5% and 1043 at
     * 49.2% — and the healthy remainder, whose worst case is 1299 at 27.4%.
     */
    public const INSUFFICIENT_FRACTION = 0.40;

    /**
     * Above this share a reviewer is asked to look, because a materially blind
     * span cannot be recognised from the output.
     *
     * Sermon 1135 is the case that sets it: 24.5% blind, and it reads perfectly
     * at both ends — a coherent illustration opening and a proper benediction —
     * while its window hides the sermon's first eight minutes. Nothing in the
     * saved text reveals the loss. The line sits between 1252 (13.5%) and 1248
     * (8.3%), both of which carry full sermons.
     */
    public const REVIEW_FRACTION = 0.10;

    private function __construct(
        public readonly float $spanSeconds,
        public readonly float $unobservableSeconds,
    ) {}

    /**
     * Both sides are reduced to a union first, so overlapping windows — which
     * {@see ChurchServiceTranscript} sorts but does not merge — cannot count
     * their shared seconds twice and drive the fraction above one.
     *
     * @param  list<array{start: float, end: float}>  $spans  The source spans the sermon media was cut from
     */
    public static function measure(array $spans, ChurchServiceTranscript $transcript): self
    {
        $windows = self::union($transcript->unobservableWindows);

        $spanSeconds = 0.0;
        $unobservableSeconds = 0.0;

        foreach (self::union($spans) as $span) {
            $spanSeconds += $span['end'] - $span['start'];

            foreach ($windows as $window) {
                $start = max($span['start'], $window['start']);
                $end = min($span['end'], $window['end']);

                if ($end > $start) {
                    $unobservableSeconds += $end - $start;
                }
            }
        }

        return new self($spanSeconds, $unobservableSeconds);
    }

    public function unobservableFraction(): float
    {
        return $this->spanSeconds > 0.0 ? $this->unobservableSeconds / $this->spanSeconds : 0.0;
    }

    public function insufficientForAnalysis(): bool
    {
        return $this->unobservableFraction() >= self::INSUFFICIENT_FRACTION;
    }

    public function warrantsReview(): bool
    {
        return $this->unobservableFraction() >= self::REVIEW_FRACTION;
    }

    /**
     * @param  array<int, array{start: float|int|string, end: float|int|string, ...}>  $intervals
     * @return list<array{start: float, end: float}>
     */
    private static function union(array $intervals): array
    {
        $ordered = [];

        foreach ($intervals as $interval) {
            $start = (float) $interval['start'];
            $end = (float) $interval['end'];

            if ($end > $start) {
                $ordered[] = ['start' => $start, 'end' => $end];
            }
        }

        usort($ordered, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $merged = [];

        foreach ($ordered as $interval) {
            $last = array_key_last($merged);

            if ($last !== null && $interval['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $interval['end']);

                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }
}
