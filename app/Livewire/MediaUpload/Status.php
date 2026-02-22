<?php

declare(strict_types=1);

namespace App\Livewire\MediaUpload;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class Status extends Component
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

    public function requestCancelProcessing(): void
    {
        $this->dispatch('media-upload:cancel-processing');
    }

    public function requestRetryUpload(): void
    {
        $this->dispatch('media-upload:retry-upload');
    }

    public function render(): View
    {
        return view('livewire.media-upload.status');
    }
}
