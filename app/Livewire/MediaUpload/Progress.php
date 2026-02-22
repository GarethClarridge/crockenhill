<?php

declare(strict_types=1);

namespace App\Livewire\MediaUpload;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class Progress extends Component
{
    #[Reactive]
    public bool $isUploading = false;

    #[Reactive]
    public string $status = 'idle';

    #[Reactive]
    public int $uploadProgress = 0;

    #[Reactive]
    public ?string $currentFileName = null;

    public function render(): View
    {
        return view('livewire.media-upload.progress');
    }
}
