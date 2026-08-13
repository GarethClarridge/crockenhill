<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\ScripturePassage;
use RuntimeException;

/**
 * The single canonical read of a portable publication's Scripture Passage
 * identity, and the destination's answer to whether it can satisfy it.
 *
 * F59 keeps Bundle A carrying a natural key only — never `html_content`,
 * `copyright` or the per-fetch `fums_token` — so an apply relinks against the
 * destination's own passages rather than copying licensed text. Decision D3
 * ratified that contract and recorded the gap it exposes: a destination that has
 * never run enrichment holds no matching row, and
 * HistoricMediaGraphPersister only discovers that as it writes each publication,
 * which is a throw partway through an apply rather than a refusal before it.
 *
 * Preflight and apply therefore share one read. If this class decided a key
 * differently from the persister, the preflight would clear keys the apply then
 * rejected — F49's "verification and consumption use different reads" defect in
 * another place — so `keyFor()` is the only implementation and the persister
 * calls it too.
 *
 * @phpstan-type PassageKey array{bible_id: string, normalized_reference: string}
 */
class HistoricScripturePassageRequirements
{
    /**
     * Terminal absences the export is allowed to settle on. F59 gates export on
     * a settled outcome, so an unrecognised one here means an unsettled
     * publication reached apply and must not be written.
     */
    private const ACCEPTED_ABSENCE_REASONS = [
        'api_disabled',
        'budget_exhausted',
        'not_found',
        'source_has_no_passage',
    ];

    /**
     * The passage identity a publication requires, or null when it approves a
     * terminal absence.
     *
     * The two shapes are an exclusive union, and neither of them is "the field
     * is missing". HIR3's finding was that an absent passage with an absent or
     * null outcome returned null here, which is the same answer an approved
     * terminal absence gives — so a bundle that had simply never settled the
     * publication passed the zero-write preflight and dropped the destination's
     * Scripture relationship during the apply. F59 gates export on a settled
     * outcome; missing evidence is not approval, so it is refused before any
     * row is written.
     *
     * @param  array<string, mixed>  $publication
     * @return PassageKey|null
     */
    public function keyFor(array $publication): ?array
    {
        $passage = $publication['scripture_passage'] ?? null;
        $outcome = $publication['scripture_passage_outcome'] ?? null;

        if ($passage === null) {
            if (! is_array($outcome)
                || ($outcome['status'] ?? null) !== 'approved_absent'
                || ! in_array($outcome['reason'] ?? null, self::ACCEPTED_ABSENCE_REASONS, true)) {
                throw new RuntimeException('Historic publication Scripture Passage absence outcome is invalid.');
            }

            return null;
        }

        if (! is_array($passage)
            || ! $this->isSettledString($passage['bible_id'] ?? null)
            || ! $this->isSettledString($passage['normalized_reference'] ?? null)
            || ! is_array($outcome)
            || ($outcome['status'] ?? null) !== 'linked'
            || ($outcome['reason'] ?? null) !== null) {
            throw new RuntimeException('Historic publication scripture passage identity is invalid.');
        }

        return [
            'bible_id' => $passage['bible_id'],
            'normalized_reference' => $passage['normalized_reference'],
        ];
    }

    /**
     * Every distinct passage identity one Bundle A service payload requires.
     *
     * @param  array<string, mixed>  $service
     * @return list<PassageKey>
     */
    public function forService(array $service): array
    {
        $graph = $service['media_graph'] ?? null;
        $publications = is_array($graph) ? ($graph['publications'] ?? []) : [];

        if (! is_array($publications)) {
            return [];
        }

        $keys = [];

        foreach ($publications as $publication) {
            if (! is_array($publication)) {
                continue;
            }

            $key = $this->keyFor($publication);

            if ($key !== null) {
                $keys[$this->identity($key)] = $key;
            }
        }

        return $this->sorted($keys);
    }

    /**
     * Every distinct passage identity a whole bundle requires. The pre-apply
     * enrichment pass D3 requires works from this set, so it fetches exactly
     * what the bundle carries and nothing else.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<PassageKey>
     */
    public function forBundle(array $bundle): array
    {
        $services = $bundle['services'] ?? [];

        if (! is_array($services)) {
            return [];
        }

        $keys = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            foreach ($this->forService($service) as $key) {
                $keys[$this->identity($key)] = $key;
            }
        }

        return $this->sorted($keys);
    }

    /**
     * Which of these identities the destination cannot satisfy.
     *
     * Each key is asked for with the query the persister itself runs rather than
     * fetched in bulk and diffed in PHP. Under a case-insensitive column
     * collation MySQL matches references PHP would call different, so a PHP-side
     * diff would report a key missing that the apply then resolves — F55's
     * source-key divergence, arrived at from the other direction.
     *
     * @param  list<PassageKey>  $keys
     * @return list<PassageKey>
     */
    public function missing(array $keys): array
    {
        $missing = [];

        foreach ($keys as $key) {
            $present = ScripturePassage::query()
                ->where('bible_id', $key['bible_id'])
                ->where('normalized_reference', $key['normalized_reference'])
                ->exists();

            if (! $present) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  list<PassageKey>  $keys
     *
     * @throws RuntimeException
     */
    public function assertAvailable(array $keys, string $context): void
    {
        $missing = $this->missing($keys);

        if ($missing === []) {
            return;
        }

        $named = implode(', ', array_map(
            fn (array $key): string => $this->identity($key),
            $missing,
        ));

        throw new RuntimeException(
            "{$context} requires ".count($missing).' Scripture Passage'.(count($missing) === 1 ? '' : 's')
            ." the destination does not hold: {$named}. Run historic-import:enrich-scripture-passages"
            .' against this bundle before the window; no records were changed.'
        );
    }

    /** @param PassageKey $key */
    public function identity(array $key): string
    {
        return "{$key['bible_id']}|{$key['normalized_reference']}";
    }

    /**
     * A whitespace-only natural key would resolve against nothing at the
     * destination while still reading as "present" to a truthiness check.
     */
    private function isSettledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, PassageKey>  $keys
     * @return list<PassageKey>
     */
    private function sorted(array $keys): array
    {
        ksort($keys);

        return array_values($keys);
    }
}
