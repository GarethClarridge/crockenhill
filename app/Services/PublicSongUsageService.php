<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PublicSongUsageService
{
    public const RANGE_ALL = 'all';

    public const RANGE_THIS_YEAR = 'year';

    /**
     * @return Builder<Song>
     */
    public function query(string $range = self::RANGE_ALL): Builder
    {
        $normalizedRange = $this->normalizeRange($range);

        return Song::query()
            ->select('songs.*')
            ->with([
                'authors' => fn ($query) => $query->orderBy('display_name'),
            ])
            ->selectSub($this->qualifyingUsageItemsQuery($normalizedRange)->selectRaw('COUNT(*)'), 'usage_count')
            ->selectSub($this->qualifyingUsageItemsQuery($normalizedRange)->selectRaw('MAX(church_services.date)'), 'last_sung_date')
            ->whereExists($this->qualifyingUsageItemsQuery($normalizedRange)->selectRaw('1'))
            ->orderByDesc('usage_count')
            ->orderByDesc('last_sung_date')
            ->orderBy('songs.title');
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
    private function qualifyingUsageItemsQuery(string $range): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereColumn('church_service_items.song_id', 'songs.id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->when(
                $range === self::RANGE_THIS_YEAR,
                fn (Builder $query): Builder => $query->whereYear('church_services.date', now()->year)
            )
            ->where(function (Builder $query): void {
                // Current Phase 6.1 policy: once any processing log exists for a service,
                // we stop trusting the OoS directly and only count detected livestream songs.
                $query
                    ->whereExists(function (QueryBuilder $logQuery): void {
                        $logQuery->selectRaw('1')
                            ->from('media_processing_logs')
                            ->whereColumn('media_processing_logs.church_service_id', 'church_services.id')
                            ->where('media_processing_logs.processing_type', MediaType::Livestream->value)
                            ->where('media_processing_logs.status', ProcessingStatus::COMPLETED->value)
                            ->whereExists(function (QueryBuilder $sectionQuery): void {
                                $sectionQuery->selectRaw('1')
                                    ->from('service_sections')
                                    ->whereColumn('service_sections.media_processing_log_id', 'media_processing_logs.id')
                                    ->whereColumn('service_sections.church_service_item_id', 'church_service_items.id')
                                    ->where('service_sections.section_type', ServiceSectionType::SONG->value);
                            });
                    })
                    ->orWhereNotExists(function (QueryBuilder $logQuery): void {
                        $logQuery->selectRaw('1')
                            ->from('media_processing_logs')
                            ->whereColumn('media_processing_logs.church_service_id', 'church_services.id');
                    });
            });
    }
}
