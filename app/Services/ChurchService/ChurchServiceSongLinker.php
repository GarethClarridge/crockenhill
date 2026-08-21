<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\SongTitleMatch;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\Song\SongTitleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Links song items to the catalogue and titles them from it.
 *
 * Titling happens here rather than at each caller so every route into the data — an
 * auto-imported email, an admin's manual save, an OpenLP export, the linkAll backfill —
 * ends with the same title for the same song. What the source actually wrote is never
 * lost: {@see resolveSearchTitle()} reads `source_title` ahead of `title`, so the raw
 * line stays both the thing matching is done on and the item's provenance.
 */
class ChurchServiceSongLinker
{
    /**
     * Match types recorded on the item as metadata.song_link — the lower-confidence tiers a
     * reviewer may want to audit. Confident deterministic links stay metadata-free.
     */
    private const AUDITED_MATCH_TYPES = [
        SongTitleMatch::TYPE_FIRST_LINE,
        SongTitleMatch::TYPE_FUZZY,
        SongTitleMatch::TYPE_HYMNBOOK_ABSENT,
    ];

    /**
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int,
     *     match_types:array<string,int>
     * }
     */
    public function linkAll(bool $dryRun = false): array
    {
        $query = ChurchServiceItem::query()
            ->where('type', 'songs')
            ->whereNull('deleted_at');

        return $this->linkQuery($query, $dryRun);
    }

    /**
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int,
     *     match_types:array<string,int>
     * }
     */
    public function linkForService(ChurchService $churchService, bool $dryRun = false): array
    {
        $query = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->where('type', 'songs')
            ->whereNull('deleted_at');

        return $this->linkQuery($query, $dryRun);
    }

    /**
     * @param  Builder<ChurchServiceItem>  $query
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int,
     *     match_types:array<string,int>
     * }
     */
    private function linkQuery(Builder $query, bool $dryRun): array
    {
        $resolver = SongTitleResolver::fromDatabase();

        $metrics = [
            'dry_run' => $dryRun,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'match_types' => [],
        ];

        $query->orderBy('id')->chunkById(250, function (Collection $items) use (&$metrics, $resolver, $dryRun): void {
            foreach ($items as $item) {
                $metrics['processed']++;

                $searchTitle = $this->resolveSearchTitle($item);
                $match = $searchTitle === null ? null : $resolver->resolve($searchTitle);

                if ($match === null) {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $metrics['matched']++;
                $metrics['match_types'][$match->matchType] = ($metrics['match_types'][$match->matchType] ?? 0) + 1;

                $auditTrail = $this->auditTrailFor($match);
                $catalogueTitle = $resolver->catalogueTitle($match->songId);
                $title = $catalogueTitle ?? $item->title;

                // Loose comparison: the metadata JSON round-trip turns a 1.0 confidence into
                // the integer 1, which must still count as unchanged on the next run.
                if (
                    $item->song_id === $match->songId
                    && $item->title === $title
                    && data_get($item->metadata, 'song_link') == $auditTrail
                ) {
                    $metrics['unchanged']++;

                    continue;
                }

                if (! $dryRun) {
                    $item->song_id = $match->songId;
                    $item->title = $title;
                    $item->metadata = $this->metadataWithAuditTrail($item, $auditTrail);
                    $item->save();
                }

                $metrics['updated']++;
            }
        });

        return $metrics;
    }

    /**
     * @return array{match_type:string, confidence:float}|null
     */
    private function auditTrailFor(SongTitleMatch $match): ?array
    {
        if (! in_array($match->matchType, self::AUDITED_MATCH_TYPES, true)) {
            return null;
        }

        return [
            'match_type' => $match->matchType,
            'confidence' => $match->confidence,
        ];
    }

    /**
     * @param  array{match_type:string, confidence:float}|null  $auditTrail
     * @return array<string, mixed>|null
     */
    private function metadataWithAuditTrail(ChurchServiceItem $item, ?array $auditTrail): ?array
    {
        $metadata = $item->metadata ?? [];

        if ($auditTrail === null) {
            unset($metadata['song_link']);
        } else {
            $metadata['song_link'] = $auditTrail;
        }

        return $metadata === [] ? null : $metadata;
    }

    private function resolveSearchTitle(ChurchServiceItem $item): ?string
    {
        $linkedSongCanonicalKey = data_get($item->metadata, 'linked_song_canonical_key');
        if (is_string($linkedSongCanonicalKey) && trim($linkedSongCanonicalKey) !== '') {
            return $linkedSongCanonicalKey;
        }

        foreach ([$item->openlp_search_title, $item->source_title, $item->title] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array{dry_run:bool,processed:int,matched:int,unmatched:int,updated:int,unchanged:int,cleared:int,match_types:array<string,int>}  $metrics
     */
    private function clearLinkIfNeeded(ChurchServiceItem $item, bool $dryRun, array &$metrics): void
    {
        if ($item->song_id === null && data_get($item->metadata, 'song_link') === null) {
            $metrics['unchanged']++;

            return;
        }

        if (! $dryRun) {
            $item->song_id = null;
            $item->metadata = $this->metadataWithAuditTrail($item, null);
            $item->save();
        }

        $metrics['cleared']++;
    }
}
