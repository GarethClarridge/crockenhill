<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\MediaProcessingLog;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ProcessingReviewList extends Component
{
    use WithAdminAuthorization, WithPagination;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function render(): View
    {
        $pendingReviews = MediaProcessingLog::query()
            ->awaitingManualSermonReview()
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.church-services.processing-review-list', [
            'pendingReviews' => $pendingReviews,
        ])->layout('layouts.admin', [
            'title' => 'Livestream Review Queue',
            'heading' => 'Livestream Review Queue',
        ]);
    }
}
