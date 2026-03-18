<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Components;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use Exception;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\HasMedia;

class MediaUploadField extends Component
{
    use WithAdminAuthorization, WithFileUploads, WithNotifications;

    public ?HasMedia $model = null;

    public string $collection = 'default';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public mixed $file = null;

    public bool $multiple = false;

    public string $accept = 'image/*';

    public int $maxSize = 2048; // KB

    public function updatedFile(): void
    {
        $this->validate([
            'file' => "max:{$this->maxSize}|mimes:jpg,jpeg,png,webp",
        ]);
    }

    public function upload(): void
    {
        $this->authorizeAdmin();

        if (! $this->file) {
            $this->error('No file selected');

            return;
        }

        if (! $this->model) {
            $this->error('Cannot upload: model not set');

            return;
        }

        try {
            // Generate a unique filename using UUID to prevent path traversal and collisions
            $extension = $this->file->extension() ?: pathinfo($this->file->getClientOriginalName(), PATHINFO_EXTENSION);
            $safeFilename = Str::uuid().'.'.Str::lower($extension);

            $this->model
                ->addMedia($this->file->getRealPath())
                ->usingFileName($safeFilename)
                ->toMediaCollection($this->collection);

            $this->file = null;
            $this->dispatch('media-uploaded');
            $this->success('Image uploaded successfully');
        } catch (Exception $e) {
            report($e);
            $this->error('Upload failed: '.$e->getMessage());
        }
    }

    public function remove(int $mediaId): void
    {
        $this->authorizeAdmin();

        try {
            $media = $this->model?->media()->find($mediaId);

            if (! $media) {
                $this->error('Media not found');

                return;
            }

            $media->delete();
            $this->dispatch('media-removed');
            $this->success('Image removed');
        } catch (Exception $e) {
            report($e);
            $this->error('Failed to remove image: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $existingMedia = $this->model?->getMedia($this->collection) ?? collect();

        return view('livewire.admin.components.media-upload-field', [
            'existingMedia' => $existingMedia,
        ]);
    }
}
