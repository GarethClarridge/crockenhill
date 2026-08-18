<?php

declare(strict_types=1);

namespace App\Services\Email;

use RuntimeException;

/**
 * The one definition of "the same extraction", shared by the two places that decide it.
 *
 * Two separate comparisons in this evaluation ask that question. `OosParserArmPrimaryComparison`
 * asks it *between arms* to count discordant sources, and `OosParserArmRunner::stability()` asks it
 * *within an arm* across two replicate parses of one source. §6.2 step 2 reads the second as a
 * precondition for trusting the first, which only holds if both use the same rule — and they did
 * not: the runner compared the model's emitted plan order while the comparison keyed plans and
 * sorted them, so two replicates that returned the same plans in a different order counted as
 * self-disagreeing but would not have counted as discordant. In the banked 2026-08-18 runs 142 of
 * 554 sources emitted their plans in non-lexicographic order, so the divergence was reachable
 * rather than theoretical. This class exists so the rule has exactly one implementation.
 *
 * What is compared, and why only this:
 *
 * - **Plans are keyed by `plan_key` and sorted by it.** `plan_key` identifies the service a plan is
 *   for, so the order the model happened to emit two plans in carries no extraction claim.
 * - **Item order within a plan is retained.** Position is part of what an order of service *is*, so
 *   the same items in a different order is a real difference and must not be normalised away.
 * - **Duplicate `plan_key`s are rejected, not merged.** Silently overwriting one plan with another
 *   discards an extraction the comparison is meant to score, and hides a parser defect as a clean
 *   result.
 * - **Confidence, disposition, hold reasons and model-generated explanation prose are excluded.**
 *   Two parses returning the same items with different confidence is not an extraction
 *   disagreement — it is the routing risk the routing-safety guardrail exists for. Folding the
 *   continuous score and the free-text reasoning in made every source look different and inflated a
 *   real baseline's self-disagreement to 100% on its first run.
 *
 * Deleted with the rest of the one-shot evaluation surface.
 */
final class OosParserExtractionSignature
{
    /**
     * The decomposition `--stability-only` reports a disagreeing replicate pair against, so the
     * driver of an instability figure is attributable to a field group rather than guessed at.
     */
    public const FieldGroups = ['service_date_scope', 'item_structure', 'titles', 'provenance'];

    /** How many plans and items a single diff entry may report, so a diagnostic artifact stays bounded. */
    private const MaxReportedPlans = 4;

    private const MaxReportedItems = 12;

    /**
     * Keys a raw `service_plans` list by `plan_key` and sorts it, keeping each plan whole.
     *
     * Callers that need more than the scored fields — the safety guardrail reads `hold_reasons` —
     * use this and project afterwards.
     *
     * @param  list<mixed>  $plans
     * @return array<string, array<string, mixed>>
     */
    public static function index(array $plans, string $context): array
    {
        $indexed = [];

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                throw new RuntimeException("{$context} has a malformed service plan.");
            }

            /** @var array<string, mixed> $plan */
            $key = $plan['plan_key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                throw new RuntimeException("The {$context} service plan is missing a usable plan_key.");
            }

            if (array_key_exists($key, $indexed)) {
                throw new RuntimeException("{$context} returned two service plans keyed '{$key}', so one would silently replace the other.");
            }

