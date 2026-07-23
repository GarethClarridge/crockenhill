<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Data\ChurchServiceProcessingRunView;
use App\Data\ChurchServiceShowReadModel;
use App\Data\ChurchServiceStatusSummary;
use App\Enums\ChurchServiceRollupStatus;
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
use BackedEnum;
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

        $rollup = $this->rollupQuery->rollupFor($churchService, $processingRuns);

        $processingRunViews = $this->runViews($processingRuns, $processingTimelines, $serviceTimelines, $serviceFlows);
        $primaryRunId = $rollup['primary_run_id'];
        $primaryProcessingRunView = collect($processingRunViews)
            ->first(fn (ChurchServiceProcessingRunView $view): bool => $view->run->id === $primaryRunId);

        return new ChurchServiceShowReadModel(
            churchService: $churchService,
            importMetadata: $importMetadata,
            warnings: $warnings,
            confidenceScore: is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            processingRunViews: $processingRunViews,
            primaryProcessingRunView: $primaryProcessingRunView,
            otherProcessingRunViews: array_values(collect($processingRunViews)
                ->reject(fn (ChurchServiceProcessingRunView $view): bool => $view->run->id === $primaryRunId)
                ->all()),
            pendingMerge: $hasPendingMerge ? $pendingMerge : null,
            pendingMergeSource: $hasPendingMerge ? $pendingMergeSource : null,
            pipelineSteps: $rollup['steps'],
            statusSummary: $this->statusSummary(
                $churchService,
                $rollup['status'],
                $rollup['attention_count'],
                $primaryProcessingRunView,
            ),
            attentionCount: $rollup['attention_count'],
            planSourceNote: $this->planSourceNote($items),
            reviewNeedsAttention: $rollup['attention_count'] > 0,
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

    private function statusSummary(
        ChurchService $churchService,
        ChurchServiceRollupStatus $status,
        int $attentionCount,
        ?ChurchServiceProcessingRunView $primaryRunView,
    ): ChurchServiceStatusSummary {
        $uploadUrl = route('admin.services.upload-recording', ['churchServiceId' => $churchService->id]);
        $failureMessage = trim((string) $primaryRunView?->run->error_message);

        return match ($status) {
            ChurchServiceRollupStatus::PlanOnly => new ChurchServiceStatusSummary(
                $status,
                'This future service has a plan but no recording yet.',
                'Upload recording',
                $uploadUrl,
                null,
            ),
            ChurchServiceRollupStatus::AwaitingRecording => new ChurchServiceStatusSummary(
                $status,
                'The service date has passed and no recording is attached.',
                'Upload recording',
                $uploadUrl,
                null,
            ),
            ChurchServiceRollupStatus::Processing => new ChurchServiceStatusSummary(
                $status,
                'The recording is still being analysed.',
                null,
                null,
                null,
            ),
            ChurchServiceRollupStatus::ProcessingFailed => new ChurchServiceStatusSummary(
                $status,
                $failureMessage !== '' ? $failureMessage : 'The recording could not be processed.',
                'Upload replacement recording',
                $uploadUrl,
                null,
            ),
            ChurchServiceRollupStatus::NeedsReview => new ChurchServiceStatusSummary(
                $status,
                "{$attentionCount} ".str('item')->plural($attentionCount).' need attention.',
                'Jump to first attention row',
                null,
                'service-record',
            ),
            ChurchServiceRollupStatus::Published => new ChurchServiceStatusSummary(
                $status,
                'Published outputs are available.',
                null,
                null,
                null,
            ),
            ChurchServiceRollupStatus::Ready => new ChurchServiceStatusSummary(
                $status,
                'Processing completed and nothing needs attention.',
                null,
                null,
                null,
            ),
        };
    }

    /**
     * @param  Collection<int, ChurchServiceItem>  $items
     */
    private function planSourceNote(Collection $items): string
    {
        $sources = $items
            ->pluck('source')
            ->map(fn (mixed $source): mixed => $source instanceof BackedEnum ? $source->value : $source)
            ->filter(fn (mixed $source): bool => is_string($source) && $source !== '')
            ->map(fn (string $source): string => strtolower($source))
            ->unique()
            ->values();

        if ($sources->contains(fn (string $source): bool => str_contains($source, 'openlp'))) {
            return 'Presentation plan from OpenLP. It usually contains slide-backed items only, so other parts of the service may appear only in the recording.';
        }

        if ($sources->contains(fn (string $source): bool => str_contains($source, 'email'))) {
            return 'Plan imported from an email. It may describe more of the service than the presentation slides.';
        }

        if ($sources->count() > 1) {
            return 'This plan combines items from more than one source. Recording-only sections are expected where the plan does not describe the whole service.';
        }

        return 'This service plan was entered manually. It may not describe every part of the recording.';
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
     *     confirmable: bool,
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
