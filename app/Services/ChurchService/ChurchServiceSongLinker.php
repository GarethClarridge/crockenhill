<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChurchServiceSongLinker
{
    /**
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int
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
     *     cleared:int
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
     *     cleared:int
     * }
     */
    private function linkQuery(Builder $query, bool $dryRun): array
    {
        $lookups = $this->buildSongLookups();

        $metrics = [
            'dry_run' => $dryRun,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
        ];

        $query->orderBy('id')->chunkById(250, function (Collection $items) use (&$metrics, $lookups, $dryRun): void {
            foreach ($items as $item) {
                $metrics['processed']++;

                $searchTitle = $this->resolveSearchTitle($item);
                if ($searchTitle === null) {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $songId = $this->resolveSongId($searchTitle, $lookups);
                if ($songId === null) {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $metrics['matched']++;

                if ($item->song_id === $songId) {
                    $metrics['unchanged']++;

                    continue;
                }

                if (! $dryRun) {
                    $item->song_id = $songId;
                    $item->save();
                }

                $metrics['updated']++;
            }
        });

        return $metrics;
    }

    /**
     * Three lookup tables built in one pass: exact canonical key; Praise! number (emails
     * prefix it, the library holds it in praise_number); and the number-stripped canonical
     * key, because OpenLP-imported keys embed the hymn number as a suffix ("all heaven
     * declares 477") that a plain email title can never equal. Ambiguous stripped keys
     * (two hymns sharing a title) are dropped rather than guessed.
     *
     * @return array{exact:array<string,int>,number:array<string,int>,stripped:array<string,int>}
     */
    private function buildSongLookups(): array
    {
        $exact = [];
        $number = [];
        $stripped = [];
        $ambiguousStripped = [];

        foreach (Song::query()->get(['id', 'canonical_key', 'praise_number']) as $song) {
            $songId = (int) $song->id;
            $exact[(string) $song->canonical_key] = $songId;

            if (is_string($song->praise_number) && trim($song->praise_number) !== '') {
                $number[Song::normalisePraiseNumber($song->praise_number)] = $songId;
            }

            $strippedKey = $this->stripTrailingNumber((string) $song->canonical_key);
            if ($strippedKey === '' || $strippedKey === (string) $song->canonical_key) {
                continue;
            }

            if (isset($stripped[$strippedKey]) && $stripped[$strippedKey] !== $songId) {
                $ambiguousStripped[$strippedKey] = true;
            }

            $stripped[$strippedKey] = $songId;
        }

        foreach (array_keys($ambiguousStripped) as $ambiguousKey) {
            unset($stripped[$ambiguousKey]);
        }

        return ['exact' => $exact, 'number' => $number, 'stripped' => $stripped];
    }

    /**
     * @param  array{exact:array<string,int>,number:array<string,int>,stripped:array<string,int>}  $lookups
     */
    private function resolveSongId(string $searchTitle, array $lookups): ?int
    {
        $canonicalKey = Song::canonicalizeKey($searchTitle);
        if ($canonicalKey === '') {
            return null;
        }

        if (isset($lookups['exact'][$canonicalKey])) {
            return $lookups['exact'][$canonicalKey];
        }

        $leadingNumber = $this->leadingPraiseNumber($searchTitle);
        if ($leadingNumber !== null && isset($lookups['number'][$leadingNumber])) {
            return $lookups['number'][$leadingNumber];
        }

        $strippedKey = $this->stripEmailDecoration($searchTitle);

        return $strippedKey === '' ? null : ($lookups['stripped'][$strippedKey] ?? $lookups['exact'][$strippedKey] ?? null);
    }

    /**
     * The Praise! number an email title leads with ("299 'How sweet…'"). A number running
     * straight into more digits or punctuation ("10,000 Reasons") is part of the title.
     */
    private function leadingPraiseNumber(string $title): ?string
    {
        if (preg_match('/^\s*(\d{1,4}[a-z]?)(?![\d,.])\b/iu', trim($title), $matches) === 1) {
            return Song::normalisePraiseNumber($matches[1]);
        }

        return null;
    }

    /**
     * Canonical key of an email title with its leading book-number decoration removed:
     * an optional NIP/Praise marker, the number itself, and any surrounding quotes.
     */
    private function stripEmailDecoration(string $title): string
    {
        $bare = (string) preg_replace('/^\s*(?:NIP|Praise)?\s*\d{1,4}[a-z]?(?![\d,.])\s*/iu', '', $title);
        $bare = trim($bare, " \t\"'\u{2018}\u{2019}\u{201C}\u{201D}");

        return Song::canonicalizeKey($bare);
    }

    /**
     * Canonical key of a library entry with its trailing hymn-number suffix removed
     * ("all heaven declares 477" → "all heaven declares").
     */
    private function stripTrailingNumber(string $canonicalKey): string
    {
        return trim((string) preg_replace('/\s+\d{1,4}[a-z]?$/i', '', $canonicalKey));
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
     * @param  array{dry_run:bool,processed:int,matched:int,unmatched:int,updated:int,unchanged:int,cleared:int}  $metrics
     */
    private function clearLinkIfNeeded(ChurchServiceItem $item, bool $dryRun, array &$metrics): void
    {
        if ($item->song_id === null) {
            $metrics['unchanged']++;

            return;
        }

        if (! $dryRun) {
            $item->song_id = null;
            $item->save();
        }

        $metrics['cleared']++;
    }
}
