<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class MediaUploadStatus extends Component
{
    #[Reactive]
    public ?string $processingId = null;

    #[Reactive]
    public string $status = 'idle';

    #[Reactive]
    public string $currentStep = '';

    #[Reactive]
    public int $progressPercentage = 0;

    #[Reactive]
    public ?string $successMessage = null;

    #[Reactive]
    public ?string $errorMessage = null;

    #[Reactive]
    public ?string $cancelledMessage = null;

    #[Reactive]
    public ?string $manualReviewMessage = null;

    #[Reactive]
    public ?string $manualReviewUrl = null;

    public ?string $formComponentId = null;

    public function requestCancelProcessing(): void
    {
        $this->dispatch('media-upload:cancel-processing', id: $this->formComponentId);
    }

    public function requestRetryUpload(): void
    {
        $this->dispatch('media-upload:retry-upload', id: $this->formComponentId);
    }

    public function render(): View
    {
        return view('livewire.media-upload.status', [
            'matchedServiceUrl' => $this->matchedServiceUrl(),
        ]);
    }

    /**
     * Workbench URL for the service this run belongs to, when one matched —
     * the workbench is where processing progress and review live. Mirrors the
     * three run↔service match paths (FK, date+slot identity, item projection)
     * without hydrating blob columns.
     */
    private function matchedServiceUrl(): ?string
    {
        if ($this->status !== 'completed' || $this->processingId === null) {
            return null;
        }

        if (! (bool) config('service-tracking.enabled', true)) {
            return null;
        }

        $log = MediaProcessingLog::query()
            ->select(['id', 'processing_id', 'church_service_id', 'extracted_date', 'extracted_service'])
            ->where('processing_id', $this->processingId)
            ->first();

        if (! $log instanceof MediaProcessingLog) {
            return null;
        }

        $service = null;

        if ($log->church_service_id !== null) {
            $service = ChurchService::query()->find($log->church_service_id);
        }

        if (! $service instanceof ChurchService && $log->extracted_date !== null && $log->extracted_service !== null) {
            $service = ChurchService::query()
                ->whereDate('date', $log->extracted_date->toDateString())
                ->where('service', $log->extracted_service->value)
                ->first();
        }

        if (! $service instanceof ChurchService) {
            $service = ChurchServiceItem::query()
                ->where('livestream_processing_id', $log->processing_id)
                ->first()
                ?->churchService;
        }

        return $service instanceof ChurchService
            ? route('admin.services.show', $service)
            : null;
    }
}
