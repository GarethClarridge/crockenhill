<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Actions\ServiceReview\BatchApproveServicePublications;
use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Actions\ServiceReview\ConfirmServiceSections;
use App\Actions\ServiceReview\MarkServiceReviewed;
use App\Actions\ServiceReview\MergeAdjacentServiceSections;
use App\Actions\ServiceReview\MergeInterruptedTalk;
use App\Actions\ServiceReview\SaveServiceSection;
use App\Actions\ServiceReview\SetOutputEdge;
use App\Actions\ServiceReview\SplitServiceSection;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Queries\ServiceReviewDashboardQuery;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\OutputEdgeCheck;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\ServiceTimestamp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Section review editing for the service workbench: inline type/title/boundary edits,
 * children's-talk speaker picks, batch approval, adjacent-section merging, merging a talk
 * across the readings or prayers that interrupted it, splitting a section in two, and an
 * operator's "start here" / "end here" for a sermon's or a talk's output ({@see SetOutputEdge}).
 *
 * Edit state is seeded for review-candidate sections only — seeding every
 * section of every run would balloon the Livewire payload.
 *
 * @property-read list<array{id: string, name: string}> $sectionTypeOptions
 * @property-read list<array{id: string, name: string}> $preacherOptions
 */
trait ReviewsServiceSections
{
    /**
     * @var list<string>
     */
    private const NonPersonSpeakerNames = [
        'god is with us',
        'palm sunday',
        'the gospel of john',
    ];

    /**
     * @var array<int, array{section_type: string, title: string, end_time: string, talk_type?: string, church_service_item_id?: string, sermon_section_ids?: string, sermon_composition_identity?: string}>
     */
    public array $sectionEdits = [];

    /**
     * @var array<int, array{preacher_id: string, speaker_name: string}>
     */
    public array $speakerEdits = [];

    /**
     * @var array{primary_id: int, secondary_id: int}|null
     */
    public ?array $pendingSectionMerge = null;

    /**
     * The talk a reviewer has asked to merge across its interruption, awaiting confirmation.
     */
    public ?int $pendingInterruptedTalkMerge = null;

    /**
     * Split times the reviewer has typed, in seconds into the recording, keyed by section id.
     *
     * @var array<int, string>
     */
    public array $splitTimes = [];

    /**
     * The start and end times a reviewer has typed for an output, keyed by section id and edge.
     *
     * @var array<int, array{start?: string, end?: string}>
     */
    public array $outputEdgeTimes = [];

    /**
     * The last read-only plan check of each output's edges ({@see OutputEdgeCheck}), keyed by section id.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $outputEdgeChecks = [];

    protected ServiceReviewDashboardQuery $dashboardQuery;

    protected SaveServiceSection $saveSectionAction;

    protected MarkServiceReviewed $markReviewedAction;

    protected ConfirmServiceSection $confirmSectionAction;

    protected ConfirmServiceSections $confirmSectionsAction;

    protected BatchApproveServicePublications $batchApproveAction;

    protected MergeAdjacentServiceSections $mergeAction;

    protected MergeInterruptedTalk $interruptedTalkMergeAction;

    protected SplitServiceSection $splitAction;

    public function bootReviewsServiceSections(
        ServiceReviewDashboardQuery $dashboardQuery,
        SaveServiceSection $saveSectionAction,
        ConfirmServiceSection $confirmSectionAction,
        ConfirmServiceSections $confirmSectionsAction,
        MarkServiceReviewed $markReviewedAction,
        BatchApproveServicePublications $batchApproveAction,
        MergeAdjacentServiceSections $mergeAction,
        MergeInterruptedTalk $interruptedTalkMergeAction,
        SplitServiceSection $splitAction,
    ): void {
        $this->dashboardQuery = $dashboardQuery;
        $this->saveSectionAction = $saveSectionAction;
        $this->confirmSectionAction = $confirmSectionAction;
        $this->confirmSectionsAction = $confirmSectionsAction;
        $this->markReviewedAction = $markReviewedAction;
        $this->batchApproveAction = $batchApproveAction;
        $this->mergeAction = $mergeAction;
        $this->interruptedTalkMergeAction = $interruptedTalkMergeAction;
        $this->splitAction = $splitAction;
    }

    public function saveSection(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! $this->dashboardQuery->isReviewCandidate($section)) {
            $this->error('Section is no longer awaiting review.');

            return;
        }

        try {
            $this->saveSectionAction->execute(
                section: $section,
                sectionEdits: $this->sectionEdits,
                speakerEdits: $this->speakerEdits,
                userId: $this->reviewingUserId(),
            );
        } catch (ValidationException $exception) {
            // The action names fields plainly; each control shows the errors bound to its own model.
            $scoped = [];
            foreach ($exception->errors() as $field => $messages) {
                $edits = in_array($field, ['preacher_id', 'speaker_name'], true) ? 'speakerEdits' : 'sectionEdits';
                $scoped["{$edits}.{$section->id}.{$field}"] = $messages;
            }

            throw ValidationException::withMessages([...$exception->errors(), ...$scoped]);
        }

        $this->sectionEdits[$section->id] = $this->sectionEditState($section);
        $publicationSpeaker = $section->publicationTalkSpeaker();
        $this->speakerEdits[$section->id] = [
            'preacher_id' => is_array($publicationSpeaker) && is_numeric($publicationSpeaker['preacher_id'] ?? null)
                ? (string) $publicationSpeaker['preacher_id']
                : '',
            'speaker_name' => $publicationSpeaker['preacher_name'] ?? '',
        ];

        $this->success('Section changes saved.');
    }

    /**
     * Record the reviewer's typed time as the output's start or end. Nothing is cut here: the
     * planner applies it on the next cut, and a check shows whether it is placed.
     */
    public function setOutputEdge(int $sectionId, string $edge): void
    {
        $this->recordOutputEdge($sectionId, $edge, $this->outputEdgeTimes[$sectionId][$edge] ?? '');
    }

