<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PublicSongUsageService
{
    public const RANGE_ALL = 'all';

    public const RANGE_THIS_YEAR = 'year';

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
            ->where(function (Builder $query): void {
                // Phase 6.1 policy: OoS items remain eligible unless a completed livestream log exists for the service.
                // Failed, pending, in-progress, or non-livestream logs leave OoS items eligible.
                $query
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
