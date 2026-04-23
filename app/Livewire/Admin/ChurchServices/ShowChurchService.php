<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\DeleteLivestreamUpload;
use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ServiceFlowBuilder;
use App\Support\ServiceRecordTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class ShowChurchService extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public ChurchService $churchService;

    public function mount(ChurchService $churchService): void
    {

        $this->authorizeAdmin();
        $this->abortIfDisabled();

        $this->churchService = $churchService->load([
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
        ]);
    }

    public function render(): View
    {
        $importMetadata = $this->churchService->import_metadata?->toArray() ?? [];
        $warnings = is_array($importMetadata['warnings'] ?? null) ? $importMetadata['warnings'] : [];
        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        $processingRuns = $this->relatedProcessingRuns();
        $pendingMerge = $this->churchService->import_metadata?->pendingStructureMerge;
        $hasPendingMerge = $pendingMerge !== null && $pendingMerge->incomingSource !== null;

        $serviceTimelines = $this->buildServiceTimelines($processingRuns);

        return view('livewire.admin.church-services.show-church-service', [
            'importMetadata' => $importMetadata,
            'warnings' => $warnings,
            'confidenceScore' => is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            'processingRuns' => $processingRuns,
            'processingTimelines' => $this->buildProcessingTimelines($processingRuns),
            'serviceTimelines' => $serviceTimelines,
            'serviceFlows' => $this->buildServiceFlows($serviceTimelines, $processingRuns),
            'pendingMerge' => $hasPendingMerge ? $pendingMerge : null,
        ])->layout('layouts.admin', [
            'title' => $this->churchService->date->format('j M Y'),
            'heading' => $this->churchService->date->format('j M Y').' '.$this->churchService->service->label(),
        ]);
    }

    public function reclassify(int $processingLogId): void
    {

        $this->authorizeAdmin();

        $processingLog = MediaProcessingLog::query()->find($processingLogId);
        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error('Processing run not found.');

            return;
        }

        if ($processingLog->processing_type !== MediaType::Livestream) {
            $this->error('Only livestream runs can be reclassified.');

            return;
        }

        if (! $this->processingLogMatchesService($processingLog)) {
            $this->error('Selected run does not belong to this service.');

            return;
        }

        Log::warning('Media processing run reclassification requested by admin', [
            'admin_id' => auth()->id(),
            'processing_log_id' => $processingLogId,
            'church_service_id' => $this->churchService->id,
        ]);

        // Resolve on demand because Livewire serializes component state between requests.
        app(\App\Services\ProcessingRunOrchestrator::class)->reclassify($processingLog);

        $this->success('Section reclassification queued');
    }

    public function acceptIncomingMerge(): void
    {

        $this->authorizeAdmin();

        $this->resolvePendingMerge('accept_incoming');
    }

    public function keepCurrentStructure(): void
    {

        $this->authorizeAdmin();

        $this->resolvePendingMerge('keep_current');
    }

    public function deleteUpload(int $processingLogId): \Livewire\Features\SupportRedirects\Redirector|RedirectResponse|null
    {

        $this->authorizeAdmin();

        $processingLog = MediaProcessingLog::query()->find($processingLogId);
        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error('Processing run not found.');

            return null;
        }

        if (! $this->processingLogMatchesService($processingLog)) {
            $this->error('Selected run does not belong to this service.');

            return null;
        }

        Log::warning('Media processing log deleted by admin', [
            'admin_id' => auth()->id(),
            'processing_log_id' => $processingLogId,
            'church_service_id' => $this->churchService->id,
        ]);

        try {
            $result = app(DeleteLivestreamUpload::class)->execute($processingLog);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return null;
        }

        if (in_array($this->churchService->id, $result['deleted_service_ids'], true)) {
            return $this->success(
                'Broken livestream upload deleted. The empty projected service was removed too.',
                route('admin.services.index')
            );
        }

        $this->churchService = $this->churchService->fresh([
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
        ]) ?? $this->churchService;

        $sermonLabel = $result['deleted_sermons'] === 1 ? 'sermon' : 'sermons';
        $itemLabel = $result['deleted_projected_items'] === 1 ? 'projected item' : 'projected items';

        $this->success(sprintf(
            'Broken livestream upload deleted. Removed %d %s and %d %s.',
            $result['deleted_sermons'],
            $sermonLabel,
            $result['deleted_projected_items'],
            $itemLabel,
        ));

        return null;
    }

    private function resolvePendingMerge(string $resolution): void
    {
        $userId = is_numeric(Auth::id()) ? (int) Auth::id() : 0;

        $result = app(ResolvePendingStructureMerge::class)->execute(
            $this->churchService,
            $resolution,
            $userId,
        );

        if (! $result->applied) {
            $this->error($result->reason);

            return;
        }

        $this->churchService = $result->churchService->load([
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $label = $resolution === 'accept_incoming' ? 'Incoming items applied' : 'Current structure preserved';
        $this->success($label.'. Merge resolved.');
    }

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function relatedProcessingRuns(): EloquentCollection
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;
        $resolver = $this->identityResolver();
        $fallbackProcessingIds = $this->fallbackProcessingIdsForService();

        $query = MediaProcessingLog::query()
            ->livestream()
            ->with([
                'serviceSections' => fn ($query) => $query
                    ->with([
                        'publishedSermon:id,title,slug,content_type',
                        'churchServiceItem' => fn ($q) => $q->withTrashed()->with('song:id,title'),
                    ])
                    ->orderBy('section_order')
                    ->orderBy('id'),
                'processingSteps' => fn ($query) => $query
                    ->whereIn('step', ChurchServiceProcessingTimeline::stepKeys())
                    ->orderBy('started_at')
                    ->orderBy('id'),
            ])
            ->where(function ($query) use ($resolver, $serviceDate, $serviceType, $fallbackProcessingIds): void {
                $resolver->scopeMatchesIdentity($query, $serviceDate, $serviceType);

                $query->orWhere('church_service_id', $this->churchService->id);

                if ($fallbackProcessingIds !== []) {
                    $query->orWhereIn('processing_id', $fallbackProcessingIds);
                }
            })
            ->orderByDesc('created_at');

        return $query->get();
    }

    /**
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildServiceTimelines(EloquentCollection $processingRuns): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChurchServiceItem> $items */
        $items = $this->churchService->items;

        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $run): array => [
                $run->id => ServiceRecordTimeline::build($items, $run),
            ])
            ->all();
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $serviceTimelines
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildServiceFlows(array $serviceTimelines, EloquentCollection $processingRuns): array
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

    /**
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildProcessingTimelines(EloquentCollection $processingRuns): array
    {
        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $processingRun): array => [
                $processingRun->id => $this->buildTimelineForRun($processingRun),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTimelineForRun(MediaProcessingLog $processingRun): array
    {
        /** @var Collection<string, SermonProcessingStep> $stepLogs */
        $stepLogs = $processingRun->processingSteps->keyBy('step');
        $latestRecordedIndex = $this->latestRecordedStepIndex($stepLogs);
        $currentTimelineStep = ChurchServiceProcessingTimeline::fromCurrentStep($processingRun->current_step);
        $currentStepIndex = $this->indexForTimelineStep($currentTimelineStep);

        $timeline = [];

        foreach (ChurchServiceProcessingTimeline::steps() as $index => $definition) {
            $recordedStep = $stepLogs->get($definition['key']);
            $timeline[] = $recordedStep instanceof SermonProcessingStep
                ? $this->timelineEntryFromRecordedStep($definition['label'], $recordedStep)
                : $this->timelineEntryFromMissingStep(
                    label: $definition['label'],
                    step: $definition['key'],
                    stepIndex: $index,
                    latestRecordedIndex: $latestRecordedIndex,
                    currentStepIndex: $currentStepIndex,
                    processingRun: $processingRun
                );
        }

        return $timeline;
    }

    /**
     * @param  Collection<string, SermonProcessingStep>  $stepLogs
     */
    private function latestRecordedStepIndex(Collection $stepLogs): ?int
    {
        $indices = $stepLogs
            ->keys()
            ->map(fn (string $step): ?int => $this->indexForTimelineStep($step))
            ->filter(fn (?int $index): bool => $index !== null)
            ->values();

        if ($indices->isEmpty()) {
            return null;
        }

        /** @var int $latest */
        $latest = $indices->max();

        return $latest;
    }

    private function indexForTimelineStep(?string $step): ?int
    {
        if ($step === null) {
            return null;
        }

        foreach (ChurchServiceProcessingTimeline::steps() as $index => $definition) {
            if ($definition['key'] === $step) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryFromRecordedStep(string $label, SermonProcessingStep $step): array
    {
        $status = $step->status->value;
        if ($step->status === ProcessingStatus::Started) {
            $status = 'running';
        }

        return [
            'label' => $label,
            'status' => $status,
            'started_at' => $step->started_at,
            'completed_at' => $step->completed_at,
            'duration' => $this->formatDuration($step->started_at?->diffInSeconds($step->completed_at ?? now(), true)),
            'message' => $this->normaliseMessage($step->message),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryFromMissingStep(
        string $label,
        string $step,
        int $stepIndex,
        ?int $latestRecordedIndex,
        ?int $currentStepIndex,
        MediaProcessingLog $processingRun
    ): array {
        $status = 'pending';
        $message = null;

        if ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isInProgress()) {
            $status = 'running';
        } elseif ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isFailed()) {
            $status = 'failed';
            $message = $this->normaliseMessage($processingRun->error_message);
        } elseif ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isCancelled()) {
            $status = 'skipped';
            $message = $this->normaliseMessage($processingRun->error_message) ?? 'Processing cancelled';
        } elseif ($latestRecordedIndex !== null && $latestRecordedIndex > $stepIndex) {
            $status = 'not_recorded';
            $message = 'No step log recorded for this older run.';
        } elseif ($processingRun->status->isComplete()) {
            $status = 'not_recorded';
            $message = 'No step log recorded for this older run.';
        }

        return [
            'label' => $label,
            'status' => $status,
            'started_at' => null,
            'completed_at' => null,
            'duration' => null,
            'message' => $message,
        ];
    }

    private function formatDuration(?float $seconds): ?string
    {
        if (! is_numeric($seconds)) {
            return null;
        }

        $duration = (int) round($seconds);
        if ($duration <= 0) {
            return '0s';
        }

        if ($duration < 60) {
            return $duration.'s';
        }

        $minutes = intdiv($duration, 60);
        $remainingSeconds = $duration % 60;

        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %02dm %02ds', $hours, $remainingMinutes, $remainingSeconds);
    }

    private function normaliseMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $message = trim($message);

        return $message === '' ? null : $message;
    }

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;

        if ($this->identityResolver()->matchesService($processingLog, $serviceDate, $serviceType)) {
            return true;
        }

        if ($processingLog->church_service_id === $this->churchService->id) {
            return true;
        }

        return in_array($processingLog->processing_id, $this->fallbackProcessingIdsForService(), true);
    }

    private function identityResolver(): MediaProcessingIdentityResolver
    {
        return app(MediaProcessingIdentityResolver::class);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }

    /**
     * @return array<int, string>
     */
    private function fallbackProcessingIdsForService(): array
    {
        $processingIds = [];

        foreach ($this->churchService->items as $item) {
            if (is_string($item->livestream_processing_id) && trim($item->livestream_processing_id) !== '') {
                $processingIds[] = $item->livestream_processing_id;
            }

            $itemMetadata = $item->metadata ?? [];
            $itemProjection = $itemMetadata['livestream_projection'] ?? [];
            if (is_string($itemProjection['processing_id'] ?? null) && trim($itemProjection['processing_id']) !== '') {
                $processingIds[] = $itemProjection['processing_id'];
            }
        }

        $serviceMetadata = $this->churchService->import_metadata?->toArray() ?? [];
        $serviceProjection = $serviceMetadata['livestream_projection'] ?? [];
        if (is_string($serviceProjection['processing_id'] ?? null) && trim($serviceProjection['processing_id']) !== '') {
            $processingIds[] = $serviceProjection['processing_id'];
        }

        return array_values(array_unique($processingIds));
    }
}
