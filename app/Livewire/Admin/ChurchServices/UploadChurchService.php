<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Services\ChurchServiceItemSyncService;
use App\Services\OpenLpServiceParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class UploadChurchService extends Component
{
    use WithAdminAuthorization, WithFileUploads, WithNotifications;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public mixed $file = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
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
        $this->abortIfDisabled();

        $validated = $this->validate();
        $uploadedFile = $validated['file'] ?? null;

        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a valid OpenLP .osz file.',
            ]);
        }

        try {
            $parser = app(OpenLpServiceParser::class);
            $itemSyncService = app(ChurchServiceItemSyncService::class);

            $parsed = $parser->parse($uploadedFile);

            $churchService = ChurchService::query()->firstOrNew([
                'date' => $parsed->date,
                'service' => $parsed->service->value,
            ]);

            $churchService->fill([
                'source' => 'openlp',
                'original_filename' => $uploadedFile->getClientOriginalName(),
                'needs_review' => $parsed->needsReview,
                'import_metadata' => $parsed->importMetadata,
            ]);
            $churchService->save();

            $itemSyncService->sync($churchService, $parsed->items);

            $this->file = null;
            $this->success('Service imported successfully', redirectTo: route('admin.services.show', $churchService));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', 'Unable to import this file. Please verify it is a valid OpenLP archive.');
        }
    }

    public function render(): View
    {
        $recentServices = ChurchService::query()
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
