<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Data\VideoProcessingOptions;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\MediaValidationService;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Singleton-only by contract.
 *
 * This component is only ever mounted once per page (the admin sermon upload
 * screen). The `media-upload:*` browser events that this component listens to
 * are intentionally page-global by name, with a payload `id` that names the
 * intended target component. The `id` filter on each `#[On(...)]` handler is
 * defense-in-depth in case the assumption is violated.
 *
 * Do not mount more than one instance of this component on the same page
 * without first encoding the instance scope into the event names themselves
 * (e.g. `media-upload:{componentId}:cancel-upload`). See the April 2026
 * frontend-interactions review for the trade-off.
 */
class MediaUpload extends Component
{
    use WithFileUploads;

    // Upload form state
    public string $mediaType = '';

    public mixed $mediaFile = null;

    public ?string $fileModifiedDate = null;

    public ?string $originalFileName = null;

    public bool $autoTrimVideo = false;

    // Upload progress tracking
    public int $uploadProgress = 0;

    public bool $isUploading = false;

    public bool $uploadCancelled = false;

    // UI state
    public bool $showUploadForm = true;

    // Processing state
    public ?string $processingId = null;

    public ?string $tempFilePath = null;

    public string $status = 'idle';

    public string $currentStep = '';

    public int $progressPercentage = 0;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public ?string $cancelledMessage = null;

    public ?string $manualReviewMessage = null;

    public ?string $manualReviewUrl = null;

    public bool $showProcessingStatus = false;

    /** @var array<string, string> */
    protected array $rules = [
        'mediaType' => 'required|in:audio,video,livestream',
        'mediaFile' => 'required|file',
    ];

    private UnifiedMediaProcessor $processor;

    protected MediaValidationService $validation;

    public function boot(UnifiedMediaProcessor $processor, MediaValidationService $validation): void
    {
        $this->processor = $processor;
        $this->validation = $validation;
    }

    public function mount(): void
    {
        Log::info('MediaUpload: Component mounting', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toDateTimeString(),
        ]);

        if (! Gate::allows('create', Sermon::class)) {
            Log::error('MediaUpload: Unauthorized access attempt', [
                'user_id' => Auth::id(),
            ]);
            abort(403, 'Unauthorized to upload sermons.');
        }

