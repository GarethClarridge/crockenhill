<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ChurchServiceRunMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ChurchServiceProcessingRunQuery
{
    public function __construct(
        private readonly ChurchServiceRunMatcher $runMatcher,
    ) {}

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    public function forService(ChurchService $churchService): EloquentCollection
    {
        $fallbackProcessingIds = $this->runMatcher->fallbackProcessingIdsForService($churchService);

        return MediaProcessingLog::query()
            ->segmentationPipeline()
            ->notSuperseded()
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
            ->tap(fn ($query) => $this->runMatcher->applyMatchClauses($query, $churchService, $fallbackProcessingIds))
            ->orderByDesc('created_at')
            ->get();
    }

    public function matchesService(MediaProcessingLog $processingLog, ChurchService $churchService): bool
    {
        return $this->runMatcher->matches($processingLog, $churchService);
    }

    public function matchedServiceUrl(MediaProcessingLog $processingLog): ?string
    {
        if (! config('service-tracking.enabled')) {
            return null;
        }

        $service = $processingLog->church_service_id !== null
            ? ChurchService::find($processingLog->church_service_id)
            : null;

        if ($service === null && $processingLog->extracted_date !== null && $processingLog->extracted_service !== null) {
            $service = ChurchService::query()
                ->whereDate('date', $processingLog->extracted_date)
                ->where('service', $processingLog->extracted_service->value)
                ->first();
        }

        if ($service === null) {
            $service = ChurchServiceItem::query()
                ->where('livestream_processing_id', $processingLog->processing_id)
                ->with('churchService')
                ->first()?->churchService;
        }

        return $service === null ? null : route('admin.services.show', $service);
    }
}
