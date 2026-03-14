<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\DB;
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
            ->where('processing_type', MediaType::Livestream->value)
            ->where('status', ProcessingStatus::FAILED->value)
            ->where('current_step', 'manual_review_required')
            ->whereNotNull(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(processing_metadata, '$.manual_review.reason_code'))"))
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
