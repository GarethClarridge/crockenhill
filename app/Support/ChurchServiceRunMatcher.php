<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for deciding whether a MediaProcessingLog belongs to
 * a ChurchService. A run matches when ANY of three paths hit:
 *
 *  (a) extracted date + service slot identity match,
 *  (b) explicit `church_service_id` foreign key,
 *  (c) fallback processing ids harvested from `items.livestream_processing_id`
 *      and `import_metadata.livestream_projection.processing_id` (repaired runs).
 *
 * Both ChurchServiceProcessingRunQuery (per-service detail page) and
 * ChurchServiceRollupQuery (bulk hub rollup) consume this class so the
 * repaired-run regression coverage keeps one implementation honest.
 */
class ChurchServiceRunMatcher
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    /**
     * Add the three-path match clauses as a nested where group.
     *
     * Callers resolve the fallback ids up front so bulk consumers can harvest
     * them from an already eager-loaded `items` relation without per-row queries.
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @param  list<string>  $fallbackProcessingIds
     */
    public function applyMatchClauses(Builder $query, ChurchService $churchService, array $fallbackProcessingIds): void
    {
        $serviceDate = $churchService->date->toDateString();
        $serviceType = $churchService->service;

        $query->where(function (Builder $query) use ($churchService, $fallbackProcessingIds, $serviceDate, $serviceType): void {
            $this->identityResolver->scopeMatchesIdentity($query, $serviceDate, $serviceType);

            $query->orWhere('church_service_id', $churchService->id);

            if ($fallbackProcessingIds !== []) {
                $query->orWhereIn('processing_id', $fallbackProcessingIds);
            }
        });
    }

    /**
     * @param  list<string>|null  $fallbackProcessingIds  pass pre-harvested ids in bulk paths
     */
    public function matches(MediaProcessingLog $processingLog, ChurchService $churchService, ?array $fallbackProcessingIds = null): bool
    {
        $fallbackProcessingIds ??= $this->fallbackProcessingIdsForService($churchService);

        if ($this->identityResolver->matchesService($processingLog, $churchService->date->toDateString(), $churchService->service)) {
            return true;
        }

        if ($processingLog->church_service_id === $churchService->id) {
            return true;
        }

        return in_array($processingLog->processing_id, $fallbackProcessingIds, true);
    }

    /**
     * @return list<string>
     */
    public function fallbackProcessingIdsForService(ChurchService $churchService): array
    {
        $churchService->loadMissing('items');

        $processingIds = [];

        foreach ($churchService->items as $item) {
            if (is_string($item->livestream_processing_id) && trim($item->livestream_processing_id) !== '') {
                $processingIds[] = $item->livestream_processing_id;
            }
        }

        $serviceMetadata = $churchService->import_metadata?->toArray() ?? [];
        $serviceProjection = $serviceMetadata['livestream_projection'] ?? [];
        if (is_string($serviceProjection['processing_id'] ?? null) && trim($serviceProjection['processing_id']) !== '') {
            $processingIds[] = $serviceProjection['processing_id'];
        }

        return array_values(array_unique($processingIds));
    }
}
