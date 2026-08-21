<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\SongTitleMatch;
use App\Enums\ChurchServiceItemSource;
use App\Services\Song\SongTitleResolver;

/**
 * Resolves incoming song titles against the catalogue so both sides of a merge
 * carry a song link.
 *
 * The catalogue is the one vocabulary all three sources can independently reach,
 * which makes it a far better anchor than comparing their text to each other: a
 * hand-typed "Amazng Grace" and OpenLP's "Amazing Grace" resolve to the same row
 * even though no string comparison would pair them.
 *
 * This runs ahead of both consumers that decide whether two items are the same —
 * {@see StructureMergePolicy} classifying a merge, and
 * {@see ChurchServiceItemSyncService} performing one — because a resolver only one
 * of them can see produces two different answers to the same question.
 */
class ServiceItemCatalogueSongResolver
{
    /**
     * Catalogue match types that are inferred rather than deterministic, so the
     * link they produce carries an audit trail. Mirrors ChurchServiceSongLinker.
     *
     * @var list<string>
     */
    private const array AuditedMatchTypes = [
        SongTitleMatch::TYPE_FIRST_LINE,
        SongTitleMatch::TYPE_FUZZY,
        SongTitleMatch::TYPE_HYMNBOOK_ABSENT,
    ];

    /**
     * SongTitleResolver is used rather than a second matcher because it is already
     * calibrated against this corpus — including the truncated titles emails
     * routinely carry. A detected run is excluded: MatchSongsFromTranscript resolves
     * those from lyrics and OCR, which is stronger evidence than the heard title,
     * and it has already run by the refining projection.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function resolveAll(array $items, ChurchServiceItemSource $incomingSource): array
    {
        if ($incomingSource->isDetected()) {
            return $items;
        }

        return $this->resolveItems($items);
    }

    /**
     * The same resolution without the incoming-source gate, for callers that have
     * already decided the evidence is eligible.
     * {@see ChurchServiceAssertionNormalizer} gates on
     * planned evidence instead, because it normalises assertions rather than merging
     * items. Both callers must reach the same answer for the same title, so the rule
     * lives here once rather than being restated at each entry point.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function resolveItems(array $items): array
    {
        // Built at most once per call, and only when there is something to resolve
        // — it loads a lookup over the whole catalogue.
        $songTitleResolver = null;

        foreach ($items as $index => $item) {
            $items[$index] = $this->resolve($item, $songTitleResolver);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolve(array $item, ?SongTitleResolver &$songTitleResolver): array
    {
        if (($item['song_id'] ?? null) !== null || ($item['type'] ?? null) !== 'songs') {
            return $item;
        }

        $searchTitle = $this->firstNonEmptyString([
            $item['openlp_search_title'] ?? null,
            $item['source_title'] ?? null,
            $item['title'] ?? null,
        ]);

        if ($searchTitle === null) {
            return $item;
        }

        $songTitleResolver ??= SongTitleResolver::fromDatabase();

        $match = $songTitleResolver->resolve($searchTitle);

        if (! $match instanceof SongTitleMatch) {
            return $item;
        }

        $item['song_id'] = $match->songId;

        if (in_array($match->matchType, self::AuditedMatchTypes, true)) {
            $metadata = $item['metadata'] ?? null;

            $item['metadata'] = [
                ...is_array($metadata) ? $metadata : [],
                'song_link' => [
                    'match_type' => $match->matchType,
                    'confidence' => $match->confidence,
                ],
            ];
        }

        return $item;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
