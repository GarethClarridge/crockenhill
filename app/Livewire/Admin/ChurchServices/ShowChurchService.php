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
use App\Presenters\ChurchServiceShowPresenter;
use App\Queries\ChurchServiceProcessingRunQuery;
use App\Services\ProcessingRunOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class ShowChurchService extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public ChurchService $churchService;

    public function mount(ChurchService $churchService): void
    {
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
        $readModel = app(ChurchServiceShowPresenter::class)->present($this->churchService);

        return view('livewire.admin.church-services.show-church-service', $readModel->toViewData());
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
        app(ProcessingRunOrchestrator::class)->reclassify($processingLog);

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

    public function deleteUpload(int $processingLogId): Redirector|RedirectResponse|null
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

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        return app(ChurchServiceProcessingRunQuery::class)->matchesService($processingLog, $this->churchService);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