        Log::info('MediaUpload: Component mounted successfully', [
            'user_id' => Auth::id(),
        ]);
    }

    public function updatedMediaType(): void
    {
        $this->mediaFile = null;
        if ($this->mediaType !== MediaType::Video->value) {
            $this->autoTrimVideo = false;
        }
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

        Log::info('MediaUpload: File upload started', [
            'media_type' => $this->mediaType,
            'file_name' => $this->mediaFile->getClientOriginalName(),
        ]);
    }

    public function uploadComplete(): void
    {
        if ($this->uploadCancelled) {
            Log::info('Upload complete but was cancelled, ignoring');

            return;
        }

        if ($this->processingId !== null || $this->tempFilePath !== null || $this->showProcessingStatus) {
            Log::info('Upload completion already handled, ignoring duplicate trigger', [
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

        Log::info('MediaUpload: File upload completed, starting validation', [
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

            Log::info('File stored to temp directory', [
                'temp_file_path' => $tempFilePath,
                'full_path' => storage_path('app/'.$tempFilePath),
                'file_exists' => file_exists(storage_path('app/'.$tempFilePath)),
            ]);

            $this->startProcessing();

            Log::info('Media processing started', [
                'processing_id' => $this->processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

        } catch (ValidationException $e) {
            $this->handleUploadError('Validation failed: '.$e->getMessage());
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Exception $e) {
            Log::error('Media processing preparation failed', [
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred: '.$e->getMessage());
        }
    }

    public function uploadMedia(): void
    {
        Log::info('Manual upload trigger received');
        $this->uploadComplete();
    }

    public function cancelUpload(): void
    {
        if (! $this->isUploading) {
            return;
        }

        Log::info('Upload cancelled by user', [
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

    public function startProcessing(): void
    {
        Log::info('startProcessing method called', [
            'processing_id' => $this->processingId,
            'temp_file_path' => $this->tempFilePath,
            'media_type' => $this->mediaType,
        ]);

        if (! $this->tempFilePath) {
            Log::error('Missing processing data', [
                'processing_id' => $this->processingId,
                'temp_file_path' => $this->tempFilePath,
            ]);
            $this->handleUploadError('Missing processing data');

            return;
        }

        $fullTempPath = Storage::disk('local')->path($this->tempFilePath);

        try {
            $mimeType = mime_content_type($fullTempPath) ?: 'application/octet-stream';
            $originalFileName = $this->originalFileName ?? basename($fullTempPath);

            $originalFile = new UploadedFile(
                $fullTempPath,
                $originalFileName,
                $mimeType,
                null,
                true
            );

            Log::info('Processing with file date', [
                'file_modified_date' => $this->fileModifiedDate,
                'original_filename' => $this->originalFileName,
            ]);

            $processor = $this->getProcessor();
            $result = $processor->process(
                $this->mediaType,
                $originalFile,
                $this->fileModifiedDate,
                $this->processingOptions()
            );

            if ($result->success) {
                $this->processingId = $result->processingId;
                $this->status = ProcessingStatus::Processing->value;
                $this->currentStep = 'Processing started';
                $this->progressPercentage = 10;
                $this->successMessage = 'Upload complete. Processing has started.';
                $this->cancelledMessage = null;
            } else {
                $this->processingId = $result->processingId;
                $this->status = 'failed';
                $this->errorMessage = $result->message;
                $this->successMessage = null;
                $this->cancelledMessage = null;
            }

            Log::info('Media processing initiated', [
                'processing_id' => $this->processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
                'success' => $result->success,
            ]);

        } catch (\Exception $e) {
            Log::error('Media processing failed', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            if ($this->processingId) {
                $log = MediaProcessingLog::query()->where('processing_id', $this->processingId)->first();
                if ($log instanceof MediaProcessingLog) {
                    app(MediaProcessingRunTransitionService::class)
                        ->markAsFailed($log, 'Processing failed: '.$e->getMessage());
                }
            }

            $this->status = 'failed';
            $this->errorMessage = 'Processing failed: '.$e->getMessage();
            $this->successMessage = null;
            $this->cancelledMessage = null;
        } finally {
            if (file_exists($fullTempPath)) {
                try {
                    unlink($fullTempPath);
                    Log::info('Cleaned up Livewire temp file', ['path' => $fullTempPath]);
                } catch (\Exception $e) {
                    Log::error('Failed to clean up Livewire temp file', [
                        'path' => $fullTempPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function retryUpload(): void
    {
        $this->resetProcessingState();
        $this->resetUploadState();
        $this->showUploadForm = true;
        $this->showProcessingStatus = false;
    }

    #[On('media-upload:cancel-upload')]
    public function handleCancelUploadRequest(string $id): void
    {
        if ($id !== $this->getId()) {
            return;
        }

        $this->cancelUpload();
    }

    #[On('media-upload:cancel-processing')]
    public function handleCancelProcessingRequest(string $id): void
    {
        if ($id !== $this->getId()) {
            return;
        }

        $this->cancelProcessing();
    }

    #[On('media-upload:retry-upload')]
    public function handleRetryUploadRequest(string $id): void
    {
        if ($id !== $this->getId()) {
            return;
        }

        $this->retryUpload();
    }

    public function cancelProcessing(): void
    {
        if (! $this->processingId) {
            return;
        }

        try {
            $processor = $this->getProcessor();
            $result = $processor->cancel($this->processingId);

            if ($result['success']) {
                $this->status = 'cancelled';
                $this->currentStep = 'Processing cancelled';
                $this->errorMessage = null;
                $this->successMessage = null;
                $this->cancelledMessage = 'Processing was cancelled by user.';
                $this->progressPercentage = 0;
            } else {
                $this->handleProcessingError($result['message']);
            }

        } catch (\Exception $e) {
            Log::error('Failed to cancel processing', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function checkProcessingStatus(): void
    {
        if (! $this->processingId || in_array($this->status, ['completed', 'failed', 'cancelled'])) {
            return;
        }

        try {
            $log = $this->findAccessibleProcessingLog($this->processingId);
            if (! $log) {
                return;
            }

            $nextStatus = $log->status->value;
            $nextProgress = $this->progressForLog($log);

            $previousStatus = $this->status;
            $previousProgress = $this->progressPercentage;

            $this->status = $nextStatus;
            $this->currentStep = $log->current_step ?? $this->currentStep;
            $this->progressPercentage = $nextProgress;

            if ($nextStatus === 'failed' && $log->current_step === 'manual_review_required') {
                $this->manualReviewMessage = 'The sermon candidate could not be identified automatically. Please review the segments and confirm which one is the sermon.';
                $this->manualReviewUrl = route('admin.services.processing.review', $log);
                $this->errorMessage = null;
                $this->successMessage = null;
                $this->cancelledMessage = null;
                $this->currentStep = 'Manual review required';
                $this->progressPercentage = 100;
            } elseif ($nextStatus === 'failed') {
                $this->errorMessage = $log->error_message ?? 'Processing failed';
                $this->manualReviewMessage = null;
                $this->manualReviewUrl = null;
                $this->successMessage = null;
                $this->cancelledMessage = null;
                $this->currentStep = 'Processing failed';
                $this->progressPercentage = 0;
            } elseif ($nextStatus === 'cancelled') {
                $this->errorMessage = null;
                $this->successMessage = null;
                $this->cancelledMessage = 'Processing was cancelled.';
                $this->currentStep = 'Processing cancelled';
                $this->progressPercentage = 0;
            } elseif ($nextStatus === 'completed') {
                $this->errorMessage = null;
                $this->successMessage = 'Processing completed successfully!';
                $this->cancelledMessage = null;
                $this->currentStep = 'Processing completed!';
                $this->progressPercentage = 100;
            }

            if ($this->status !== $previousStatus || $this->progressPercentage !== $previousProgress) {
                Log::debug('Processing status updated', [
                    'processing_id' => $this->processingId,
                    'status' => $nextStatus,
                    'progress' => $nextProgress,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to check processing status', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function handleProcessingError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->successMessage = null;
        $this->cancelledMessage = null;
        $this->currentStep = 'Processing failed';
    }

    private function resetUploadState(): void
    {
        $this->isUploading = false;
        $this->uploadCancelled = false;
        $this->uploadProgress = 0;
        $this->mediaFile = null;

        if ($this->mediaType !== MediaType::Video->value) {
            $this->autoTrimVideo = false;
        }
    }

    private function resetProcessingState(): void
    {
        $this->processingId = null;
        $this->tempFilePath = null;
        $this->status = 'idle';
        $this->currentStep = '';
        $this->progressPercentage = 0;
        $this->errorMessage = null;
        $this->successMessage = null;
        $this->cancelledMessage = null;
        $this->manualReviewMessage = null;
        $this->manualReviewUrl = null;
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

        Log::info('MediaUpload: Dynamic rules from config', [
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

    /**
     * @return array<string, mixed>
     */
    private function processingOptions(): array
    {
        return VideoProcessingOptions::forMediaType($this->mediaType, $this->autoTrimVideo);
    }

    private function getProcessor(): UnifiedMediaProcessor
    {
        return $this->processor;
    }

    private function findAccessibleProcessingLog(string $processingId): ?MediaProcessingLog
    {
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->first();
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function processingLogQuery(): Builder
    {
        $query = MediaProcessingLog::query();
        $user = Auth::user();

        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query;
    }

    private function progressForLog(MediaProcessingLog $log): int
    {
        if ($log->isComplete()) {
            return 100;
        }

        if ($log->isFailed() || $log->isCancelled()) {
            return 0;
        }

        return ProcessingStep::progressForStep($log->current_step);
    }

    public function render(): View
    {
        $mediaType = MediaType::tryFrom($this->mediaType);

        return view('livewire.media-upload.form', [
            'maxFileSize' => $mediaType ? $this->validation->maxFileSizeForDisplay($mediaType) : null,
            'allowedExtensions' => $mediaType ? $this->validation->allowedExtensionsForDisplay($mediaType) : null,
            'maxFileSizeBytes' => $mediaType ? $this->validation->maxFileSizeBytes($mediaType) : null,
            'acceptAttribute' => $mediaType ? $this->validation->acceptAttribute($mediaType) : '',
        ])->layout('layouts.admin', [
            'title' => 'Upload recording',
            'heading' => 'Upload recording',
        ]);
    }
}
