<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

/**
 * Decides the final ordering when two independently sourced item lists merge.
 *
 * The three sources describe a service along different axes: the emailed order
 * of service is the most complete list, OpenLP identifies items most accurately
 * but only lists the slide-backed ones, and a livestream run is the record of
 * what actually happened. Ordering therefore follows the detected run whenever
 * one is merging, and follows the existing list otherwise.
 *
 * Matched pairs are "anchors" — the only evidence tying the two sequences
 * together. Everything unmatched is placed relative to the anchors that bracket
 * it, so an item neither side agrees on still lands beside its neighbours
 * instead of being appended to the end of the service.
 */
class ServiceItemMergeOrderResolver
{
    /**
     * @param  list<array{kind: string, existing_position: int|null}>  $plan  Incoming items, in incoming order.
     * @param  list<int>  $preservedPositions  Positions of surviving unmatched existing items, ascending.
     * @return list<array{source: string, index: int}>
     */
    public function resolve(array $plan, array $preservedPositions, bool $spineIsIncoming): array
    {
        return $spineIsIncoming
            ? $this->resolveAgainstIncomingSpine($plan, $preservedPositions)
            : $this->resolveAgainstExistingSpine($plan, $preservedPositions);
    }

    /**
     * The incoming run dictates the order; unmatched existing items are spliced
     * into the gap between the anchors that bracketed them in the old list.
     *
     * @param  list<array{kind: string, existing_position: int|null}>  $plan
     * @param  list<int>  $preservedPositions
     * @return list<array{source: string, index: int}>
     */
    private function resolveAgainstIncomingSpine(array $plan, array $preservedPositions): array
    {
        /** @var array<int, int> $anchors existing position => spine index */
        $anchors = [];

        foreach ($plan as $spineIndex => $entry) {
            if ($entry['existing_position'] !== null) {
                $anchors[$entry['existing_position']] = $spineIndex;
            }
        }

        ksort($anchors);

        if ($anchors === []) {
            // Nothing anchored at all, so there is no evidence about how the two
            // lists interleave. Leave the existing list untouched and let the run
            // follow it rather than asserting an order we cannot support; the
            // caller raises a thin-coverage conflict so a reviewer sees it.
            return [
                ...$this->preservedSlots(array_keys($preservedPositions)),
                ...array_map(
                    fn (int $spineIndex): array => ['source' => 'plan', 'index' => $spineIndex],
                    array_keys($plan),
                ),
            ];
        }

        /** @var list<int> $head */
        $head = [];
        /** @var array<int, list<int>> $trailing spine index => preserved indexes to emit after it */
        $trailing = [];

        foreach ($preservedPositions as $preservedIndex => $position) {
            $precedingSpineIndex = $this->precedingAnchorSpineIndex($anchors, $position);

            if ($precedingSpineIndex === null) {
                $head[] = $preservedIndex;

                continue;
            }

            $trailing[$precedingSpineIndex][] = $preservedIndex;
        }

        $firstAnchorSpineIndex = reset($anchors);

        $ordered = [];
        $headEmitted = false;

        foreach ($plan as $spineIndex => $entry) {
            if (! $headEmitted && $spineIndex === $firstAnchorSpineIndex) {
                $ordered = [...$ordered, ...$this->preservedSlots($head)];
                $headEmitted = true;
            }

            $ordered[] = ['source' => 'plan', 'index' => $spineIndex];
            $ordered = [...$ordered, ...$this->preservedSlots($trailing[$spineIndex] ?? [])];
        }

        if (! $headEmitted) {
            $ordered = [...$ordered, ...$this->preservedSlots($head)];
        }

        return $ordered;
    }

    /**
     * The existing list dictates the order; incoming-only items are spliced in
     * after the last incoming item that anchored to it.
     *
     * @param  list<array{kind: string, existing_position: int|null}>  $plan
     * @param  list<int>  $preservedPositions
     * @return list<array{source: string, index: int}>
     */
    private function resolveAgainstExistingSpine(array $plan, array $preservedPositions): array
    {
        /** @var list<array{position: int, source: string, index: int}> $slots */
        $slots = [];

        foreach ($plan as $planIndex => $entry) {
            if ($entry['existing_position'] !== null) {
                $slots[] = ['position' => $entry['existing_position'], 'source' => 'plan', 'index' => $planIndex];
            }
        }

        foreach ($preservedPositions as $preservedIndex => $position) {
            $slots[] = ['position' => $position, 'source' => 'preserved', 'index' => $preservedIndex];
        }

        usort($slots, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        $hasAnchor = $slots !== [] && array_filter($slots, fn (array $slot): bool => $slot['source'] === 'plan') !== [];

        /** @var array<int, list<int>> $trailing plan index of an anchor => incoming-only plan indexes */
        $trailing = [];
        /** @var list<int> $tail */
        $tail = [];
        $lastAnchorPlanIndex = null;

        foreach ($plan as $planIndex => $entry) {
            if ($entry['existing_position'] !== null) {
                $lastAnchorPlanIndex = $planIndex;

                continue;
            }

            if (! $hasAnchor || $lastAnchorPlanIndex === null) {
                $tail[] = $planIndex;

                continue;
            }

            $trailing[$lastAnchorPlanIndex][] = $planIndex;
        }

        $ordered = [];

        foreach ($slots as $slot) {
            $ordered[] = ['source' => $slot['source'], 'index' => $slot['index']];

            if ($slot['source'] !== 'plan') {
                continue;
            }

            foreach ($trailing[$slot['index']] ?? [] as $planIndex) {
                $ordered[] = ['source' => 'plan', 'index' => $planIndex];
            }
        }

        foreach ($tail as $planIndex) {
            $ordered[] = ['source' => 'plan', 'index' => $planIndex];
        }

        return $ordered;
    }

    /**
     * @param  array<int, int>  $anchors  existing position => spine index, ascending by position
     */
    private function precedingAnchorSpineIndex(array $anchors, int $position): ?int
    {
        $precedingSpineIndex = null;

        foreach ($anchors as $anchorPosition => $spineIndex) {
            if ($anchorPosition >= $position) {
                break;
            }

            $precedingSpineIndex = $spineIndex;
        }

        return $precedingSpineIndex;
    }

    /**
     * @param  list<int>  $preservedIndexes
     * @return list<array{source: string, index: int}>
     */
    private function preservedSlots(array $preservedIndexes): array
    {
        return array_map(
            fn (int $preservedIndex): array => ['source' => 'preserved', 'index' => $preservedIndex],
            $preservedIndexes,
        );
    }
}
