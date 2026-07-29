<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Actions\DeleteLivestreamUpload;
use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Actions\ServiceReview\ReviewChurchServiceEvidence;
use App\Enums\ChurchServiceProposalStatus;
use App\Livewire\Admin\ChurchServices\Concerns\EditsPlannedItems;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Admin\ChurchServices\Concerns\ReviewsServiceSections;
use App\Livewire\Forms\ChurchServiceFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Presenters\ChurchServiceShowPresenter;
use App\Queries\ChurchServiceProcessingRunQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class ShowChurchService extends Component
{
    use EditsPlannedItems;
    use ManagesSectionPublication;
    use ReviewsServiceSections;
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchService $churchService;

    public ChurchServiceFormData $form;

    /** @var array<int, bool> */
    public array $selectedProposals = [];

    /** @var array<int, string> */
    public array $proposalResolutions = [];

    /** @var list<array<string, mixed>> */
    public array $evidenceReviewItems = [];

    public string $evidenceSummary = '';

    public string $evidenceNotices = '[]';

    public string $evidenceChapterMarkers = '[]';

    public int $loadedCanonicalRevision = 0;

    public ?string $loadedCanonicalHash = null;

    public int $loadedProposalMaxId = 0;

    #[Url(except: false)]
    public bool $edit = false;

    public function mount(ChurchService $churchService): void
    {
        $this->churchService = ChurchService::query()
            ->whereKey($churchService->getKey())
            ->withOrderedItems(withSong: true)
            ->firstOrFail();

        $this->form->setChurchService($this->churchService);
        $this->seedEvidenceReview();

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
        $evidenceProposals = $this->evidenceProposals();
        $latestProposalId = (int) ($evidenceProposals->max('id') ?? 0);

        $dateHeading = $this->churchService->date->format('l j F Y');
        $serviceLabel = $this->churchService->service->label().' service';

        return view('livewire.admin.church-services.show-church-service', [
            ...$readModel->toViewData(),
            'sectionTypeOptions' => $this->sectionTypeOptions,
            'preacherOptions' => $this->preacherOptions,
            'items' => $this->form->items,
            'songSuggestions' => $this->edit ? $this->form->songSuggestions() : [],
            'linkedSongTitles' => $this->edit ? $this->form->linkedSongTitles() : [],
            'evidenceProposals' => $evidenceProposals,
            'evidenceChangedSinceLoad' => $latestProposalId > $this->loadedProposalMaxId,
        ])
            ->layout('layouts.admin', [
                'title' => "{$dateHeading} — {$serviceLabel}",
                'heading' => $dateHeading,
                'breadcrumbHeading' => $this->churchService->date->format('j F Y'),
            ]);
    }

    public function startEditingOrderOfService(): void
    {
        $this->authorizeAdmin();

        $this->form->setChurchService($this->churchService);
        $this->edit = true;
    }

    public function cancelEditingOrderOfService(): void
    {
        $this->authorizeAdmin();

        $this->form->setChurchService($this->churchService);
        $this->resetErrorBag('form.items');
        $this->edit = false;
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

    public function selectAllPendingEvidence(): void
    {
        $this->authorizeAdmin();

        $this->selectedProposals = $this->evidenceProposals()
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->mapWithKeys(fn (ChurchServiceMergeProposal $proposal): array => [$proposal->id => true])
            ->all();
    }

    public function reviewSelectedEvidence(): void
    {
        $this->authorizeAdmin();

        $latestProposalId = (int) ($this->evidenceProposals()->max('id') ?? 0);

        if ($latestProposalId > $this->loadedProposalMaxId) {
            $this->error('New evidence arrived after this screen loaded. Reload before submitting your review.');

            return;
        }

        $validated = $this->validate([
            'selectedProposals' => ['required', 'array', 'min:1'],
            'selectedProposals.*' => ['boolean'],
            'proposalResolutions' => ['array'],
            'proposalResolutions.*' => [Rule::in(['accepted', 'rejected'])],
            'evidenceReviewItems' => ['array'],
            'evidenceReviewItems.*.included' => ['boolean'],
            'evidenceReviewItems.*.selected_assertion_id' => ['nullable', 'integer'],
            'evidenceReviewItems.*.type' => ['required', 'string', 'max:50'],
            'evidenceReviewItems.*.section_type' => ['nullable', 'string', 'max:50'],
            'evidenceReviewItems.*.title' => ['required', 'string', 'max:255'],
            'evidenceReviewItems.*.source_title' => ['nullable', 'string', 'max:255'],
            'evidenceReviewItems.*.song_id' => ['nullable', 'integer'],
            'evidenceReviewItems.*.song_canonical_key' => ['nullable', 'string', 'max:255'],
            'evidenceReviewItems.*.scripture_reference' => ['nullable', 'string', 'max:255'],
            'evidenceReviewItems.*.occurrence_state' => [
                'nullable',
                Rule::in(['planned_only', 'observed_only', 'planned_and_observed', 'manually_confirmed']),
            ],
            'evidenceSummary' => ['nullable', 'string'],
            'evidenceNotices' => ['required', 'json'],
            'evidenceChapterMarkers' => ['required', 'json'],
        ]);

        $proposalIds = [];

        foreach ($this->selectedProposals as $proposalId => $selected) {
            if ($selected) {
                $proposalIds[] = (int) $proposalId;
            }
        }

        if ($proposalIds === []) {
            $this->addError('selectedProposals', 'Select at least one proposal to review.');

            return;
        }

        $items = [];

        foreach ($this->evidenceReviewItems as $item) {
            if ((bool) $item['included']) {
                $items[] = $item;
            }
        }

        $notices = json_decode($this->evidenceNotices, true, flags: JSON_THROW_ON_ERROR);
        $chapterMarkers = json_decode($this->evidenceChapterMarkers, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($notices) || ! is_array($chapterMarkers)) {
            $this->addError('evidenceNotices', 'Notices and chapter markers must be JSON arrays.');

            return;
        }

        $userId = is_numeric(Auth::id()) ? (int) Auth::id() : 0;
        $result = app(ReviewChurchServiceEvidence::class)->execute(
            $this->churchService,
            $proposalIds,
            $this->proposalResolutions,
            $items,
            [
                'summary' => filled($this->evidenceSummary) ? $this->evidenceSummary : null,
                'notices' => $notices,
                'chapter_markers' => $chapterMarkers,
            ],
            $userId,
            $this->loadedCanonicalRevision,
            $this->loadedCanonicalHash,
        );

        if (! $result->applied) {
            $this->error($result->reason);

            return;
        }

        $this->churchService = ChurchService::query()
            ->whereKey($result->churchService->getKey())
            ->withOrderedItems(withSong: true)
            ->firstOrFail();
        $this->seedEvidenceReview();
        $this->success('Selected evidence reviewed. Source records and proposal history were preserved.');
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
            $this->churchService->canonical_revision,
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

    private function seedEvidenceReview(): void
    {
        $proposals = $this->evidenceProposals();
        $latestProposal = $proposals
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->sortByDesc('id')
            ->first();

        $this->loadedCanonicalRevision = $this->churchService->canonical_revision;
        $this->loadedCanonicalHash = $this->churchService->canonical_hash;
        $this->loadedProposalMaxId = (int) ($proposals->max('id') ?? 0);
        $this->selectedProposals = $proposals
            ->where('status', ChurchServiceProposalStatus::Pending)
            ->mapWithKeys(fn (ChurchServiceMergeProposal $proposal): array => [$proposal->id => true])
            ->all();
        $this->proposalResolutions = $proposals
            ->mapWithKeys(fn (ChurchServiceMergeProposal $proposal): array => [$proposal->id => 'accepted'])
            ->all();

        $proposedItems = $latestProposal instanceof ChurchServiceMergeProposal
            ? $latestProposal->proposed_items
            : $this->churchService->items->map->toArray()->all();
        $assertions = $proposals
            ->flatMap(function (ChurchServiceMergeProposal $proposal) {
                $sourceRecord = $proposal->triggerSourceRecord;

                return $sourceRecord?->assertions->all() ?? [];
            })
            ->keyBy(fn (ChurchServiceItemAssertion $assertion): string => mb_strtolower($assertion->title));

        $this->evidenceReviewItems = [];

        foreach ($proposedItems as $item) {
            $assertion = $assertions->get(mb_strtolower((string) ($item['title'] ?? '')));
            $this->evidenceReviewItems[] = [
                ...$item,
                'included' => true,
                'selected_assertion_id' => $assertion?->id,
            ];
        }
        $this->evidenceSummary = $this->churchService->summary ?? '';
        $this->evidenceNotices = json_encode($this->churchService->notices ?? [], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->evidenceChapterMarkers = json_encode(
            $this->churchService->chapter_markers ?? [],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return Collection<int, ChurchServiceMergeProposal>
     */
    private function evidenceProposals(): Collection
    {
        return ChurchServiceMergeProposal::query()
            ->whereBelongsTo($this->churchService)
            ->whereIn('status', [
                ChurchServiceProposalStatus::Pending,
                ChurchServiceProposalStatus::Stale,
            ])
            ->with([
                'triggerSourceRecord.assertions' => fn ($query) => $query
                    ->with('song:id,title')
                    ->orderBy('source_position')
                    ->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    protected function churchServiceForPlannedItems(): ?ChurchService
    {
        return $this->churchService;
    }

    protected function inboundEmailIdForPlannedItems(): ?int
    {
        return null;
    }

    protected function planKeyForPlannedItems(): ?string
    {
        return null;
    }

    protected function afterPlannedItemsSaved(ChurchService $churchService, bool $wasCreated): mixed
    {
        $this->churchService = ChurchService::query()
            ->whereKey($churchService->getKey())
            ->withOrderedItems(withSong: true)
            ->firstOrFail();

        $this->form->setChurchService($this->churchService);
        $this->edit = false;
        $this->success('Service updated');

        return null;
    }
}
