<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Data\SongUsageOccurrence;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongUsageReport;
use App\Services\Song\SongUsageQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PublicSongUsageService
{
    public const RANGE_ALL = 'all';

    public const RANGE_THIS_YEAR = 'year';

    public function __construct(
        private PublicServiceContentEligibility $eligibility,
        private SongUsageQuery $usageQuery,
    ) {}

    /** @return array{usage_count: int, last_sung_date: string|null} */
    public function statsForSong(Song $song, string $range = self::RANGE_ALL): array
    {
        $stats = $this->usageQuery->occurrences(publicOnly: true)
            ->where('song_id', $song->id)
            ->when(
                $this->normalizeRange($range) === self::RANGE_THIS_YEAR,
                fn ($query) => $query->whereYear('used_on', now()->year),
            )
            ->selectRaw('COUNT(*) AS usage_count, MAX(used_on) AS last_sung_date')
            ->first();

        return [
            'usage_count' => is_numeric($stats?->usage_count) ? (int) $stats->usage_count : 0,
            'last_sung_date' => is_string($stats?->last_sung_date) ? $stats->last_sung_date : null,
        ];
    }

    /** @return Collection<int, SongUsageOccurrence> */
    public function usageHistoryForSong(Song $song, int $limit = 40): Collection
    {
        $canonical = $this->canonicalHistory($song, $limit)
            ->map(fn (ChurchServiceItem $item): SongUsageOccurrence => new SongUsageOccurrence(
                sourceId: $item->id,
                sourceType: 'service_item',
                date: $item->churchService->date,
                service: $item->churchService->service,
                title: $item->title,
                churchService: $item->churchService,
            ));

        $reported = SongUsageReport::query()
            ->where('song_id', $song->id)
            ->whereNull('resolved_church_service_item_id')
            ->orderByDesc('used_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SongUsageReport $report): SongUsageOccurrence => new SongUsageOccurrence(
                sourceId: $report->id,
                sourceType: 'usage_report',
                date: $report->used_on,
                service: $report->reported_service,
                title: $report->reported_title,
                churchService: null,
            ));

        return $canonical
            ->concat($reported)
            ->sortByDesc(fn (SongUsageOccurrence $occurrence): string => $occurrence->date->format('Y-m-d').sprintf('%010d', $occurrence->sourceId))
            ->take($limit)
            ->values();
    }

    public function normalizeRange(?string $range): string
    {
        return $range === self::RANGE_THIS_YEAR ? self::RANGE_THIS_YEAR : self::RANGE_ALL;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ChurchServiceItem> */
    private function canonicalHistory(Song $song, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->where('church_service_items.song_id', $song->id)
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->tap(fn (Builder $query) => $this->eligibility->applySongItemEligibility($query))
            ->select(['church_service_items.id', 'church_service_items.church_service_id', 'church_service_items.title', 'church_service_items.position'])
            ->with(['churchService' => fn ($query) => $query->select(['id', 'date', 'service'])])
            ->orderByDesc('church_services.date')
            ->orderByDesc('church_service_items.position')
            ->limit($limit)
            ->get();
    }
}
