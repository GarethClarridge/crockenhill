<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\ServiceSectionPublicationStatus;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ServiceSection;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListSectionPublications extends Component
{
    use EscapesLikeWildcards;
    use ManagesSectionPublication;
    use WithAdminAuthorization;
    use WithNotifications;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'pending_approval')]
    public string $publicationStatus = ServiceSectionPublicationStatus::PendingApproval->value;

    public bool $hasFilters = false;

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPublicationStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search']);
        $this->publicationStatus = ServiceSectionPublicationStatus::PendingApproval->value;
        $this->resetPage();
    }

    public function render(): View
    {
        $search = trim($this->search);
        $escapedSearch = $this->escapeLike($search);
        $searchPattern = '%'.$escapedSearch.'%';
        $this->hasFilters = $search !== ''
            || $this->publicationStatus !== ServiceSectionPublicationStatus::PendingApproval->value;

        $sections = ServiceSection::query()
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata',
                'publishedSermon:id,title,slug,content_type',
            ])
            ->when($this->publicationStatus !== '', fn ($query) => $query->where('publication_status', $this->publicationStatus))
            ->when($search !== '', function ($query) use ($searchPattern): void {
                $query->where(function ($query) use ($searchPattern): void {
                    $query->where('title', 'like', $searchPattern)
                        ->orWhere('section_type', 'like', $searchPattern)
                        ->orWhereHas('processingLog', fn ($query) => $query->where('processing_id', 'like', $searchPattern));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.church-services.list-section-publications', [
            'sections' => $sections,
            'statuses' => ServiceSectionPublicationStatus::cases(),
        ])->layout('layouts.admin', [
            'title' => 'Section Publications',
            'heading' => 'Section Publications',
        ]);
    }

    private function abortIfDisabled(): void
    {
        if (
            ! (bool) config('service-tracking.enabled', true)
            || ! (bool) config('media-processing.section_publishing.enabled', true)
        ) {
            abort(404);
        }
    }
}
