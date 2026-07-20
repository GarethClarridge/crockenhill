<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Actions\ServiceReview\BatchApproveServicePublications;
use App\Actions\ServiceReview\MarkServiceReviewed;
use App\Actions\ServiceReview\MergeAdjacentServiceSections;
use App\Actions\ServiceReview\SaveServiceSection;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Queries\ServiceReviewDashboardQuery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Section review editing for the service workbench: inline type/title edits, children's-talk
 * speaker picks, batch approval, and adjacent-section merging.
 *
 * Edit state is seeded for review-candidate sections only — seeding every
 * section of every run would balloon the Livewire payload.
 */
trait ReviewsServiceSections
{
    /**
     * @var array<int, array{section_type: string, title: string}>
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

    protected ServiceReviewDashboardQuery $dashboardQuery;

    protected SaveServiceSection $saveSectionAction;

    protected MarkServiceReviewed $markReviewedAction;

    protected BatchApproveServicePublications $batchApproveAction;

    protected MergeAdjacentServiceSections $mergeAction;

    public function bootReviewsServiceSections(
        ServiceReviewDashboardQuery $dashboardQuery,
        SaveServiceSection $saveSectionAction,
        MarkServiceReviewed $markReviewedAction,
        BatchApproveServicePublications $batchApproveAction,
        MergeAdjacentServiceSections $mergeAction,
    ): void {
        $this->dashboardQuery = $dashboardQuery;
        $this->saveSectionAction = $saveSectionAction;
        $this->markReviewedAction = $markReviewedAction;
        $this->batchApproveAction = $batchApproveAction;
        $this->mergeAction = $mergeAction;
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

        $this->saveSectionAction->execute(
            section: $section,
            sectionEdits: $this->sectionEdits,
            speakerEdits: $this->speakerEdits,
            userId: $this->reviewingUserId(),
        );

        $this->sectionEdits[$section->id] = [
            'section_type' => $section->section_type->value,
            'title' => (string) ($section->title ?? ''),
        ];
        $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
        $this->speakerEdits[$section->id] = [
            'preacher_id' => is_array($publicationSpeaker) && is_numeric($publicationSpeaker['preacher_id'] ?? null)
                ? (string) $publicationSpeaker['preacher_id']
                : '',
            'speaker_name' => $publicationSpeaker['preacher_name'] ?? '',
        ];

        $this->success('Section changes saved.');
    }

    public function markServiceReviewed(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $this->markReviewedAction->execute($service, $this->reviewingUserId());

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

    /**
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function sectionTypeOptions(): array
    {
        return collect(ServiceSectionType::cases())
            ->map(fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function preacherOptions(): array
    {
        return Preacher::query()->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Preacher $preacher): array => [
                'id' => (string) $preacher->id,
                'name' => $preacher->name,
            ])
            ->all();
    }

    /**
     * @param  iterable<int, ServiceSection>  $sections
     */
    protected function seedSectionEditsForSections(iterable $sections): void
    {
        foreach ($sections as $section) {
            if (! array_key_exists($section->id, $this->sectionEdits)) {
                $this->sectionEdits[$section->id] = [
                    'section_type' => $section->section_type->value,
                    'title' => (string) ($section->title ?? ''),
                ];
            }

            if (! array_key_exists($section->id, $this->speakerEdits)) {
                $speaker = $section->publicationChildrensTalkSpeaker();
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

    protected function reviewingUserId(): int
    {
        return is_numeric(Auth::id()) ? (int) Auth::id() : 0;
    }
}
