<?php

declare(strict_types=1);

namespace App\Services\Song\Sync;

use App\Models\Song;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Matches incoming OpenLP song groups against pre-import "legacy" song rows
 * (rows whose canonical_key is missing or still carries a legacy placeholder),
 * so a re-import updates the existing record instead of creating a duplicate.
 *
 * Matching runs in two phases — praise number + title, then title only — and
 * only accepts a match when it is unambiguous in both directions (one legacy
 * candidate for the group, one group claiming the legacy row).
 */
class LegacySongReconciler
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     * @param  array<string, array{song_id: int, deleted: bool}>  $existingSongs
     * @return array<string, array{song_id: int, deleted: bool}>
     */
    public function buildReconciliationMap(array $songGroups, array $existingSongs): array
    {
        $legacySongs = $this->fetchLegacySongsForReconciliation();
        if ($legacySongs === []) {
            return [];
        }

        $lookupState = $this->prepareLegacyLookupState($legacySongs);

        $acceptedMatches = [];
        $reservedLegacyIds = [];

        // Phase 1: Match by Praise number AND Title
        $this->matchLegacySongsByPraiseAndTitle(
            $songGroups,
            $existingSongs,
            $lookupState,
            $acceptedMatches,
            $reservedLegacyIds
        );

        // Phase 2: Match by Title only (excluding already matched)
        $this->matchLegacySongsByTitleOnly(
            $songGroups,
            $existingSongs,
            $lookupState,
            $acceptedMatches,
            $reservedLegacyIds
        );

        return $acceptedMatches;
    }

    /**
     * Prepare lookups for legacy song reconciliation.
     *
     * @param  list<Song>  $legacySongs
     * @return array{
     *     byPraiseAndTitle: array<string, list<int>>,
     *     byTitle: array<string, list<int>>,
     *     stateById: array<int, array{song_id: int, deleted: bool}>
     * }
     */
    private function prepareLegacyLookupState(array $legacySongs): array
    {
        $byPraiseAndTitle = [];
        $byTitle = [];
        $stateById = [];

        foreach ($legacySongs as $legacySong) {
            $stateById[$legacySong->id] = [
                'song_id' => $legacySong->id,
                'deleted' => $legacySong->deleted_at !== null,
            ];

            $titleVariants = $this->legacyTitleVariants($legacySong);
            $praiseNumber = $this->normalizedPraiseNumber($legacySong->getAttribute('praise_number'));

            foreach ($titleVariants as $titleVariant) {
                $byTitle[$titleVariant][] = $legacySong->id;

                if ($praiseNumber !== null) {
                    $byPraiseAndTitle[$praiseNumber.'|'.$titleVariant][] = $legacySong->id;
                }
            }
        }

        return [
            'byPraiseAndTitle' => $byPraiseAndTitle,
            'byTitle' => $byTitle,
            'stateById' => $stateById,
        ];
    }

    /**
     * Match legacy songs using both praise number and title.
     *
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     * @param  array<string, array{song_id: int, deleted: bool}>  $existingSongs
     * @param  array{
     *     byPraiseAndTitle: array<string, list<int>>,
     *     byTitle: array<string, list<int>>,
     *     stateById: array<int, array{song_id: int, deleted: bool}>
     * }  $lookupState
     * @param  array<string, array{song_id: int, deleted: bool}>  $acceptedMatches
     * @param  array<int, bool>  $reservedLegacyIds
     */
    private function matchLegacySongsByPraiseAndTitle(
        array $songGroups,
        array $existingSongs,
        array $lookupState,
        array &$acceptedMatches,
        array &$reservedLegacyIds
    ): void {
        $praiseCandidates = [];

        foreach ($songGroups as $canonicalKey => $groupRows) {
            if (array_key_exists($canonicalKey, $existingSongs)) {
                continue;
            }

            $candidateIds = $this->candidateLegacySongIdsByPraiseAndTitle($groupRows, $lookupState['byPraiseAndTitle']);

            if (count($candidateIds) === 1) {
                $praiseCandidates[$canonicalKey] = $candidateIds[0];
            }
        }

        foreach ($this->acceptUniqueCandidateMatches($praiseCandidates) as $canonicalKey => $legacySongId) {
            $acceptedMatches[$canonicalKey] = $lookupState['stateById'][$legacySongId];
            $reservedLegacyIds[$legacySongId] = true;
        }
    }

    /**
     * Match legacy songs using title only.
     *
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     * @param  array<string, array{song_id: int, deleted: bool}>  $existingSongs
     * @param  array{
     *     byPraiseAndTitle: array<string, list<int>>,
     *     byTitle: array<string, list<int>>,
     *     stateById: array<int, array{song_id: int, deleted: bool}>
     * }  $lookupState
     * @param  array<string, array{song_id: int, deleted: bool}>  $acceptedMatches
     * @param  array<int, bool>  $reservedLegacyIds
     */
    private function matchLegacySongsByTitleOnly(
        array $songGroups,
        array $existingSongs,
        array $lookupState,
        array &$acceptedMatches,
        array &$reservedLegacyIds
    ): void {
        $titleCandidates = [];

        foreach ($songGroups as $canonicalKey => $groupRows) {
            if (array_key_exists($canonicalKey, $existingSongs) || array_key_exists($canonicalKey, $acceptedMatches)) {
                continue;
            }

            $candidateIds = $this->candidateLegacySongIdsByTitle($groupRows, $lookupState['byTitle'], $reservedLegacyIds);

            if (count($candidateIds) === 1) {
                $titleCandidates[$canonicalKey] = $candidateIds[0];
            }
        }

        foreach ($this->acceptUniqueCandidateMatches($titleCandidates) as $canonicalKey => $legacySongId) {
            $acceptedMatches[$canonicalKey] = $lookupState['stateById'][$legacySongId];
        }
    }

    /**
     * @return list<Song>
     */
    private function fetchLegacySongsForReconciliation(): array
    {
        $selectColumns = ['id', 'canonical_key', 'title', 'deleted_at'];

        if (Schema::hasColumn('songs', 'praise_number')) {
            $selectColumns[] = 'praise_number';
        }

        if (Schema::hasColumn('songs', 'alternative_title')) {
            $selectColumns[] = 'alternative_title';
        }

        if (Schema::hasColumn('songs', 'alternate_title')) {
            $selectColumns[] = 'alternate_title';
        }

        /** @var list<Song> $legacySongs */
        $legacySongs = Song::query()
            ->withTrashed()
            ->where(function ($query): void {
                $query->whereNull('canonical_key')
                    ->orWhere('canonical_key', '')
                    ->orWhere('canonical_key', 'like', 'legacy-song-%');
            })
            ->get($selectColumns)
            ->all();

        return $legacySongs;
    }

    /**
     * @return list<string>
     */
    private function legacyTitleVariants(Song $song): array
    {
        $variants = [];

        foreach (['title', 'alternative_title', 'alternate_title'] as $attribute) {
            $normalizedTitle = $this->normalizedSongTitle($song->getAttribute($attribute));

            if ($normalizedTitle === null || in_array($normalizedTitle, $variants, true)) {
                continue;
            }

            $variants[] = $normalizedTitle;
        }

        return $variants;
    }

    /**
     * @param  list<array<string, mixed>>  $groupRows
     * @param  array<string, list<int>>  $legacyByPraiseAndTitle
     * @return list<int>
     */
    private function candidateLegacySongIdsByPraiseAndTitle(array $groupRows, array $legacyByPraiseAndTitle): array
    {
        $candidateIds = [];

        foreach ($this->sourcePraiseNumbers($groupRows) as $praiseNumber) {
            foreach ($this->sourceTitleVariants($groupRows) as $titleVariant) {
                foreach ($legacyByPraiseAndTitle[$praiseNumber.'|'.$titleVariant] ?? [] as $legacySongId) {
                    $candidateIds[$legacySongId] = true;
                }
            }
        }

        return array_map('intval', array_keys($candidateIds));
    }

    /**
     * @param  list<array<string, mixed>>  $groupRows
     * @param  array<string, list<int>>  $legacyByTitle
     * @param  array<int, bool>  $reservedLegacyIds
     * @return list<int>
     */
    private function candidateLegacySongIdsByTitle(array $groupRows, array $legacyByTitle, array $reservedLegacyIds): array
    {
        $candidateIds = [];

        foreach ($this->sourceTitleVariants($groupRows) as $titleVariant) {
            foreach ($legacyByTitle[$titleVariant] ?? [] as $legacySongId) {
                if (array_key_exists($legacySongId, $reservedLegacyIds)) {
                    continue;
                }

                $candidateIds[$legacySongId] = true;
            }
        }

        return array_map('intval', array_keys($candidateIds));
    }

    /**
     * @param  array<string, int>  $candidates
     * @return array<string, int>
     */
    private function acceptUniqueCandidateMatches(array $candidates): array
    {
        $countsByLegacySongId = [];

        foreach ($candidates as $legacySongId) {
            $countsByLegacySongId[$legacySongId] = ($countsByLegacySongId[$legacySongId] ?? 0) + 1;
        }

        $accepted = [];

        foreach ($candidates as $canonicalKey => $legacySongId) {
            if (($countsByLegacySongId[$legacySongId] ?? 0) !== 1) {
                continue;
            }

            $accepted[$canonicalKey] = $legacySongId;
        }

        return $accepted;
    }

    /**
     * @param  list<array<string, mixed>>  $groupRows
     * @return list<string>
     */
    private function sourceTitleVariants(array $groupRows): array
    {
        $variants = [];

        foreach ($groupRows as $groupRow) {
            foreach (['title', 'alternate_title'] as $field) {
                $normalizedTitle = $this->normalizedSongTitle($groupRow[$field] ?? null);

                if ($normalizedTitle === null || in_array($normalizedTitle, $variants, true)) {
                    continue;
                }

                $variants[] = $normalizedTitle;
            }
        }

        return $variants;
    }

    /**
     * @param  list<array<string, mixed>>  $groupRows
     * @return list<string>
     */
    private function sourcePraiseNumbers(array $groupRows): array
    {
        $praiseNumbers = [];

        foreach ($groupRows as $groupRow) {
            $praiseNumber = $this->sourcePraiseNumber($groupRow);

            if ($praiseNumber === null || in_array($praiseNumber, $praiseNumbers, true)) {
                continue;
            }

            $praiseNumbers[] = $praiseNumber;
        }

        return $praiseNumbers;
    }

    /**
     * @param  array<string, mixed>  $sourceRow
     */
    private function sourcePraiseNumber(array $sourceRow): ?string
    {
        $title = OpenLpRowValue::stringOrNull($sourceRow['title'] ?? null);

        if ($title !== null && preg_match('/#\s*([A-Za-z0-9]+)\s*$/u', $title, $matches) === 1) {
            return $this->normalizedPraiseNumber($matches[1]);
        }

        $searchTitle = OpenLpRowValue::stringOrNull($sourceRow['search_title'] ?? null);

        if ($searchTitle === null) {
            return null;
        }

        $primarySearchTitle = trim((string) strtok($searchTitle, '@'));

        if (preg_match('/\b([0-9]+[A-Za-z]?)$/u', $primarySearchTitle, $matches) === 1) {
            return $this->normalizedPraiseNumber($matches[1]);
        }

        if (preg_match('/^([0-9]+[A-Za-z]?)\b/u', $primarySearchTitle, $matches) === 1) {
            return $this->normalizedPraiseNumber($matches[1]);
        }

        return null;
    }

    private function normalizedSongTitle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(Str::lower($value));

        if ($normalized === '') {
            return null;
        }

        $normalized = (string) preg_replace('/\s*#\s*[a-z0-9]+$/u', '', $normalized);
        $normalized = (string) preg_replace('/[^a-z0-9]+/u', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizedPraiseNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(\d+)([A-Z]?)$/', $normalized, $matches) !== 1) {
            return $normalized;
        }

        $number = ltrim($matches[1], '0');

        if ($number === '') {
            $number = '0';
        }

        return $number.$matches[2];
    }
}
