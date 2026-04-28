<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ServiceTimelineBuilder
{
    /**
     * Build merged service timelines for all runs, keyed by run ID.
     *
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @param  EloquentCollection<int, ChurchServiceItem>  $items  Already-loaded with song relation
     * @return array<int, list<array<string, mixed>>>
     */
    public static function buildTimelines(EloquentCollection $processingRuns, EloquentCollection $items): array
    {
        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $run): array => [
                $run->id => ServiceRecordTimeline::build($items, $run),
            ])
            ->all();
    }

    /**
     * Build human-readable service flows for all runs, keyed by run ID.
     *
     * @param  array<int, list<array<string, mixed>>>  $serviceTimelines  Output from buildTimelines()
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, list<array<string, mixed>>>
     */
    public static function buildFlows(array $serviceTimelines, EloquentCollection $processingRuns): array
    {
        return collect($serviceTimelines)
            ->mapWithKeys(function (array $rows, int $runId) use ($processingRuns): array {
                $run = $processingRuns->find($runId);
                if (! $run instanceof MediaProcessingLog) {
                    return [$runId => []];
                }

                return [$runId => ServiceFlowBuilder::build($rows, $run)];
            })
            ->all();
    }
}
