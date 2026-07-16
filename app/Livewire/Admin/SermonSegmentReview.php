<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;

class SermonSegmentReview extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public MediaProcessingLog $processingLog;

    public function mount(MediaProcessingLog $processingLog): void
    {
        abort_unless($processingLog->canUseManualSermonReview(), 404);

        $this->processingLog = $processingLog;
    }

    public function confirmSegment(int $segmentId, ConfirmLivestreamSermonSegment $confirmSegment): void
    {
        $this->authorizeAdmin();

        /** @var User $user */
        $user = Auth::user();

        try {
            $confirmSegment->execute(
                $this->processingLog->processing_id,
                $segmentId,
                $user,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->processingLog->refresh();
        $this->success('Sermon segment confirmed. Processing will resume shortly.');
    }

    public function render(VideoStorageService $videoStorageService): View
    {
        $this->processingLog->loadMissing('segments');

        $sourcePath = $this->processingLog->source_file_path;
        $sourceAvailable = is_string($sourcePath)
            && $sourcePath !== ''
            && $videoStorageService->sourceVideoExistsForPath($sourcePath);

        return view('livewire.admin.sermon-segment-review', [
            'segments' => $this->processingLog->segments->sortBy('start_time')->values(),
            'confirmedSegmentId' => $this->processingLog->manuallyConfirmedSegmentId(),
            'requiresReview' => $this->processingLog->requiresManualSermonReview(),
            'sourceAvailable' => $sourceAvailable,
        ])->layout('layouts.admin', [
            'title' => 'Choose sermon segment',
            'heading' => 'Choose sermon segment',
        ]);
    }
}
