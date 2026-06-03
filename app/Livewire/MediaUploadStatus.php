<?php

declare(strict_types=1);

namespace App\Livewire;

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
        return view('livewire.media-upload.status');
    }
}
