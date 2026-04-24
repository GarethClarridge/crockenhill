<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\DeleteLivestreamUpload;
use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Enums\MediaType;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ProcessingRunTimelineBuilder;
use App\Support\ServiceFlowBuilder;
use App\Support\ServiceRecordTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
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
            'processingTimelines' => ProcessingRunTimelineBuilder::buildAll($processingRuns),
            'serviceTimelines' => $serviceTimelines,
            'serviceFlows' => $this->buildServiceFlows($serviceTimelines, $processingRuns),
            'pendingMerge' => $hasPendingMerge ? $pendingMerge : null,
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
        }

        $serviceMetadata = $this->churchService->import_metadata?->toArray() ?? [];
        $serviceProjection = $serviceMetadata['livestream_projection'] ?? [];
        if (is_string($serviceProjection['processing_id'] ?? null) && trim($serviceProjection['processing_id']) !== '') {
            $processingIds[] = $serviceProjection['processing_id'];
        }

        return array_values(array_unique($processingIds));
    }
}
