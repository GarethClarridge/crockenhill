<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ChurchServiceProcessingRunQuery
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    public function forService(ChurchService $churchService): EloquentCollection
    {
        $serviceDate = $churchService->date->toDateString();
        $serviceType = $churchService->service;
        $fallbackProcessingIds = $this->fallbackProcessingIdsForService($churchService);

        return MediaProcessingLog::query()
            ->livestream()
            ->with([
                'serviceSections' => fn ($query) => $query
                    ->with([
                        'publishedSermon:id,title,slug,content_type',
                        'churchServiceItem' => fn ($query) => $query->withTrashed()->with('song:id,title'),
                    ])
                    ->orderBy('section_order')
                    ->orderBy('id'),
                'processingSteps' => fn ($query) => $query
                    ->whereIn('step', ChurchServiceProcessingTimeline::stepKeys())
                    ->orderBy('started_at')
                    ->orderBy('id'),
            ])
            ->where(function (Builder $query) use ($churchService, $fallbackProcessingIds, $serviceDate, $serviceType): void {
                $this->identityResolver->scopeMatchesIdentity($query, $serviceDate, $serviceType);

                $query->orWhere('church_service_id', $churchService->id);

                if ($fallbackProcessingIds !== []) {
                    $query->orWhereIn('processing_id', $fallbackProcessingIds);
                }
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function matchesService(MediaProcessingLog $processingLog, ChurchService $churchService): bool
    {
        $serviceDate = $churchService->date->toDateString();
        $serviceType = $churchService->service;

        if ($this->identityResolver->matchesService($processingLog, $serviceDate, $serviceType)) {
            return true;
        }

        if ($processingLog->church_service_id === $churchService->id) {
            return true;
        }

        return in_array($processingLog->processing_id, $this->fallbackProcessingIdsForService($churchService), true);
    }

    /**
     * @return list<string>
     */
    private function fallbackProcessingIdsForService(ChurchService $churchService): array
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
