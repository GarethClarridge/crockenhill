<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Data\ChurchServiceProcessingRunView;
use App\Data\ChurchServiceShowReadModel;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Queries\ChurchServiceProcessingRunQuery;
use App\Queries\ChurchServiceRollupQuery;
use App\Queries\ServiceReviewDashboardQuery;
use App\Services\ChurchService\ProcessingRunTimelineBuilder;
use App\Services\ChurchService\ServiceFlowBuilder;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Database\Eloquent\Collection;

class ChurchServiceShowPresenter
{
    public function __construct(
        private readonly ChurchServiceProcessingRunQuery $processingRunQuery,
        private readonly ChurchServiceRollupQuery $rollupQuery,
        private readonly ServiceReviewDashboardQuery $dashboardQuery,
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
        $serviceTimelines = ServiceFlowBuilder::buildTimelines($processingRuns, $items);
        $processingTimelines = ProcessingRunTimelineBuilder::buildAll($processingRuns);
        $serviceFlows = ServiceFlowBuilder::buildFlows($serviceTimelines, $processingRuns);

        return new ChurchServiceShowReadModel(
            churchService: $churchService,
            importMetadata: $importMetadata,
            warnings: $warnings,
            confidenceScore: is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            processingRunViews: $this->runViews($processingRuns, $processingTimelines, $serviceTimelines, $serviceFlows),
            pendingMerge: $hasPendingMerge ? $pendingMerge : null,
            pendingMergeSource: $hasPendingMerge ? $pendingMergeSource : null,
            pipelineSteps: $this->rollupQuery->rollupFor($churchService, $processingRuns)['steps'],
            sectionReviewPanels: $this->sectionReviewPanels($processingRuns),
            mergeCandidatePairs: $this->mergeCandidatePairs($processingRuns),
            segmentConfirmations: $this->segmentConfirmations($processingRuns),
            pendingApprovalCount: $processingRuns
                ->flatMap(fn (MediaProcessingLog $run) => $run->serviceSections)
                ->filter(fn (ServiceSection $section): bool => $section->publication_status === ServiceSectionPublicationStatus::PendingApproval)
                ->count(),
            sectionPublishingEnabled: (bool) config('media-processing.section_publishing.enabled', true),
        );
    }

    /**
     * Inline review panels for the timeline rows, keyed by section id. Reuses
     * the dashboard query's review-candidate predicate (contract C1) so the
     * workbench flags exactly the sections the inbox counts.
     *
     * @param  Collection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, array{
     *     section: ServiceSection,
     *     reasons: array<int, array{key: string, label: string, classes: string}>,
     *     review_reason: string|null,
     *     audio_url: string|null,
     *     video_url: string|null
     * }>
     */
    private function sectionReviewPanels(Collection $processingRuns): array
    {
        $panels = [];

        foreach ($processingRuns as $run) {
            foreach ($run->serviceSections as $section) {
                $entry = $this->dashboardQuery->reviewEntryFor($section);

                if ($entry !== null) {
                    $panels[$section->id] = ['section' => $section, ...$entry];
                }
            }
        }

        return $panels;
    }

    /**
     * Embedded sermon-segment confirmation data, keyed by run id, for runs
     * paused on manual sermon review. Segments are loaded only for those runs.
     *
     * @param  Collection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, array{
     *     segments: Collection<int, LivestreamSegment>,
     *     confirmed_segment_id: int|null,
     *     source_available: bool
     * }>
     */
    private function segmentConfirmations(Collection $processingRuns): array
    {
        $pausedRuns = $processingRuns->filter(
            fn (MediaProcessingLog $run): bool => $run->requiresManualSermonReview()
        );

        if ($pausedRuns->isEmpty()) {
            return [];
        }

        // Resolved on demand: the video storage binding is only needed when a
        // run is actually paused for review.
        $videoStorage = app(VideoStorageService::class);

        $confirmations = [];

        foreach ($pausedRuns as $run) {
            $run->loadMissing('segments');

            $confirmations[$run->id] = [
                'segments' => $run->segments->sortBy('start_time')->values(),
                'confirmed_segment_id' => $run->manuallyConfirmedSegmentId(),
                'source_available' => is_string($run->source_file_path)
                    && $run->source_file_path !== ''
                    && $videoStorage->sourceVideoExistsForPath($run->source_file_path),
            ];
        }

        return $confirmations;
    }

    /**
     * Adjacent same-type sections that can be merged (≤2s gap, neither
     * published), keyed by the earlier section's id.
     *
     * @param  Collection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, int>
     */
    private function mergeCandidatePairs(Collection $processingRuns): array
    {
        $pairs = [];

        foreach ($processingRuns as $run) {
            $sections = $run->serviceSections->values();

            foreach ($sections as $index => $section) {
                $next = $sections[$index + 1] ?? null;

                if (
                    $next instanceof ServiceSection
                    && $next->section_type === $section->section_type
                    && abs($next->start_time - $section->end_time) <= 2
                    && $section->publication_status !== ServiceSectionPublicationStatus::Published
                    && $next->publication_status !== ServiceSectionPublicationStatus::Published
                ) {
                    $pairs[$section->id] = $next->id;
                }
            }
        }

        return $pairs;
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
