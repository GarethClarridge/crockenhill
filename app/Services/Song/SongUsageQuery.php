<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Models\ChurchServiceItem;
use App\Models\SongUsageReport;
use App\Services\Public\PublicServiceContentEligibility;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class SongUsageQuery
{
    public function __construct(
        private PublicServiceContentEligibility $eligibility,
    ) {}

    /**
     * A normalized occurrence relation used by correlated song-catalogue subqueries. Resolved
     * reports are excluded because their canonical service item supplies the occurrence.
     *
     * `$publicOnly` gates the reported side the same way it gates the canonical side: admin
     * screens see imported hymn evidence as soon as it lands, visitors only once it is released.
     */
    public function occurrences(bool $publicOnly): QueryBuilder
    {
        $canonical = ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->when($publicOnly, fn ($query) => $this->eligibility->applySongItemEligibility($query))
            ->selectRaw("church_service_items.song_id AS song_id, church_services.date AS used_on, church_services.service AS reported_service, CONCAT('service:', church_services.id) AS service_identity");

        $reported = SongUsageReport::query()
            ->whereNotNull('song_id')
            ->whereNull('resolved_church_service_item_id')
            ->when($publicOnly, fn (EloquentBuilder $query) => $query->publiclyReleased())
            ->selectRaw("song_id, used_on, reported_service, CONCAT('report:', id) AS service_identity");

        return DB::query()->fromSub($canonical->toBase()->unionAll($reported->toBase()), 'song_usage_occurrences');
    }
}
