<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Actions\DeleteLivestreamUpload;
use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Admin\ChurchServices\Concerns\ReviewsServiceSections;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Presenters\ChurchServiceShowPresenter;
use App\Queries\ChurchServiceProcessingRunQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class ShowChurchService extends Component
{
    use ManagesSectionPublication;
    use ReviewsServiceSections;
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchService $churchService;

    public function mount(ChurchService $churchService): void
    {
        $this->churchService = ChurchService::query()
            ->whereKey($churchService->getKey())
            ->withOrderedItems(withSong: true)
            ->firstOrFail();

        // Seed edit state for review candidates only — seeding every section
        // of every run would balloon the Livewire payload.
        $this->seedSectionEditsForSections(
            app(ChurchServiceProcessingRunQuery::class)
                ->forService($this->churchService)
                ->flatMap(fn (MediaProcessingLog $run) => $run->serviceSections)
                ->filter(fn (ServiceSection $section): bool => $this->dashboardQuery->isReviewCandidate($section))
        );
    }

    public function render(): View
    {
        $readModel = app(ChurchServiceShowPresenter::class)->present($this->churchService);

        $label = $this->churchService->date->format('j M Y').' '.$this->churchService->service->label();

        return view('livewire.admin.church-services.show-church-service', [
            ...$readModel->toViewData(),
            'sectionTypeOptions' => $this->sectionTypeOptions(),
            'preacherOptions' => $this->preacherOptions(),
        ])
            ->layout('layouts.admin', [
                'title' => $label,
                'heading' => $label,
            ]);
    }

    public function confirmRunSegment(int $processingLogId, int $segmentId): void
    {
        $this->authorizeAdmin();

        $processingLog = MediaProcessingLog::query()->find($processingLogId);
        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error('Processing run not found.');

            return;
        }

        if (! $this->processingLogMatchesService($processingLog)) {
            $this->error('Selected run does not belong to this service.');

            return;
        }

        if (! $processingLog->requiresManualSermonReview()) {
            $this->error('This run is not awaiting sermon-segment confirmation.');

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            app(ConfirmLivestreamSermonSegment::class)->execute(
                $processingLog->processing_id,
                $segmentId,
                $user
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->success('Sermon segment confirmed. Processing will resume shortly.');
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

        $this->churchService = ChurchService::query()
            ->whereKey($result->churchService->getKey())
            ->withOrderedItems(withSong: true)
            ->firstOrFail();

        $label = $resolution === 'accept_incoming' ? 'Incoming items applied' : 'Current structure preserved';
        $this->success($label.'. Merge resolved.');
    }

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        return app(ChurchServiceProcessingRunQuery::class)->matchesService($processingLog, $this->churchService);
    }
}
