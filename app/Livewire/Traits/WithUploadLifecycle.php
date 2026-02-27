<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Enums\MediaType;
use App\Services\MediaValidationService;
use Illuminate\Validation\ValidationException;

/**
 * @property string $status
 * @property ?string $errorMessage
 * @property bool $showProcessingStatus
 * @property int $progressPercentage
 * @property string $currentStep
 * @property MediaValidationService $validation
 */
trait WithUploadLifecycle
{
    // Upload form state
    public string $mediaType = '';

    public mixed $mediaFile = null;

    public ?string $fileModifiedDate = null;

    public ?string $originalFileName = null;

    // Upload progress tracking
    public int $uploadProgress = 0;

    public bool $isUploading = false;

    public bool $uploadCancelled = false;

    // UI state
    public bool $showUploadForm = true;

    /** @var array<string, string> */
    protected array $rules = [
        'mediaType' => 'required|in:audio,video,livestream',
        'mediaFile' => 'required|file',
    ];

    public function updatedMediaType(): void
    {
        $this->mediaFile = null;
        $this->resetErrorBag('mediaFile');
    }

    public function updatedMediaFile(): void
    {
        if (! $this->mediaFile) {
            return;
        }

        $this->isUploading = true;
        $this->uploadCancelled = false;
        $this->status = 'uploading';
        $this->errorMessage = null;
        $this->successMessage = null;
        $this->cancelledMessage = null;
        $this->uploadProgress = 0;

        $this->logInfo('MediaUpload: File upload started', [
            'media_type' => $this->mediaType,
            'file_name' => $this->mediaFile->getClientOriginalName(),
        ]);
    }

    public function uploadComplete(): void
    {
        if ($this->uploadCancelled) {
            $this->logInfo('Upload complete but was cancelled, ignoring');

            return;
        }

        if ($this->processingId !== null || $this->tempFilePath !== null || $this->showProcessingStatus) {
            $this->logInfo('Upload completion already handled, ignoring duplicate trigger', [
                'processing_id' => $this->processingId,
                'temp_file_path' => $this->tempFilePath,
            ]);

            return;
        }

        if (! $this->mediaFile) {
            $this->handleUploadError('File upload completed but file is missing');

            return;
        }

        $this->isUploading = false;
        $this->uploadProgress = 100;

        $this->logInfo('MediaUpload: File upload completed, starting validation', [
            'file_name' => $this->mediaFile->getClientOriginalName(),
        ]);

        try {
            $this->validate($this->getDynamicRules(), $this->getDynamicMessages());

            $this->originalFileName = $this->mediaFile->getClientOriginalName();

            $this->showUploadForm = false;
            $this->showProcessingStatus = true;
            $this->status = 'processing';
            $this->currentStep = 'Preparing for processing...';
            $this->progressPercentage = 5;
            $this->errorMessage = null;
            $this->successMessage = null;
            $this->cancelledMessage = null;

            $tempFilePath = $this->mediaFile->store('temp/livewire-upload', 'local');
            $this->tempFilePath = $tempFilePath;

            $this->logInfo('File stored to temp directory', [
                'temp_file_path' => $tempFilePath,
                'full_path' => storage_path('app/'.$tempFilePath),
                'file_exists' => file_exists(storage_path('app/'.$tempFilePath)),
            ]);

            $this->startProcessing();

            $this->logInfo('Media processing started', [
                'processing_id' => $this->processingId,
                'media_type' => $this->mediaType,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

        } catch (ValidationException $e) {
            $this->handleUploadError('Validation failed: '.$e->getMessage());
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Exception $e) {
            $this->logError('Media processing preparation failed', [
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred: '.$e->getMessage());
        }
    }

    public function uploadMedia(): void
    {
        $this->logInfo('Manual upload trigger received');
        $this->uploadComplete();
    }

    public function cancelUpload(): void
    {
        if (! $this->isUploading) {
            return;
        }

        $this->logInfo('Upload cancelled by user', [
            'file_name' => $this->originalFileName ?? 'unknown',
            'upload_progress' => $this->uploadProgress,
        ]);

        $this->uploadCancelled = true;
        $this->isUploading = false;
        $this->status = 'idle';
        $this->uploadProgress = 0;
        $this->mediaFile = null;
        $this->errorMessage = null;
    }

    public function updateUploadProgress(int $progress): void
    {
        if ($this->uploadCancelled) {
            return;
        }

        $this->uploadProgress = $progress;
    }

    public function handleUploadError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->successMessage = null;
        $this->cancelledMessage = null;
        $this->showUploadForm = true;
        $this->showProcessingStatus = true;

        $this->resetUploadState();
    }

    private function resetUploadState(): void
    {
        $this->isUploading = false;
        $this->uploadCancelled = false;
        $this->uploadProgress = 0;
        $this->mediaFile = null;
    }

    /**
     * @return array<string, string>
     */
    protected function getDynamicRules(): array
    {
        $mediaType = MediaType::tryFrom($this->mediaType);
        $fileRules = $mediaType !== null
            ? $this->validation->rulesForType($mediaType)
            : ['file' => 'required|file'];

        $rules = $this->rules;
        $rules['mediaFile'] = $fileRules['file'];

        $this->logInfo('MediaUpload: Dynamic rules from config', [
            'media_type' => $this->mediaType,
            'rules' => $rules['mediaFile'],
        ]);

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function getDynamicMessages(): array
    {
        $mediaType = MediaType::tryFrom($this->mediaType);
        $maxSize = $mediaType !== null
            ? $this->validation->maxFileSizeForDisplay($mediaType)
            : '100MB';
        $extensions = $mediaType !== null
            ? $this->validation->allowedExtensionsForDisplay($mediaType)
            : 'MP3, WAV, M4A, MP4, MOV, AVI, MKV';

        return [
            'mediaType.required' => 'Please select a media type.',
            'mediaType.in' => 'Invalid media type selected.',
            'mediaFile.required' => 'Please select a file to upload.',
            'mediaFile.file' => 'The uploaded item must be a file.',
            'mediaFile.mimes' => "Invalid file type. Supported formats: {$extensions}.",
            'mediaFile.max' => "File size cannot exceed {$maxSize}.",
        ];
    }
}
