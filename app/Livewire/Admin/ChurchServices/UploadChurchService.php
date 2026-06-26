<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class UploadChurchService extends Component
{
    use WithAdminAuthorization, WithFileUploads, WithNotifications;

    /** @var TemporaryUploadedFile|null */
    public mixed $file = null;

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $maxSize = (int) config('service-tracking.upload.max_size_kb', 614400);

        return [
            'file' => ['required', 'file', 'mimes:zip', 'max:'.$maxSize],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'file.required' => 'Please upload an OpenLP .osz file.',
            'file.file' => 'The uploaded value must be a file.',
            'file.mimes' => 'The uploaded file must be a valid OpenLP .osz archive.',
            'file.max' => 'The uploaded file exceeds the maximum configured size.',
        ];
    }

    public function save(): void
    {

        $this->authorizeAdmin();
        $this->abortIfDisabled();

        $validated = $this->validate();
        $uploadedFile = $validated['file'] ?? null;

        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a valid OpenLP .osz file.',
            ]);
        }

        try {
            $result = app(ImportChurchServiceFromOpenLp::class)->import($uploadedFile);

            $this->file = null;
            $this->success('Service imported successfully', redirectTo: route('admin.services.show', $result->churchService));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', 'Unable to import this file. Please verify it is a valid OpenLP archive.');
        }
    }

    public function render(): View
    {
        /**
         * Performance Optimization: Limits retrieved columns for recent services
         * to required fields for the sidebar listing to reduce memory usage and DB I/O.
         */
        $recentServices = ChurchService::query()
            ->select(['id', 'date', 'service', 'original_filename', 'updated_at'])
            ->withCount('items')
            ->orderByDesc('date')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return view('livewire.admin.church-services.upload-church-service', [
            'recentServices' => $recentServices,
        ])->layout('layouts.admin', ['title' => 'Upload Service', 'heading' => 'Upload Service']);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
