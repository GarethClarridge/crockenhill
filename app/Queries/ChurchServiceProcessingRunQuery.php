<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ChurchService;
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
}
