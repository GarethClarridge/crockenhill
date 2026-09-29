<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Actions\DeleteLivestreamUpload;
use App\Actions\ServiceReview\AnswerServiceStructureEnsembleQuestion;
use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Actions\ServiceReview\ReviewChurchServiceEvidence;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ServiceSectionType;
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
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\ServiceTimestamp;
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

    /** @var array<int, string|null> */
    public array $proposalResolutions = [];

    /** @var list<array<string, mixed>> */
    public array $evidenceReviewItems = [];

    public string $evidenceSummary = '';

    public string $evidenceNotices = '[]';

    public string $evidenceChapterMarkers = '[]';

    public int $loadedCanonicalRevision = 0;

    public ?string $loadedCanonicalHash = null;

    public int $loadedProposalMaxId = 0;

    /**
     * Correction rows being edited, per open ensemble question.
     *
     * @var array<string, list<array{type: string, start: string, end: string, reference: string, song_title: string}>>
     */
    public array $ensembleCorrections = [];

    /** @var array<string, string> */
    public array $ensembleAbsenceExplanation = [];

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
            'ensembleReviewPanels' => $this->ensembleReviewPanels(),
            'ensembleSectionTypes' => array_map(
                static fn (ServiceSectionType $type): array => ['id' => $type->value, 'name' => $type->label()],
                ServiceSectionType::cases(),
            ),
        ])
            ->layout('layouts.admin', [
                'title' => "{$dateHeading} — {$serviceLabel}",
                'heading' => $dateHeading,
                'breadcrumbHeading' => $this->churchService->date->format('j F Y'),
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function ensembleReviewPanels(): array
    {
        $panels = [];

        foreach (app(ChurchServiceProcessingRunQuery::class)->forService($this->churchService) as $run) {
            $bank = $run->processing_metadata?->raw['service_structure_ensemble'] ?? null;
            $evidence = is_array($bank) && $bank !== [] ? end($bank) : null;

            if (! is_array($evidence) || ! is_array($evidence['composition'] ?? null)) {
                continue;
            }

            $composition = $evidence['composition'];
            $questions = $composition['disputes'] ?? [];

            if (! is_array($questions) || $questions === []) {
                continue;
            }

            $run->loadMissing('serviceSections');

            $current = app(EnsembleReviewGate::class)->inputIsCurrent($run, $evidence);
            $cues = [];

            if ($current) {
                try {
                    $snapshot = app(ServiceStructureEnsembleReplay::class)->snapshot($evidence);
                    $cues = is_array($snapshot['transcript']['cues'] ?? null) ? $snapshot['transcript']['cues'] : [];
                } catch (\Throwable) {
                    $current = false;
                }
            }

            $hasServiceAudio = collect(ServiceArtifactStorage::recordedFor($run))
                ->contains(static fn (array $entry): bool => $entry['kind'] === 'audio');

            $panels[$run->id] = [
                'current' => $current,
                'service_audio_url' => $hasServiceAudio ? route('admin.recordings.service-audio', $run) : null,
                'deferred_count' => count(array_filter($questions, static fn (array $question): bool => ($question['deferred'] ?? false) === true)),
                'stale_count' => count($composition['stale_rulings'] ?? []),
                'applied_count' => count($composition['applied_rulings'] ?? []),
                'questions' => array_map(function (array $question) use ($cues, $run): array {
                    $nearby = [];

                    if (is_numeric($question['start_time'] ?? null) && is_numeric($question['end_time'] ?? null)) {
                        $start = (float) $question['start_time'];
                        $end = (float) $question['end_time'];
                        $nearby = array_values(array_filter($cues, static fn (mixed $cue): bool => is_array($cue)
                            && (float) ($cue['end'] ?? 0) >= $start - 20
                            && (float) ($cue['start'] ?? 0) <= $end + 20));
                    }

                    $section = $run->serviceSections->first(static fn (ServiceSection $candidate): bool => $candidate->section_type->value === ($question['type'] ?? null)
                        && abs((float) $candidate->start_time - (float) ($question['start_time'] ?? -1000)) < 1
                        && abs((float) $candidate->end_time - (float) ($question['end_time'] ?? -1000)) < 1);
                    $media = $section instanceof ServiceSection ? $this->dashboardQuery->reviewEntryFor($section) : null;

                    return [
                        ...$question,
                        'clip' => $this->ensembleQuestionClip($question),
                        'context' => array_slice($nearby, 0, 12),
                        'audio_url' => $media['audio_url'] ?? null,
                        'video_url' => $media['video_url'] ?? null,
                    ];
                }, $questions),
            ];
        }

        return $panels;
    }

    public function answerEnsembleQuestion(int $processingLogId, string $questionId, string $kind, ?int $slot = null): void
    {
        $this->authorizeAdmin();

        $log = MediaProcessingLog::query()->findOrFail($processingLogId);

        if (! $this->processingLogMatchesService($log)) {
            abort(404);
        }

        $sections = [];
        $explanation = null;

        if ($kind === 'correct') {
            try {
                $sections = $this->ensembleCorrectionSections($questionId);
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return;
            }
        }

        if ($kind === 'absent') {
            $explanation = trim($this->ensembleAbsenceExplanation[$questionId] ?? '');

            if ($explanation === '') {
                $this->error('Explain what happened instead of a sermon.');

                return;
            }
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            $result = app(AnswerServiceStructureEnsembleQuestion::class)->execute(
                $log->id,
                $questionId,
                $kind,
                $user,
                $slot,
                $sections,
                $explanation,
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        unset($this->ensembleCorrections[$questionId]);
        unset($this->ensembleAbsenceExplanation[$questionId]);
        $this->success(match (true) {
            $kind === 'defer' => 'Question deferred.',
            ($result['sections_synced'] ?? false) === true => 'Answer saved and structure replayed.',
            default => 'Answer saved. This run already has extracted media, so its sections were left alone; the answer applies when the structure is next re-detected.',
        });
    }

    /** Open the correction rows for a question, starting from the version the proposal wrote. */
    public function startEnsembleCorrection(int $processingLogId, string $questionId): void
    {
        $this->authorizeAdmin();

        $question = null;

        foreach ($this->ensembleReviewPanels()[$processingLogId]['questions'] ?? [] as $open) {
            if (is_array($open) && ($open['question_id'] ?? null) === $questionId) {
                $question = $open;
            }
        }

        if (! is_array($question)) {
            $this->error('This question is no longer open.');

            return;
        }

        $alternatives = is_array($question['alternatives'] ?? null) ? $question['alternatives'] : [];
        $section = $alternatives[0]['section'] ?? [
            'type' => $question['type'] ?? ServiceSectionType::Other->value,
            'start_time' => $question['start_time'] ?? 0,
            'end_time' => $question['end_time'] ?? 0,
        ];

        $this->ensembleCorrections[$questionId] = [$this->correctionRow(is_array($section) ? $section : [])];
    }

    public function addEnsembleCorrectionRow(string $questionId): void
    {
        $this->authorizeAdmin();

        $rows = $this->ensembleCorrections[$questionId] ?? [];
        $last = end($rows);
        $start = is_array($last) ? $last['end'] : '';
        $this->ensembleCorrections[$questionId][] = [
            'type' => ServiceSectionType::Other->value,
            'start' => $start,
            'end' => $start,
            'reference' => '',
            'song_title' => '',
        ];
    }

    public function removeEnsembleCorrectionRow(string $questionId, int $index): void
    {
        $this->authorizeAdmin();

        $rows = $this->ensembleCorrections[$questionId] ?? [];
        unset($rows[$index]);
        $this->ensembleCorrections[$questionId] = array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{start: float, end: float}|null
     */
    private function ensembleQuestionClip(array $question): ?array
    {
        $starts = [];
        $ends = [];

        foreach ([$question, ...array_column($question['alternatives'] ?? [], 'section')] as $span) {
            if (is_array($span) && is_numeric($span['start_time'] ?? null) && is_numeric($span['end_time'] ?? null)) {
                $starts[] = (float) $span['start_time'];
                $ends[] = (float) $span['end_time'];
            }
        }

        if ($starts === [] || $ends === []) {
            return null;
        }

        return ['start' => max(0.0, min($starts) - 10.0), 'end' => max($ends) + 10.0];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array{type: string, start: string, end: string, reference: string, song_title: string}
     */
    private function correctionRow(array $section): array
    {
        return [
            'type' => (string) ($section['type'] ?? ServiceSectionType::Other->value),
            'start' => ServiceTimestamp::format((float) ($section['start_time'] ?? 0)),
            'end' => ServiceTimestamp::format((float) ($section['end_time'] ?? 0)),
            'reference' => (string) ($section['reading_reference'] ?? $section['sermon_reference'] ?? ''),
            'song_title' => (string) ($section['song_title'] ?? ''),
        ];
    }

    /**
     * The correction rows as full sections; no rows means the claim is not there.
     *
     * @return list<array<string, mixed>>
     */
    private function ensembleCorrectionSections(string $questionId): array
    {
        $sections = [];

        foreach ($this->ensembleCorrections[$questionId] ?? [] as $number => $row) {
            $label = 'Row '.($number + 1);
            $type = ServiceSectionType::tryFrom($row['type']);
            $start = ServiceTimestamp::parse($row['start']);
            $end = ServiceTimestamp::parse($row['end']);

            if ($type === null) {
                throw new \InvalidArgumentException("{$label}: choose what this section is.");
            }

            if ($start === null || $end === null || $end <= $start) {
                throw new \InvalidArgumentException("{$label}: enter a start before the end, as h:mm:ss, m:ss or seconds.");
            }

            $reference = trim($row['reference']);
            $songTitle = trim($row['song_title']);
            $sections[] = [
                'type' => $type->value,
                'title' => null,
                'start_time' => $start,
                'end_time' => $end,
                'confidence' => 1.0,
                'song_title' => $type === ServiceSectionType::Song && $songTitle !== '' ? $songTitle : null,
                'reading_reference' => $type === ServiceSectionType::BibleReading && $reference !== '' ? $reference : null,
                'sermon_reference' => $type === ServiceSectionType::Sermon && $reference !== '' ? $reference : null,
            ];
        }

        return $sections;
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
            'selectedProposals' => ['array'],
            'selectedProposals.*' => ['boolean'],
            'proposalResolutions' => ['array'],
            'proposalResolutions.*' => ['nullable', Rule::in(['accepted', 'rejected', 'replaced'])],
            'evidenceReviewItems' => ['array'],
            'evidenceReviewItems.*.included' => ['boolean'],
            'evidenceReviewItems.*.rationale' => ['nullable', 'string', 'max:2000'],
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

        $hasPendingProposals = $this->evidenceProposals()
            ->contains(fn (ChurchServiceMergeProposal $proposal): bool => $proposal->status === ChurchServiceProposalStatus::Pending);

        // A service with nothing pending still needs a way to record its manual
        // revision, so an empty selection is only an error when there is something
        // to select. Otherwise the reviewer is silently locked out of the workbench.
        if ($proposalIds === [] && $hasPendingProposals) {
            $this->addError('selectedProposals', 'Select at least one proposal to review.');

            return;
        }

        $items = $this->evidenceReviewItems;

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
        $this->success(
            $result->churchService->needs_review
                ? 'Selected evidence saved. Remaining proposals still need review.'
                : 'Selected evidence reviewed. Source records and proposal history were preserved.',
        );
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
            ->mapWithKeys(fn (ChurchServiceMergeProposal $proposal): array => [$proposal->id => null])
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
                'rationale' => '',
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
