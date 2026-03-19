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
            ->select([
                'id',
                'processing_id',
                'processing_type',
                'status',
                'current_step',
                'error_message',
                'original_filename',
                'extracted_date',
                'extracted_service',
                'processing_metadata',
                'updated_at',
            ])
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
