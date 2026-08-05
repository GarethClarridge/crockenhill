<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchServiceProposalClassReview;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use App\Services\ChurchService\ChurchServiceProposalRuleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;

class ReviewChurchServiceProposalQueue extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public string $selectedClassKey = '';

    /** @var list<int> */
    public array $selectedProposalIds = [];

    public string $disposition = 'accepted';

    public string $rationale = '';

    public string $markClassKey = '';

    public string $markStatus = ChurchServiceProposalClassReview::AUTOMATED;

    public string $markReason = '';

    public ?int $markSecondsPerDecision = null;

    /**
     * Built once per request and shared by render and every action, because the
     * census scans every pending proposal in the corpus.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $censusClasses = null;

    public function render(ChurchServiceProposalCensusGate $gate, ChurchServiceCorpusCompleteness $corpus): View
    {
        $classes = $this->classes();
        $result = $gate->evaluate($classes, $corpus->evidence());

        return view('livewire.admin.church-services.review-church-service-proposal-queue', [
            'classes' => $classes,
            'gate' => $result,
            'corpusBlockerMessages' => array_map(
                static fn (string $blocker): string => $gate->describeCorpusBlocker($blocker),
                $result['corpus_blockers'],
            ),
        ])->layout('layouts.admin', [
            'title' => 'Evidence proposal queue',
            'heading' => 'Evidence proposal queue',
        ]);
    }

    public function selectClass(string $classKey): void
    {
        $this->authorizeAdmin();

        $class = $this->class($classKey);

        if ($class === null) {
            $this->error('That proposal class is no longer pending. Refresh the queue.');

            return;
        }

        $this->selectedClassKey = $classKey;
        $this->selectedProposalIds = $class['proposal_ids'];
    }

    /**
     * Selection is per proposal so a reviewer who spots an outlier in the sample can
     * leave it out of the rule instead of abandoning the class.
     */
    public function toggleProposal(string $classKey, int $proposalId): void
    {
        $this->authorizeAdmin();

        $class = $this->class($classKey);

        if ($class === null || ! in_array($proposalId, $class['proposal_ids'], true)) {
            $this->error('That proposal is no longer pending in this class. Refresh the queue.');

            return;
        }

        if ($this->selectedClassKey !== $classKey) {
            $this->selectedClassKey = $classKey;
            $this->selectedProposalIds = [];
        }

        $this->selectedProposalIds = in_array($proposalId, $this->selectedProposalIds, true)
            ? array_values(array_filter(
                $this->selectedProposalIds,
                static fn (int $id): bool => $id !== $proposalId,
            ))
            : [...$this->selectedProposalIds, $proposalId];
    }

    public function clearSelection(): void
    {
        $this->authorizeAdmin();

        $this->reset('selectedClassKey', 'selectedProposalIds', 'rationale');
        $this->disposition = 'accepted';
    }

    public function applyDecisionRule(ChurchServiceProposalRuleService $rules): void
    {
        $this->authorizeAdmin();

        $validated = $this->validate([
            'selectedClassKey' => ['required', 'string', 'size:64'],
            'selectedProposalIds' => ['required', 'array', 'min:1'],
            'selectedProposalIds.*' => ['integer', 'distinct', 'min:1'],
            'disposition' => ['required', Rule::in(['accepted', 'rejected'])],
            'rationale' => ['required', 'string', 'max:2000'],
        ]);

        $userId = Auth::id();

        if (! is_int($userId)) {
            $this->error('A signed-in administrator is required to apply a proposal rule.');

            return;
        }

        try {
            $rules->apply(
                $validated['selectedClassKey'],
                array_values(array_map(intval(...), $validated['selectedProposalIds'])),
                $validated['disposition'],
                trim($validated['rationale']),
                $userId,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->censusClasses = null;
        $this->reset('selectedClassKey', 'selectedProposalIds', 'rationale');
        $this->disposition = 'accepted';
        $this->success('Decision rule applied to the explicitly selected proposals.');
    }

    public function startMarkingClass(string $classKey): void
    {
        $this->authorizeAdmin();

        $class = $this->class($classKey);

        if ($class === null) {
            $this->error('That proposal class is no longer pending. Refresh the queue.');

            return;
        }

        $this->markClassKey = $classKey;
        $this->markStatus = $class['status'] === ChurchServiceProposalClassReview::UNCLASSIFIED
            ? ChurchServiceProposalClassReview::AUTOMATED
            : $class['status'];
        $this->markReason = (string) ($class['reason'] ?? '');
        $this->markSecondsPerDecision = $class['seconds_per_decision'];
    }

    /**
     * Records the §9.4.6 standing of a class. An irreducible class must carry a
     * measured per-decision time, so the residual figure is observed rather than
     * estimated.
     */
    public function markClass(): void
    {
        $this->authorizeAdmin();

        $validated = $this->validate([
            'markClassKey' => ['required', 'string', 'size:64'],
            'markStatus' => ['required', Rule::in(ChurchServiceProposalClassReview::STATUSES)],
            'markReason' => ['required', 'string', 'max:2000'],
            'markSecondsPerDecision' => [
                Rule::requiredIf($this->markStatus === ChurchServiceProposalClassReview::IRREDUCIBLE),
                'nullable',
                'integer',
                'min:1',
                'max:86400',
            ],
        ], attributes: [
            'markReason' => 'reason',
            'markSecondsPerDecision' => 'measured seconds per decision',
        ]);

        $userId = Auth::id();

        if (! is_int($userId)) {
            $this->error('A signed-in administrator is required to mark a proposal class.');

            return;
        }

        if ($this->class($validated['markClassKey']) === null) {
            $this->error('That proposal class is no longer pending. Refresh the queue.');

            return;
        }

        ChurchServiceProposalClassReview::query()->updateOrCreate(
            ['class_key' => $validated['markClassKey']],
            [
                'status' => $validated['markStatus'],
                'reason' => trim($validated['markReason']),
                'seconds_per_decision' => $validated['markStatus'] === ChurchServiceProposalClassReview::IRREDUCIBLE
                    ? $validated['markSecondsPerDecision']
                    : null,
                'marked_by_user_id' => $userId,
            ],
        );

        $this->censusClasses = null;
        $this->reset('markClassKey', 'markReason', 'markSecondsPerDecision');
        $this->markStatus = ChurchServiceProposalClassReview::AUTOMATED;
        $this->success('Proposal class recorded.');
    }

    /** @return list<array<string, mixed>> */
    private function classes(): array
    {
        return $this->censusClasses ??= app(ChurchServiceProposalCensus::class)->build();
    }

    /** @return array<string, mixed>|null */
    private function class(string $classKey): ?array
    {
        foreach ($this->classes() as $class) {
            if ($class['class_key'] === $classKey) {
                return $class;
            }
        }

        return null;
    }
}
