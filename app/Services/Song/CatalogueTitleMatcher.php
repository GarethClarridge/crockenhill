<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\SongTitleMatch;

/**
 * How a hymn-workbook reference — a title and, sometimes, a hymn-book number — is resolved
 * against the deployed catalogue.
 *
 * Extracted from HistoricHymnReconciliation so the item-level ground truth resolves workbook
 * titles by the same rule the reconciliation itself used. Two consumers applying two slightly
 * different matching policies to one source would produce two different membership sets and
 * no way to tell which disagreed with the parser and which disagreed with each other.
 */
final class CatalogueTitleMatcher
{
    /**
     * The strongest match across both ways of probing the catalogue.
     *
     * Catalogue titles carry their hymn-book number inconsistently — "Amazing Grace #772"
     * alongside a bare "Amazing Grace" — so neither probe wins everywhere. Probing with
     * the number appended finds the numbered entries, and probing on the title alone
     * finds the rest; sending only the combined probe turns a clean title match into a
     * fuzzy one whenever the catalogue entry carries no number.
     *
     * A definite match always beats a fuzzy one, and confidence breaks the remaining ties.
     */
    public function match(SongTitleResolver $resolver, string $title, ?string $number): ?SongTitleMatch
    {
        $matches = array_values(array_filter([
            $resolver->resolve($title),
            $number === null ? null : $resolver->resolve($title.' '.$number),
        ]));

        if ($matches === []) {
            return null;
        }

        usort($matches, static function (SongTitleMatch $a, SongTitleMatch $b): int {
            $aFuzzy = $a->matchType === SongTitleMatch::TYPE_FUZZY;
            $bFuzzy = $b->matchType === SongTitleMatch::TYPE_FUZZY;

            return $aFuzzy === $bFuzzy
                ? $b->confidence <=> $a->confidence
                : ($aFuzzy ? 1 : -1);
        });

        return $matches[0];
    }
}
