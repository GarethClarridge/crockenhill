<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ChurchServiceRollupStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ChurchServiceRunMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Bulk pipeline rollup for the services hub: one run query for a whole page
 * of services (no N+1), aggregated into a single ChurchServiceRollupStatus
 * chip plus pipeline-stepper states per service.
 */
class ChurchServiceRollupQuery
{
    /**
     * Safe column list for media_processing_logs (TD-004B): the table carries
     * oversized JSON blobs (rms_stats, ai_analysis) that must never be
     * hydrated for list/rollup rendering.
     *
     * @var list<string>
     */
    private const RUN_COLUMNS = [
        'id',
        'processing_id',
        'processing_type',
        'status',
        'current_step',
        'error_message',
        'original_filename',
        'extracted_date',
        'extracted_service',
        'processing_metadata',
        'sermon_id',
        'church_service_id',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly ChurchServiceRunMatcher $runMatcher,
        private readonly ServiceReviewDashboardQuery $dashboardQuery,
    ) {}

    /**
     * @param  EloquentCollection<int, ChurchService>  $services
     * @return array<int, array{
     *     status: ChurchServiceRollupStatus,
     *     attention_count: int,
     *     run_count: int,
     *     primary_run_id: int|null,
     *     steps: list<array{label: string, state: string}>
     * }>
     */
    public function forServices(EloquentCollection $services): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $services->loadMissing('items');

        /** @var array<int, list<string>> $fallbackProcessingIds */
        $fallbackProcessingIds = $services
            ->mapWithKeys(fn (ChurchService $service): array => [
                $service->id => $this->runMatcher->fallbackProcessingIdsForService($service),
            ])
            ->all();

        $runs = $this->matchingRuns($services, $fallbackProcessingIds);

        $rollups = [];

        foreach ($services as $service) {
            $serviceRuns = $runs->filter(
                fn (MediaProcessingLog $run): bool => $this->runMatcher->matches($run, $service, $fallbackProcessingIds[$service->id])
            )->values();

            $rollups[$service->id] = $this->rollupFor($service, $serviceRuns);
        }

