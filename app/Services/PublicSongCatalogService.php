<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PublicSongCatalogService
{
    public const RANGE_ALL = 'all';

    public const RANGE_THIS_YEAR = 'year';

    /**
     * Build the base catalogue query.
     *
     * Unlike PublicSongUsageService, the `all` range includes songs with zero usage.
     * The `year` range still filters to songs with qualifying usage in the current year.
     *
     * @return Builder<Song>
     */
    public function query(string $range = self::RANGE_ALL): Builder
    {
        $normalizedRange = $this->normalizeRange($range);

        $query = Song::query()
            ->select(['songs.id', 'songs.slug', 'songs.title', 'songs.alternate_title', 'songs.ccli_number', 'songs.lyrics_plain'])
            ->with([
                'authors' => fn ($q) => $q->select(['id', 'display_name'])->orderBy('display_name'),
            ])
            ->selectSub($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('COUNT(*)'), 'usage_count')
            ->selectSub($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('MAX(church_services.date)'), 'last_sung_date')
            ->orderByDesc('usage_count')
            ->orderByDesc('last_sung_date')
            ->orderBy('songs.title');

        // For the `year` range, only include songs with qualifying usage this year.
        // For `all`, include the full catalogue (no whereExists constraint).
        if ($normalizedRange === self::RANGE_THIS_YEAR) {
            $query->whereExists($this->qualifyingUsageSubquery($normalizedRange)->selectRaw('1'));
        }

        return $query;
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
    private function qualifyingUsageSubquery(string $range): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->whereColumn('church_service_items.song_id', 'songs.id')
            ->when(
                $range === self::RANGE_THIS_YEAR,
                fn (Builder $q): Builder => $q->whereYear('church_services.date', now()->year)
            )
            ->where(function (Builder $q): void {
                // Phase 6.1 policy: when a completed livestream processing log exists for a service,
                // only count songs with a confirmed section match. Services without any processing
                // log use the order-of-service items directly.
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
                                    ->where('service_sections.section_type', ServiceSectionType::SONG->value)
                                    ->where('service_sections.song_match_type', ServiceSectionSongMatchType::CONFIRMED->value);
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
