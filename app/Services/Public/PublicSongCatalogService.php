<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class PublicSongCatalogService
{
    use EscapesLikeWildcards;

    public const RANGE_ALL = 'all';

    public const RANGE_RECENT = 'recent';

    /**
     * Build the catalogue query, optionally filtered and ordered by search.
     *
     * When no search is active: full catalogue (all range) or qualifying-only (year range),
     * ordered by usage count → last sung → title.
     *
     * When search is active: all tokens must match somewhere across title, alternate title,
     * authors, CCLI, or lyrics. Results are ordered in two buckets — title/alternate-title
     * matches first, then everything else — each bucket sorted by usage then date then title.
     *
     * @return Builder<Song>
     */
    public function query(string $range = self::RANGE_RECENT, string $search = ''): Builder
    {
        $normalizedRange = $this->normalizeRange($range);
        $tokens = $this->tokenize($search);

        /**
         * Performance Optimization: Only include the large lyrics_plain column when
         * search tokens are present, as lyrics are only needed for snippet generation.
         * This reduces memory usage and DB I/O for the main catalog listing.
         */
        $columns = ['songs.id', 'songs.slug', 'songs.title', 'songs.alternate_title', 'songs.ccli_number'];
        if ($tokens->isNotEmpty()) {
            $columns[] = 'songs.lyrics_plain';
        }

        $query = Song::query()
            ->select($columns)
            ->with([
                'authors' => fn ($q) => $q->select(['id', 'display_name'])->orderBy('display_name'),
                'books' => fn ($q) => $q->select(['song_books.id', 'song_books.name'])->orderBy('song_books.name'),
            ])
            ->selectSub($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('COUNT(*)'), 'usage_count')
            ->selectSub($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('MAX(church_services.date)'), 'last_sung_date');

        if ($normalizedRange === self::RANGE_RECENT) {
            $query->whereExists($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('1'));
        }

        if ($tokens->isNotEmpty()) {
            $this->applyTokenFilters($query, $tokens);
            $this->applySearchOrdering($query, $tokens);
        } else {
            $query
                ->orderByDesc('usage_count')
                ->orderByDesc('last_sung_date')
                ->orderBy('songs.title');
        }

        return $query;
    }

    public function normalizeRange(?string $range): string
    {
        return $range === self::RANGE_RECENT
            ? self::RANGE_RECENT
            : self::RANGE_ALL;
    }

    /**
     * Split a search string into distinct lowercase tokens, stripping empty parts.
     *
     * @return Collection<int, non-empty-string>
     */
    public function tokenize(string $search): Collection
    {
        /** @var list<string> $parts */
        $parts = preg_split('/\s+/', mb_strtolower(trim($search))) ?: [];

        return collect($parts)
            ->filter(fn (string $t): bool => $t !== '')
            ->unique()
            ->values();
    }

    /**
     * Require every token to appear in at least one of: title, alternate title,
     * author display name, CCLI number, or lyrics (fulltext MATCH AGAINST).
     *
     * Each token gets its own grouped WHERE clause so all tokens must be satisfied.
     * Lyrics are matched via MySQL full-text boolean mode to use the fulltext index.
     *
     * @param  Builder<Song>  $query
     * @param  Collection<int, non-empty-string>  $tokens
     */
    private function applyTokenFilters(Builder $query, Collection $tokens): void
    {
        foreach ($tokens as $token) {
            $escaped = $this->escapeLike($token);
            $pattern = "%{$escaped}%";

            // Boolean mode token: prefix with + to require the word, wrap in double-quotes
            // to treat it as a literal phrase rather than a full-text operator.
            $booleanToken = '+"'.str_replace('"', '', $token).'"';

            $query->where(function (Builder $q) use ($pattern, $booleanToken): void {
                $q->where('songs.title', 'like', $pattern)
                    ->orWhere('songs.alternate_title', 'like', $pattern)
                    ->orWhere('songs.ccli_number', 'like', $pattern)
                    ->orWhereHas('authors', fn (Builder $a) => $a->where('display_name', 'like', $pattern))
                    ->orWhereRaw('MATCH(songs.lyrics_plain) AGAINST(? IN BOOLEAN MODE)', [$booleanToken]);
            });
        }
    }

    /**
     * Two-bucket ordering: title/alternate-title matches (bucket 0) before all others (bucket 1),
     * then usage count → last sung → title within each bucket.
     *
     * @param  Builder<Song>  $query
     * @param  Collection<int, non-empty-string>  $tokens
     */
    private function applySearchOrdering(Builder $query, Collection $tokens): void
    {
        // Build one LIKE pattern per token for the title-match bucket check.
        // A song goes in bucket 0 if *any* token matches in title or alternate_title.
        $titleConditions = $tokens->map(function (string $token): array {
            return ['pattern' => '%'.$this->escapeLike($token).'%'];
        });

        $caseParts = $titleConditions->map(fn (array $t): string => 'songs.title LIKE ? OR songs.alternate_title LIKE ?')->implode(' OR ');

        $bindings = $titleConditions->flatMap(fn (array $t): array => [$t['pattern'], $t['pattern']])->all();

        // $caseParts is the same literal fragment joined with ' OR ' — no user input is interpolated; user input flows only through $bindings via ? placeholders.
        // @phpstan-ignore-next-line argument.type
        $query->orderByRaw("CASE WHEN ({$caseParts}) THEN 0 ELSE 1 END", $bindings)
            ->orderByDesc('usage_count')
            ->orderByDesc('last_sung_date')
            ->orderBy('songs.title');
    }

    /**
     * @return Builder<ChurchServiceItem>
     */
    private function qualifyingUsageSubquery(string $range): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->whereColumn('church_service_items.song_id', 'songs.id')
            ->when(
                $range === self::RANGE_RECENT,
                fn (Builder $q): Builder => $q->where('church_services.date', '>=', now()->subYears(3)->startOfDay())
            )
            ->where(function (Builder $q): void {
                // Phase 6.1 policy: OoS items remain eligible unless a completed livestream log exists for the service.
                // Failed, pending, in-progress, or non-livestream logs leave OoS items eligible.
                $q
                    ->whereExists(function (QueryBuilder $logQuery): void {
                        $logQuery->selectRaw('1')
                            ->from('media_processing_logs')
                            ->whereColumn('media_processing_logs.church_service_id', 'church_services.id')
                            ->where('media_processing_logs.processing_type', MediaType::Livestream->value)
                            ->where('media_processing_logs.status', ProcessingStatus::Completed->value)
                            ->whereExists(function (QueryBuilder $sectionQuery): void {
                                $sectionQuery->selectRaw('1')
                                    ->from('service_sections')
                                    ->whereColumn('service_sections.media_processing_log_id', 'media_processing_logs.id')
                                    ->whereColumn('service_sections.church_service_item_id', 'church_service_items.id')
                                    ->where('service_sections.section_type', ServiceSectionType::Song->value)
                                    ->where('service_sections.song_match_type', ServiceSectionSongMatchType::Confirmed->value);
                            });
                    })
                    ->orWhereNotExists(function (QueryBuilder $logQuery): void {
                        $logQuery->selectRaw('1')
                            ->from('media_processing_logs')
                            ->whereColumn('media_processing_logs.church_service_id', 'church_services.id')
                            ->where('media_processing_logs.processing_type', MediaType::Livestream->value)
                            ->where('media_processing_logs.status', ProcessingStatus::Completed->value);
                    });
            });
    }
}