        return $rollups;
    }

    /**
     * Roll up one service from its already-matched runs (with serviceSections
     * loaded). Used by the bulk path above and by ChurchServiceShowPresenter,
     * so the hub chip and the workbench stepper cannot drift apart.
     *
     * @param  Collection<int, MediaProcessingLog>  $serviceRuns
     * @return array{
     *     status: ChurchServiceRollupStatus,
     *     attention_count: int,
     *     run_count: int,
     *     primary_run_id: int|null,
     *     steps: list<array{label: string, state: string}>
     * }
     */
    public function rollupFor(ChurchService $service, Collection $serviceRuns): array
    {
        return $this->rollup($service, $serviceRuns);
    }

    /**
     * @param  EloquentCollection<int, ChurchService>  $services
     * @param  array<int, list<string>>  $fallbackProcessingIds
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function matchingRuns(EloquentCollection $services, array $fallbackProcessingIds): EloquentCollection
    {
        return MediaProcessingLog::query()
            ->select(self::RUN_COLUMNS)
            ->segmentationPipeline()
            ->notSuperseded()
            ->with([
                'serviceSections' => fn ($query) => $query
                    ->orderBy('section_order')
                    ->orderBy('id'),
            ])
            ->where(function (Builder $query) use ($services, $fallbackProcessingIds): void {
                foreach ($services as $service) {
                    $query->orWhere(function (Builder $query) use ($service, $fallbackProcessingIds): void {
                        $this->runMatcher->applyMatchClauses($query, $service, $fallbackProcessingIds[$service->id]);
                    });
                }
            })
            ->get();
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $serviceRuns
     * @return array{
     *     status: ChurchServiceRollupStatus,
     *     attention_count: int,
     *     run_count: int,
     *     primary_run_id: int|null,
     *     steps: list<array{label: string, state: string}>
     * }
     */
    private function rollup(ChurchService $service, Collection $serviceRuns): array
    {
        $primaryRun = $serviceRuns
            ->sortByDesc(fn (MediaProcessingLog $run): string => sprintf(
                '%s-%020d',
                $run->created_at?->format('Y-m-d H:i:s.u') ?? '',
                $run->id,
            ))
            ->first();

        $primaryRuns = $primaryRun instanceof MediaProcessingLog
            ? collect([$primaryRun])
            : collect();

        /** @var Collection<int, ServiceSection> $sections */
        $sections = $primaryRuns->flatMap(fn (MediaProcessingLog $run) => $run->serviceSections);

        $flaggedSectionCount = $this->sectionPublishingEnabled()
            ? $sections->filter(fn (ServiceSection $section): bool => $this->dashboardQuery->isReviewCandidate($section))->count()
            : 0;

        $awaitingSegmentRunCount = $primaryRuns
            ->filter(fn (MediaProcessingLog $run): bool => $run->requiresManualSermonReview())
            ->count();

        $attentionCount = $flaggedSectionCount
            + $awaitingSegmentRunCount
            + ($service->needs_review ? 1 : 0)
            + ($service->pending_structure_merge_source !== null ? 1 : 0);

        $isProcessing = $primaryRuns->contains(
            fn (MediaProcessingLog $run): bool => in_array($run->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Started,
                ProcessingStatus::Processing,
            ], true)
        );

        $hasCompletedRun = $primaryRuns->contains(
            fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed
        );

        $hasFailedRun = $primaryRun instanceof MediaProcessingLog && $primaryRun->status === ProcessingStatus::Failed;

        $hasSermon = $primaryRuns->contains(fn (MediaProcessingLog $run): bool => $run->sermon_id !== null)
            || $sections->contains(fn (ServiceSection $section): bool => $section->published_sermon_id !== null);

        $allSectionsResolved = $sections->isNotEmpty() && $sections->every(
            fn (ServiceSection $section): bool => in_array($section->publication_status, [
                ServiceSectionPublicationStatus::Published,
                ServiceSectionPublicationStatus::NotApplicable,
            ], true)
        );

        $status = match (true) {
            $isProcessing => ChurchServiceRollupStatus::Processing,
            $hasFailedRun => ChurchServiceRollupStatus::ProcessingFailed,
            $attentionCount > 0 => ChurchServiceRollupStatus::NeedsReview,
            $serviceRuns->isEmpty() => $service->date->isPast() && ! $service->date->isToday()
                ? ChurchServiceRollupStatus::AwaitingRecording
                : ChurchServiceRollupStatus::PlanOnly,
            $allSectionsResolved && $hasSermon => ChurchServiceRollupStatus::Published,
            default => ChurchServiceRollupStatus::Ready,
        };

        return [
            'status' => $status,
            'attention_count' => $attentionCount,
            'run_count' => $serviceRuns->count(),
            'primary_run_id' => $primaryRun?->id,
            'steps' => $this->steps($service, $status, $serviceRuns->isNotEmpty(), $hasCompletedRun, $attentionCount),
        ];
    }

    /**
     * Stepper states for x-admin.pipeline-steps: done|active|blocked|todo.
     *
     * @return list<array{label: string, state: string}>
     */
    private function steps(
        ChurchService $service,
        ChurchServiceRollupStatus $status,
        bool $hasRuns,
        bool $hasCompletedRun,
        int $attentionCount
    ): array {
        $reviewState = match (true) {
            $attentionCount > 0 => 'blocked',
            $hasCompletedRun => 'done',
            default => 'todo',
        };

        return [
            ['label' => 'Plan', 'state' => $service->items->isNotEmpty() ? 'done' : 'todo'],
            ['label' => 'Recording', 'state' => $hasRuns ? 'done' : 'todo'],
            ['label' => 'Processed', 'state' => match (true) {
                $status === ChurchServiceRollupStatus::Processing => 'active',
                $hasCompletedRun => 'done',
                default => 'todo',
            }],
            ['label' => 'Review', 'state' => $reviewState],
            ['label' => 'Published', 'state' => $status === ChurchServiceRollupStatus::Published ? 'done' : 'todo'],
        ];
    }

    private function sectionPublishingEnabled(): bool
    {
        return (bool) config('media-processing.section_publishing.enabled', true);
    }
}
