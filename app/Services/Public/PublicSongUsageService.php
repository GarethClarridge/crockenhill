<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class PublicSongUsageService
{
    public const RANGE_ALL = 'all';

    public const RANGE_THIS_YEAR = 'year';

    public function __construct(
        private readonly PublicServiceContentEligibility $eligibility,
    ) {}

    /**
     * @return array{usage_count: int, last_sung_date: string|null}
     */
    public function statsForSong(Song $song, string $range = self::RANGE_ALL): array
    {
        /** @var array<string, mixed> $stats */
        $stats = (array) ($this->qualifyingUsageItemsQueryForSong($song, $range)
            ->selectRaw('COUNT(*) AS usage_count')
            ->selectRaw('MAX(church_services.date) AS last_sung_date')
            ->toBase()
            ->first() ?? []);

        return [
            'usage_count' => is_numeric($stats['usage_count'] ?? null) ? (int) $stats['usage_count'] : 0,
            'last_sung_date' => is_string($stats['last_sung_date'] ?? null) ? $stats['last_sung_date'] : null,
        ];
    }

    /**
     * @return EloquentCollection<int, ChurchServiceItem>
     */
    public function usageHistoryForSong(Song $song, int $limit = 40): EloquentCollection
    {
        /**
         * Performance Optimization: Limits retrieved columns for usage history items,
         * excluding large JSON metadata blobs to reduce memory usage.
         */
        return $this->qualifyingUsageItemsQueryForSong($song)
            ->select(['church_service_items.id', 'church_service_items.church_service_id', 'church_service_items.title', 'church_service_items.position'])
            ->with([
                'churchService' => fn ($query) => $query->select(['id', 'date', 'service']),
            ])
            ->orderByDesc('church_services.date')
            ->orderByDesc('church_service_items.position')
            ->limit($limit)
            ->get();
    }

    public function normalizeRange(?string $range): string
    {
        return $range === self::RANGE_THIS_YEAR
            ? self::RANGE_THIS_YEAR
            : self::RANGE_ALL;
    }

    /**
     * @return Builder<ChurchServiceItem>
     */
    private function qualifyingUsageItemsQueryForSong(Song $song, string $range = self::RANGE_ALL): Builder
    {
        return $this->baseQualifyingUsageItemsQuery($this->normalizeRange($range))
            ->where('church_service_items.song_id', $song->id);
    }

    /**
     * @return Builder<ChurchServiceItem>
     */
    private function baseQualifyingUsageItemsQuery(string $range): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->when(
                $range === self::RANGE_THIS_YEAR,
                fn (Builder $query): Builder => $query->whereYear('church_services.date', now()->year)
            )
            ->tap(fn (Builder $query) => $this->eligibility->applySongItemEligibility($query));
    }
}