            $indexed[$key] = $plan;
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * @param  list<mixed>  $plans
     * @return array<string, array<string, mixed>>
     */
    public static function fromPlanList(array $plans, string $context): array
    {
        return self::fromIndexedPlans(self::index($plans, $context));
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans  keyed and sorted by {@see index()}
     * @return array<string, array<string, mixed>>
     */
    public static function fromIndexedPlans(array $plans): array
    {
        $signature = [];

        foreach ($plans as $key => $plan) {
            $items = $plan['items'] ?? [];
            $provenance = $plan['source_provenance'] ?? [];

            $signature[$key] = [
                'service' => $plan['service'] ?? null,
                'date' => $plan['date'] ?? null,
                'content_scope' => $plan['content_scope'] ?? null,
                'items' => is_array($items) ? array_map(
                    static fn (mixed $item): array => is_array($item) ? [
                        'position' => $item['position'] ?? null,
                        'type' => $item['type'] ?? null,
                        'section_type' => $item['section_type'] ?? null,
                        'title' => $item['title'] ?? null,
                        'source_title' => $item['source_title'] ?? null,
                    ] : ['malformed' => true],
                    $items,
                ) : [],
                'source_line_bindings' => is_array($provenance) ? ($provenance['items'] ?? []) : [],
                'service_evidence_line_ids' => is_array($provenance) ? ($provenance['service_evidence_line_ids'] ?? []) : [],
            ];
        }

        return $signature;
    }

    /**
     * Attributes the difference between two signatures to field groups, with bounded concrete values.
     *
     * §12 round three's open question is *which* fields drive a 90% self-disagreement rate. A
     * boolean "these differed" cannot answer it, and the whole projection is too large and too
     * email-derived to retain, so this reports the group that moved plus a capped sample of the
     * values that moved within it.
     *
     * @param  array<string, array<string, mixed>>  $first
     * @param  array<string, array<string, mixed>>  $second
     * @return array<string, mixed>
     */
    public static function fieldDifferences(array $first, array $second): array
    {
        $firstOnly = array_values(array_diff(array_keys($first), array_keys($second)));
        $secondOnly = array_values(array_diff(array_keys($second), array_keys($first)));
        $shared = array_values(array_intersect(array_keys($first), array_keys($second)));

        $groups = array_fill_keys(self::FieldGroups, false);
        $plans = [];

        foreach ($shared as $key) {
            $difference = self::planDifference($first[$key], $second[$key]);

            if ($difference === []) {
                continue;
            }

            foreach (array_keys($difference) as $group) {
                $groups[$group] = true;
            }

            if (count($plans) < self::MaxReportedPlans) {
                $plans[$key] = $difference;
            }
        }

        return [
            'plan_keys_differ' => $firstOnly !== [] || $secondOnly !== [],
            'first_only_plan_keys' => $firstOnly,
            'second_only_plan_keys' => $secondOnly,
            'groups_that_differ' => array_values(array_filter(array_keys($groups), static fn (string $group): bool => $groups[$group])),
            'plans' => $plans,
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    private static function planDifference(array $first, array $second): array
    {
        $difference = [];

        $identity = ['service', 'date', 'content_scope'];
        $moved = array_values(array_filter($identity, static fn (string $field): bool => ($first[$field] ?? null) !== ($second[$field] ?? null)));

        if ($moved !== []) {
            $difference['service_date_scope'] = [
                'fields' => $moved,
                'first' => array_intersect_key($first, array_flip($moved)),
                'second' => array_intersect_key($second, array_flip($moved)),
            ];
        }

        /** @var list<array<string, mixed>> $firstItems */
        $firstItems = $first['items'];
        /** @var list<array<string, mixed>> $secondItems */
        $secondItems = $second['items'];

        $structure = static fn (array $items): array => array_map(
            static fn (array $item): array => ['position' => $item['position'] ?? null, 'type' => $item['type'] ?? null, 'section_type' => $item['section_type'] ?? null],
            $items,
        );

        if ($structure($firstItems) !== $structure($secondItems)) {
            $difference['item_structure'] = [
                'first_item_count' => count($firstItems),
                'second_item_count' => count($secondItems),
                'first' => array_slice($structure($firstItems), 0, self::MaxReportedItems),
                'second' => array_slice($structure($secondItems), 0, self::MaxReportedItems),
            ];
        }

        // `array_values`: the title comparison below addresses items by integer position, which is
        // only meaningful over a list. A plan whose items arrived keyed rather than sequential would
        // otherwise index as gaps and report every position as changed.
        $titles = static fn (array $items): array => array_values(array_map(
            static fn (array $item): array => ['title' => $item['title'] ?? null, 'source_title' => $item['source_title'] ?? null],
            $items,
        ));

        if ($titles($firstItems) !== $titles($secondItems)) {
            $difference['titles'] = self::titleDifferences($titles($firstItems), $titles($secondItems));
        }

        $provenance = ['source_line_bindings', 'service_evidence_line_ids'];
        $movedProvenance = array_values(array_filter($provenance, static fn (string $field): bool => ($first[$field] ?? null) !== ($second[$field] ?? null)));

        if ($movedProvenance !== []) {
            $difference['provenance'] = ['fields' => $movedProvenance];
        }

        return $difference;
    }

    /**
     * Only the positions whose titles actually moved, so a one-word change in a thirty-item order of
     * service is not reported as thirty lines of identical text.
     *
     * @param  list<array{title:mixed,source_title:mixed}>  $first
     * @param  list<array{title:mixed,source_title:mixed}>  $second
     * @return array<string, mixed>
     */
    private static function titleDifferences(array $first, array $second): array
    {
        $changed = [];

        for ($index = 0, $length = max(count($first), count($second)); $index < $length; $index++) {
            $left = $first[$index] ?? null;
            $right = $second[$index] ?? null;

            if ($left === $right) {
                continue;
            }

            $changed[] = ['index' => $index, 'first' => $left, 'second' => $right];
        }

        return [
            'changed_position_count' => count($changed),
            'changed_positions' => array_slice($changed, 0, self::MaxReportedItems),
        ];
    }
}
