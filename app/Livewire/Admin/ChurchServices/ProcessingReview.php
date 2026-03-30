<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\MediaType;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\MediaProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ProcessingReview extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public int $processingLogId;

    public bool $confirming = false;

    private VideoStorageService $videoStorageService;

    public function boot(VideoStorageService $videoStorageService): void
    {
        $this->videoStorageService = $videoStorageService;
    }

    public function mount(MediaProcessingLog $processingLog): void
    {

        $this->authorizeAdmin();

        if ($processingLog->processing_type !== MediaType::Livestream) {
            abort(404);
        }

        $this->processingLogId = $processingLog->id;
    }

    public function confirmSegment(int $segmentId): void
    {

        $this->authorizeAdmin();

        $this->confirming = true;

        $log = MediaProcessingLog::findOrFail($this->processingLogId);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            app(ConfirmLivestreamSermonSegment::class)->execute(
                $log->processing_id,
                $segmentId,
                $user
            );
        } catch (\InvalidArgumentException $e) {
            $this->confirming = false;
            $this->error($e->getMessage());

            return;
        }

        $this->success(
            'Sermon segment confirmed. Processing will resume shortly.',
            route('admin.services.processing.review.index')
        );
    }

    public function render(): View
    {
        $log = MediaProcessingLog::with('segments')->findOrFail($this->processingLogId);

        $segments = $log->segments
            ->sortBy('start_time')
            ->values();

        $reviewMeta = $log->manualReviewMetadata();
        $sourceAvailable = $this->checkSourceAvailable($log);

        return view('livewire.admin.church-services.processing-review', [
            'log' => $log,
            'segments' => $segments,
            'reviewMeta' => $reviewMeta,
            'sourceAvailable' => $sourceAvailable,
            'confirmedSegmentId' => $log->manuallyConfirmedSegmentId(),
            'requiresReview' => $log->requiresManualSermonReview(),
        ])->layout('layouts.admin', [
            'title' => 'Review Livestream Processing',
            'heading' => 'Review Livestream Processing',
        ]);
    }

    private function checkSourceAvailable(MediaProcessingLog $log): bool
    {
        if (! is_string($log->source_file_path) || $log->source_file_path === '') {
            return false;
        }

        return $this->videoStorageService->sourceVideoExistsForPath($log->source_file_path);
    }
}
