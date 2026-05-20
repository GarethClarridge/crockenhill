<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Data\ChurchServiceProcessingRunView;
use App\Data\ChurchServiceShowReadModel;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Queries\ChurchServiceProcessingRunQuery;
use App\Support\ProcessingRunTimelineBuilder;
use App\Support\ServiceTimelineBuilder;
use Illuminate\Database\Eloquent\Collection;

class ChurchServiceShowPresenter
{
    public function __construct(
        private readonly ChurchServiceProcessingRunQuery $processingRunQuery,
    ) {}

    public function present(ChurchService $churchService): ChurchServiceShowReadModel
    {
        $churchService->load([
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $importMetadata = $churchService->import_metadata?->toArray() ?? [];
        $warnings = is_array($importMetadata['warnings'] ?? null)
            ? array_values(array_filter($importMetadata['warnings'], is_string(...)))
            : [];
        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        $pendingMerge = $churchService->import_metadata?->pendingStructureMerge;
        $pendingMergeSource = is_string($churchService->pending_structure_merge_source)
            ? trim($churchService->pending_structure_merge_source)
            : null;
        $hasPendingMerge = $pendingMerge !== null && $pendingMergeSource !== null && $pendingMergeSource !== '';
        $processingRuns = $this->processingRunQuery->forService($churchService);

        /** @var Collection<int, ChurchServiceItem> $items */
        $items = $churchService->items;
        $serviceTimelines = ServiceTimelineBuilder::buildTimelines($processingRuns, $items);
        $processingTimelines = ProcessingRunTimelineBuilder::buildAll($processingRuns);
        $serviceFlows = ServiceTimelineBuilder::buildFlows($serviceTimelines, $processingRuns);

        return new ChurchServiceShowReadModel(
            churchService: $churchService,
            importMetadata: $importMetadata,
            warnings: $warnings,
            confidenceScore: is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            processingRunViews: $this->runViews($processingRuns, $processingTimelines, $serviceTimelines, $serviceFlows),
            pendingMerge: $hasPendingMerge ? $pendingMerge : null,
            pendingMergeSource: $hasPendingMerge ? $pendingMergeSource : null,
        );
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $processingRuns
     * @param  array<int, list<array<string, mixed>>>  $processingTimelines
     * @param  array<int, list<array<string, mixed>>>  $serviceTimelines
     * @param  array<int, list<array<string, mixed>>>  $serviceFlows
     * @return list<ChurchServiceProcessingRunView>
     */
    private function runViews(
        Collection $processingRuns,
        array $processingTimelines,
        array $serviceTimelines,
        array $serviceFlows,
    ): array {
        return array_values($processingRuns
            ->map(fn (MediaProcessingLog $run): ChurchServiceProcessingRunView => new ChurchServiceProcessingRunView(
                run: $run,
                processingTimeline: $processingTimelines[$run->id] ?? [],
                serviceTimeline: $serviceTimelines[$run->id] ?? [],
                serviceFlow: $serviceFlows[$run->id] ?? [],
                hasSections: $run->serviceSections->isNotEmpty(),
                isInProgress: $run->status->isInProgress(),
                needsSermonReview: $run->requiresManualSermonReview(),
                needsSectionReview: $run->serviceSections->contains('needs_manual_review', true),
                hasPendingPublications: $run->serviceSections->contains(
                    fn ($section): bool => in_array($section->publication_status, [
                        ServiceSectionPublicationStatus::PendingApproval,
                        ServiceSectionPublicationStatus::Approved,
                    ], true)
                ),
            ))
            ->all());
    }
}