    public function clearOutputEdge(int $sectionId, string $edge): void
    {
        $this->recordOutputEdge($sectionId, $edge, null);
    }

    /** Resolve the output's next cut read only and show where each edge lands, or why it is refused. */
    public function checkOutputEdges(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);

        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $this->outputEdgeChecks[$sectionId] = app(OutputEdgeCheck::class)->check($section);
    }

    /**
     * What the panel's start and end controls show for a sermon or talk: each edge's current bound,
     * the answer set and who set it, and the transcript lines and audio clip around it, in
     * recording time. Null for any other section.
     *
     * @return array{audio_url: string|null, edges: array<'start'|'end', array{bound: float, around: float, clip: array{start: float, end: float}, answer: array<string, mixed>|null, cues: list<array{start: float, end: float, text: string}>}>}|null
     */
    public function outputEdgePanelFor(ServiceSection $section): ?array
    {
        $output = app(SetOutputEdge::class)->outputSections($section);

        if ($output === null) {
            return null;
        }

        $log = $section->processingLog;
        $answers = OutputEdgeAnswers::recordsOf($section);
        $readCues = static fn (): array => app(OutputEdgeWordTimings::class)->cues($log);

        try {
            $context = $log->historicStagingContext();
            $cues = $context === null ? $readCues() : app(HistoricStagingContextRegistry::class)->within($context, $readCues);
        } catch (\Throwable) {
            $cues = [];
        }

        $edges = [];

        foreach (['start' => (float) $output[0]->start_time, 'end' => (float) $output[count($output) - 1]->end_time] as $edge => $bound) {
            $around = $answers[$edge]['time'] ?? $bound;
            $edges[$edge] = [
                'bound' => $bound,
                'around' => $around,
                'clip' => ['start' => max(0.0, $around - 20.0), 'end' => $around + 20.0],
                'answer' => $answers[$edge] ?? null,
                'cues' => array_values(array_filter($cues, static fn (array $cue): bool => $cue['end'] >= $around - 25.0 && $cue['start'] <= $around + 25.0)),
            ];
        }

        $hasAudio = collect(ServiceArtifactStorage::recordedFor($log))->contains(static fn (array $entry): bool => $entry['kind'] === 'audio');

        return ['audio_url' => $hasAudio ? route('admin.recordings.service-audio', $log) : null, 'edges' => $edges];
    }

    private function recordOutputEdge(int $sectionId, string $edge, ?string $at): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);

        if (! $section instanceof ServiceSection || ! in_array($edge, ['start', 'end'], true)) {
            $this->error('Section not found.');

            return;
        }

        try {
            app(SetOutputEdge::class)->execute($section, $edge, $at, $this->reviewingUserId());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(["outputEdgeTimes.{$sectionId}.{$edge}" => array_merge(...array_values($exception->errors()))]);
        }

        unset($this->outputEdgeTimes[$sectionId][$edge], $this->outputEdgeChecks[$sectionId]);
        $this->success($at === null
            ? sprintf('The %s is cleared: the next cut uses the planner\'s own.', $edge)
            : sprintf('%s set at %s. It applies on the next cut; check it against the plan below.', ucfirst($edge), ServiceTimestamp::format((float) ServiceTimestamp::parse($at))));
    }

    public function confirmSection(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $section->loadMissing('churchServiceItem');

        $skipReason = $this->dashboardQuery->confirmationSkipReason($section);
        if ($skipReason !== null) {
            $this->warning($this->formatConfirmationSkipReason($skipReason));

            return;
        }

        $this->confirmSectionAction->execute($section, $this->reviewingUserId());

        $this->success('Section confirmed.');
    }

    public function confirmAllSections(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $result = $this->confirmSectionsAction->execute($service, $this->reviewingUserId());
        $confirmedCount = $result['confirmed_count'];
        $skippedReasons = $result['skipped_reasons'];

        if ($confirmedCount === 0) {
            $this->warning(sprintf(
                'No sections were confirmed. %s',
                $this->formatBatchApprovalSkipSummary($skippedReasons)
            ));

            return;
        }

        if ($skippedReasons === []) {
            $this->success(sprintf(
                'Confirmed all %d %s for this service.',
                $confirmedCount,
                Str::plural('section', $confirmedCount)
            ));

            return;
        }

        $this->success(sprintf(
            'Confirmed %d %s. %s',
            $confirmedCount,
            Str::plural('section', $confirmedCount),
            $this->formatBatchApprovalSkipSummary($skippedReasons)
        ));
    }

    public function markServiceReviewed(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $warning = $this->markReviewedAction->execute($service, $this->reviewingUserId());

        if ($warning !== null) {
            $this->warning($warning);

            return;
        }

        $this->success('Service marked as reviewed.');
    }

    public function approvePendingPublications(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $result = $this->batchApproveAction->execute($service, $this->reviewingUserId());

        $approvedCount = $result['approved_count'];
        $skippedReasons = $result['skipped_reasons'];

        if ($approvedCount === 0) {
            $this->error(sprintf(
                'No sections were batch-approved. %s',
                $this->formatBatchApprovalSkipSummary($skippedReasons)
            ));

            return;
        }

        if ($skippedReasons === []) {
            $this->success(sprintf(
                'Approved all %d pending %s for this service.',
                $approvedCount,
                Str::plural('publication', $approvedCount)
            ));

            return;
        }

        $this->success(sprintf(
            'Approved %d pending %s. %s',
            $approvedCount,
            Str::plural('publication', $approvedCount),
            $this->formatBatchApprovalSkipSummary($skippedReasons)
        ));
    }

    public function initiateMerge(int $sectionIdA, int $sectionIdB): void
    {
        $this->authorizeAdmin();

        $this->pendingSectionMerge = ['primary_id' => $sectionIdA, 'secondary_id' => $sectionIdB];
    }

    public function confirmMerge(): void
    {
        $this->authorizeAdmin();

        if ($this->pendingSectionMerge === null) {
            return;
        }

        $primary = ServiceSection::query()->find($this->pendingSectionMerge['primary_id']);
        $secondary = ServiceSection::query()->find($this->pendingSectionMerge['secondary_id']);

        if (! $primary instanceof ServiceSection || ! $secondary instanceof ServiceSection) {
            $this->pendingSectionMerge = null;
            $this->error('One or both sections could not be found.');

            return;
        }

        $error = $this->mergeAction->execute($primary, $secondary, $this->reviewingUserId());

        $this->pendingSectionMerge = null;

        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->success('Sections merged successfully.');
    }

    public function cancelMerge(): void
    {
        $this->authorizeAdmin();

        $this->pendingSectionMerge = null;
    }

    public function initiateInterruptedTalkMerge(int $talkId): void
    {
        $this->authorizeAdmin();

        $this->pendingInterruptedTalkMerge = $talkId;
    }

    public function confirmInterruptedTalkMerge(): void
    {
        $this->authorizeAdmin();

        if ($this->pendingInterruptedTalkMerge === null) {
            return;
        }

        $talk = ServiceSection::query()->find($this->pendingInterruptedTalkMerge);
        $this->pendingInterruptedTalkMerge = null;

        if (! $talk instanceof ServiceSection) {
            $this->error('The talk could not be found.');

            return;
        }

        $error = $this->interruptedTalkMergeAction->execute($talk, $this->reviewingUserId());

        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->success('Talk merged across its interruption.');
    }

    public function cancelInterruptedTalkMerge(): void
    {
        $this->authorizeAdmin();

        $this->pendingInterruptedTalkMerge = null;
    }

    public function splitSection(int $sectionId): void
    {
        $this->authorizeAdmin();

        $at = trim($this->splitTimes[$sectionId] ?? '');

        if (! is_numeric($at)) {
            $this->error('Enter the split time in seconds into the recording.');

            return;
        }

        $section = ServiceSection::query()->find($sectionId);

        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $error = $this->splitAction->execute($section, (float) $at, $this->reviewingUserId());

        if ($error !== null) {
            $this->error($error);

            return;
        }

        unset($this->splitTimes[$sectionId]);
        $seconds = (int) round((float) $at);
        $this->success(sprintf('Section split in two at %d:%02d.', intdiv($seconds, 60), $seconds % 60));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function sectionTypeOptions(): array
    {
        return array_map(
            fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ],
            ServiceSectionType::cases()
        );
    }

    /** @var array<int, list<array{id: string, name: string}>> */
    private array $serviceItemOptionsByService = [];

    /**
     * The order of service a section can be linked to: its own service's items, in printed order.
     *
     * @return list<array{id: string, name: string}>
     */
    public function serviceItemOptionsFor(ServiceSection $section): array
    {
        $serviceId = $section->processingLog->church_service_id;

        if ($serviceId === null) {
            return [];
        }

        return $this->serviceItemOptionsByService[$serviceId] ??= array_values(ChurchServiceItem::query()
            ->where('church_service_id', $serviceId)
            ->orderBy('position')
            ->get(['id', 'position', 'title', 'type', 'section_type'])
            ->map(static fn (ChurchServiceItem $item): array => [
                'id' => (string) $item->id,
                'name' => sprintf('%d. %s (%s)', $item->position, $item->title, strtolower($item->semanticSectionType()->label())),
            ])
            ->all());
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function preacherOptions(): array
    {
        return array_values(array_map(
            fn (Preacher $preacher): array => [
                'id' => (string) $preacher->id,
                'name' => $preacher->name,
            ],
            Preacher::query()->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (Preacher $preacher): bool => $this->isPlausibleSpeakerName($preacher->name))
                ->all()
        ));
    }

    private function isPlausibleSpeakerName(string $name): bool
    {
        $normalizedName = Str::lower(trim($name));

        if (preg_match('/^\d{3}[ab]$/', $normalizedName) === 1) {
            return false;
        }

        return ! in_array($normalizedName, self::NonPersonSpeakerNames, true);
    }

    /**
     * The talk type starts at the confirmed value and is otherwise unchosen. The
     * proposal is shown beside it, never preselected: every short talk's first save
     * also sets its speaker, and that save must not confirm the detector's guess.
     *
     * @return array{section_type: string, title: string, end_time: string, talk_type: string, church_service_item_id: string, sermon_section_ids?: string, sermon_composition_identity?: string}
     */
    private function sectionEditState(ServiceSection $section): array
    {
        $state = [
            'section_type' => $section->section_type->value,
            'title' => (string) ($section->title ?? ''),
            'end_time' => (string) $section->end_time,
            'talk_type' => $section->publicationTalkType()->value ?? '',
            'church_service_item_id' => (string) ($section->church_service_item_id ?? ''),
        ];
        if ($section->section_type === ServiceSectionType::Sermon && is_array($section->metadata?->raw['sermon_boundary'] ?? null)) {
            $state['sermon_section_ids'] = implode(', ', $section->metadata->raw['sermon_boundary']['selected_section_ids'] ?? []);
            $state['sermon_composition_identity'] = (string) ($section->metadata->raw['sermon_boundary']['input_identity'] ?? '');
        }

        return $state;
    }

    /**
     * @param  iterable<int, ServiceSection>  $sections
     */
    protected function seedSectionEditsForSections(iterable $sections): void
    {
        foreach ($sections as $section) {
            if (! array_key_exists($section->id, $this->sectionEdits)) {
                $this->sectionEdits[$section->id] = $this->sectionEditState($section);
            }

            if (! array_key_exists($section->id, $this->speakerEdits)) {
                $speaker = $section->publicationTalkSpeaker();
                $this->speakerEdits[$section->id] = [
                    'preacher_id' => is_array($speaker) && is_numeric($speaker['preacher_id'] ?? null)
                        ? (string) $speaker['preacher_id']
                        : '',
                    'speaker_name' => is_string($speaker['preacher_name'] ?? null)
                        ? (string) $speaker['preacher_name']
                        : '',
                ];
            }
        }
    }

    /**
     * @param  array<string, int>  $skippedReasons
     */
    protected function formatBatchApprovalSkipSummary(array $skippedReasons): string
    {
        $totalSkipped = array_sum($skippedReasons);
        if ($totalSkipped === 0) {
            return 'No sections were skipped.';
        }

        $reasons = collect($skippedReasons)
            ->map(fn (int $count, string $reason): string => sprintf('%s (%d)', $reason, $count))
            ->implode(', ');

        return sprintf(
            'Skipped %d %s: %s.',
            $totalSkipped,
            Str::plural('section', $totalSkipped),
            $reasons
        );
    }

    protected function formatConfirmationSkipReason(string $skipReason): string
    {
        return match ($skipReason) {
            'speaker review required' => 'Choose a speaker before confirming this section.',
            'unmatched song' => 'Resolve the song match before confirming this section.',
            default => 'This section is no longer awaiting manual review.',
        };
    }

    protected function reviewingUserId(): int
    {
        return is_numeric(Auth::id()) ? (int) Auth::id() : 0;
    }
}
