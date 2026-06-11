<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Admin\ChurchServices\Concerns\ReviewsServiceSections;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use Illuminate\View\View;
use Livewire\Component;

class ServiceReviewDashboard extends Component
{
    use ManagesSectionPublication;
    use ReviewsServiceSections;
    use WithAdminAuthorization;
    use WithNotifications;

    public function mount(): void
    {
        $this->abortIfDisabled();

        $groups = $this->dashboardQuery->reviewGroups();

        $this->seedSectionEditsForSections(
            collect($groups)->flatMap(
                fn (array $group) => collect($group['sections'])->map(
                    fn (array $entry): ServiceSection => $entry['section']
                )
            )
        );
    }

    public function render(): View
    {
        $groups = $this->dashboardQuery->reviewGroups();

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

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
