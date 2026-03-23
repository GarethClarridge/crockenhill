<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\ServiceReview\BatchApproveServicePublications;
use App\Actions\ServiceReview\MarkServiceReviewed;
use App\Actions\ServiceReview\SaveServiceSection;
use App\Enums\ServiceSectionType;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Queries\ServiceReviewDashboardQuery;
use App\Support\ServiceSectionConfidence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ServiceReviewDashboard extends Component
{
    use ManagesSectionPublication;
    use WithNotifications;

    /**
     * @var array<int, array{section_type:string,title:string}>
     */
    public array $sectionEdits = [];

    /**
     * @var array<int, array{preacher_id:string,speaker_name:string}>
     */
    public array $speakerEdits = [];

    private ServiceReviewDashboardQuery $dashboardQuery;

    private SaveServiceSection $saveSectionAction;

    private MarkServiceReviewed $markReviewedAction;

    private BatchApproveServicePublications $batchApproveAction;

    public function boot(
        ServiceReviewDashboardQuery $dashboardQuery,
        SaveServiceSection $saveSectionAction,
        MarkServiceReviewed $markReviewedAction,
        BatchApproveServicePublications $batchApproveAction,
    ): void {
        $this->dashboardQuery = $dashboardQuery;
        $this->saveSectionAction = $saveSectionAction;
        $this->markReviewedAction = $markReviewedAction;
        $this->batchApproveAction = $batchApproveAction;
    }

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    public function saveSection(int $sectionId): void
    {
        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! $this->dashboardQuery->isReviewCandidate($section)) {
            $this->error('Section is no longer awaiting review.');

            return;
        }

        $userId = is_numeric(Auth::id()) ? (int) Auth::id() : 0;

        $this->saveSectionAction->execute(
            section: $section,
            sectionEdits: $this->sectionEdits,
            speakerEdits: $this->speakerEdits,
            userId: $userId,
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
        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $userId = is_numeric(Auth::id()) ? (int) Auth::id() : 0;

        $this->markReviewedAction->execute($service, $userId);

        $this->success('Service marked as reviewed.');
    }

    public function approvePendingPublications(int $serviceId): void
    {
        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $userId = is_numeric(Auth::id()) ? (int) Auth::id() : 0;

        $result = $this->batchApproveAction->execute($service, $userId);

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

    public function render(): View
    {
        $groups = $this->dashboardQuery->reviewGroups();
        $this->seedSectionEdits($groups);

        return view('livewire.admin.church-services.service-review-dashboard', [
            'groups' => $groups,
            'sectionTypeOptions' => $this->sectionTypeOptions(),
            'preacherOptions' => $this->preacherOptions(),
            'summary' => $this->dashboardQuery->summary($groups),
            'lowConfidenceThreshold' => ServiceSectionConfidence::HIGH_THRESHOLD,
        ])->layout('layouts.admin', [
            'title' => 'Service Review Dashboard',
            'heading' => 'Service Review Dashboard',
        ]);
    }

    /**
     * @return array<int, array{id:string,name:string}>
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
     * @return array<int, array{id:string,name:string}>
     */
    #[Computed]
    public function preacherOptions(): array
    {
        return Preacher::active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Preacher $preacher): array => [
                'id' => (string) $preacher->id,
                'name' => $preacher->name,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{
     *     sections:array<int, array{section:ServiceSection}>
     * }>  $groups
     */
    private function seedSectionEdits(array $groups): void
    {
        foreach ($groups as $group) {
            foreach ($group['sections'] as $entry) {
                $section = $entry['section'];

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
    }

    /**
     * @param  array<string, int>  $skippedReasons
     */
    private function formatBatchApprovalSkipSummary(array $skippedReasons): string
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

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
