<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Data\VideoProcessingOptions;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\SermonService;
use App\Enums\UploadState;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Queries\ChurchServiceProcessingRunQuery;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\MediaValidationService;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class MediaUpload extends Component
{
    use WithAdminAuthorization, WithFileUploads;

    public string $mediaType = '';

    public mixed $mediaFile = null;

    public ?string $fileModifiedDate = null;

    public ?string $originalFileName = null;

    public bool $autoTrimVideo = false;

    public string $serviceOverride = '';

    #[Url(except: null)]
    public ?int $churchServiceId = null;

    public int $uploadProgress = 0;

    public ?string $processingId = null;

    public ?string $tempFilePath = null;

    public UploadState $status = UploadState::Idle;

    public string $currentStep = '';

    public int $progressPercentage = 0;

    public ?string $statusMessageOverride = null;

    /** @var array<string, string> */
    protected array $rules = [
        'mediaType' => 'required|in:audio,video,livestream',
        'mediaFile' => 'required|file',
        'serviceOverride' => 'required|in:morning,evening',
    ];

    private UnifiedMediaProcessor $processor;

    protected MediaValidationService $validation;

    protected ChurchServiceProcessingRunQuery $runQuery;

    public function boot(
        UnifiedMediaProcessor $processor,
        MediaValidationService $validation,
        ChurchServiceProcessingRunQuery $runQuery,
    ): void {
        $this->processor = $processor;
        $this->validation = $validation;
        $this->runQuery = $runQuery;
    }

    public function mount(): void
    {
        $this->applyChurchServiceContext();
    }

    public function updatedMediaType(): void
    {
        $this->authorizeAdmin();
        $this->mediaFile = null;

        if ($this->mediaType !== MediaType::Video->value) {
            $this->autoTrimVideo = false;
        }

        $this->serviceOverride = $this->contextChurchService()?->service->value
            ?? $this->defaultServiceForType($this->mediaType);
        $this->resetErrorBag('mediaFile');
    }

    public function updatedMediaFile(): void
    {
        $this->authorizeAdmin();

        if (! $this->mediaFile) {
            return;
        }

        $this->status = UploadState::Uploading;
        $this->statusMessageOverride = null;
        $this->uploadProgress = 0;
    }

    public function uploadComplete(): void
    {
        $this->authorizeAdmin();

        if ($this->status !== UploadState::Uploading && $this->status->isTerminal()) {
            return;
        }

        if ($this->processingId !== null || $this->tempFilePath !== null || $this->status === UploadState::Processing) {
            return;
        }

        if (! $this->mediaFile) {
            $this->handleUploadError('File upload completed but file is missing');

            return;
        }

        $this->uploadProgress = 100;

        try {
            $this->applyChurchServiceContext();
            $this->validate($this->getDynamicRules(), $this->getDynamicMessages());
            $this->originalFileName = $this->mediaFile->getClientOriginalName();
            $this->status = UploadState::Processing;
            $this->currentStep = 'Preparing for processing...';
            $this->progressPercentage = 5;
            $this->statusMessageOverride = null;

            $this->tempFilePath = $this->mediaFile->store('temp/livewire-upload', 'local');
            $this->startProcessing();
        } catch (ValidationException $exception) {
            $this->handleUploadError('Validation failed: '.$exception->getMessage());
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (\Throwable $exception) {
            Log::error('Media processing preparation failed', [
                'error' => $exception->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred: '.$exception->getMessage());
        }
    }

    public function uploadMedia(): void
    {
        $this->authorizeAdmin();
        $this->uploadComplete();
    }

    public function cancelUpload(): void
    {
        $this->authorizeAdmin();

        if ($this->processingId !== null || $this->tempFilePath !== null || $this->status === UploadState::Processing) {
            return;
        }

        $this->status = UploadState::Idle;
        $this->uploadProgress = 0;
        $this->mediaFile = null;
        $this->statusMessageOverride = null;
    }

    public function updateUploadProgress(int $progress): void
    {
        $this->authorizeAdmin();

        if ($this->status !== UploadState::Uploading) {
            return;
        }

        $this->uploadProgress = max(0, min(100, $progress));
    }

    public function startProcessing(): void
    {
        $this->authorizeAdmin();

        if (! $this->tempFilePath) {
            $this->handleUploadError('Missing processing data');

            return;
        }

        $fullTempPath = Storage::disk('local')->path($this->tempFilePath);

        try {
            $originalFile = new UploadedFile(
                $fullTempPath,
                $this->originalFileName ?? basename($fullTempPath),
                mime_content_type($fullTempPath) ?: 'application/octet-stream',
                null,
                true,
            );

            $churchService = $this->contextChurchService();

            $result = $churchService instanceof ChurchService
                ? $this->processor->process(
                    $this->mediaType,
                    $originalFile,
                    $this->fileModifiedDate,
                    $this->processingOptions(),
                    $churchService->service,
                    $churchService->date->toDateString(),
                )
                : $this->processor->process(
                    $this->mediaType,
                    $originalFile,
                    $this->fileModifiedDate,
                    $this->processingOptions(),
                    SermonService::tryFrom($this->serviceOverride),
                );

            $this->processingId = $result->processingId;

            if ($result->success) {
                $this->status = UploadState::Processing;
                $this->currentStep = 'Processing started';
                $this->progressPercentage = 10;
                $this->statusMessageOverride = 'Upload complete. Processing has started.';
            } else {
                $this->status = UploadState::Failed;
                $this->statusMessageOverride = $result->message;
            }
        } catch (\Throwable $exception) {
            Log::error('Media processing failed', [
                'processing_id' => $this->processingId,
                'error' => $exception->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            if ($this->processingId !== null) {
                $log = MediaProcessingLog::query()
                    ->where('processing_id', $this->processingId)
                    ->first();

                if ($log instanceof MediaProcessingLog) {
                    app(MediaProcessingRunTransitionService::class)
                        ->markAsFailed($log, 'Processing failed: '.$exception->getMessage());
                }
            }

            $this->status = UploadState::Failed;
            $this->statusMessageOverride = 'Processing failed: '.$exception->getMessage();
        } finally {
            if (file_exists($fullTempPath)) {
                try {
                    unlink($fullTempPath);
                } catch (\Throwable $exception) {
                    Log::error('Failed to clean up Livewire temp file', [
                        'path' => $fullTempPath,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    public function retryUpload(): void
    {
        $this->authorizeAdmin();
        $this->resetProcessingState();
        $this->resetUploadState();
    }

    public function cancelProcessing(): void
    {
        $this->authorizeAdmin();

        if (! $this->processingId) {
            return;
        }

        try {
            $result = $this->processor->cancel($this->processingId);

            if ($result['success']) {
                $this->status = UploadState::Cancelled;
                $this->currentStep = 'Processing cancelled';
                $this->progressPercentage = 0;
                $this->statusMessageOverride = 'Processing was cancelled by user.';
            } else {
                $this->handleProcessingError($result['message']);
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to cancel processing', [
                'processing_id' => $this->processingId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function checkProcessingStatus(): void
    {
        $this->authorizeAdmin();

        if (! $this->processingId || $this->status->isTerminal()) {
            return;
        }

        try {
            $log = $this->findAccessibleProcessingLog($this->processingId);

            if (! $log) {
                return;
            }

            if ($log->status->isFailed() && $log->current_step === 'manual_review_required') {
                $this->status = UploadState::ManualReview;
                $this->currentStep = 'Manual review required';
                $this->progressPercentage = 100;
                $this->statusMessageOverride = null;

                return;
            }

            $this->status = UploadState::fromProcessingStatus($log->status);
            $this->currentStep = $log->current_step ?? $this->currentStep;
            $this->progressPercentage = $this->progressForLog($log);
            $this->statusMessageOverride = null;

            if ($this->status === UploadState::Failed) {
                $this->currentStep = 'Processing failed';
            } elseif ($this->status === UploadState::Cancelled) {
                $this->currentStep = 'Processing cancelled';
                $this->progressPercentage = 0;
            } elseif ($this->status === UploadState::Completed) {
                $this->currentStep = 'Processing completed!';
                $this->progressPercentage = 100;
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to check processing status', [
                'processing_id' => $this->processingId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function handleUploadError(string $message): void
    {
        $this->authorizeAdmin();
        $this->status = UploadState::Failed;
        $this->statusMessageOverride = $message;
        $this->resetUploadState();
    }

    public function getStatusMessageProperty(): ?string
    {
        if ($this->statusMessageOverride !== null) {
            return $this->statusMessageOverride;
        }

        if ($this->status === UploadState::Failed && $this->processingId !== null) {
            $log = $this->findAccessibleProcessingLog($this->processingId);

            return $log instanceof MediaProcessingLog
                ? ($log->error_message ?? 'Processing failed')
                : 'Processing failed';
        }

        return match ($this->status) {
            UploadState::Idle => null,
            UploadState::Uploading => 'Uploading…',
            UploadState::Processing => 'Processing is still running.',
            UploadState::Completed => 'Processing completed successfully!',
            UploadState::Failed => 'Processing failed',
            UploadState::Cancelled => 'Processing was cancelled.',
            UploadState::ManualReview => 'The sermon candidate could not be identified automatically. Please review the segments and confirm which one is the sermon.',
        };
    }

    public function getStatusUrlProperty(): ?string
    {
        if ($this->processingId === null || ! in_array($this->status, [UploadState::Completed, UploadState::ManualReview], true)) {
            return null;
        }

        $log = $this->findAccessibleProcessingLog($this->processingId);

        if ($log === null) {
            return null;
        }

        if ($this->status === UploadState::ManualReview) {
            return route('admin.recordings.sermon-segment', $log->processing_id);
        }

        return $this->runQuery->matchedServiceUrl($log);
    }

    private function defaultServiceForType(string $mediaType): string
    {
        return $mediaType === MediaType::Livestream->value
            ? SermonService::Morning->value
            : SermonService::Evening->value;
    }

    private function applyChurchServiceContext(): void
    {
        $churchService = $this->contextChurchService();

        if ($churchService instanceof ChurchService) {
            $this->serviceOverride = $churchService->service->value;
        }
    }

    private function contextChurchService(): ?ChurchService
    {
        if ($this->churchServiceId === null) {
            return null;
        }

        return ChurchService::query()->findOrFail($this->churchServiceId);
    }

    private function handleProcessingError(string $message): void
    {
        $this->status = UploadState::Failed;
        $this->statusMessageOverride = $message;
        $this->currentStep = 'Processing failed';
    }

    private function resetUploadState(): void
    {
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
        $this->status = UploadState::Idle;
        $this->currentStep = '';
        $this->progressPercentage = 0;
        $this->statusMessageOverride = null;
    }

    /** @return array<string, string> */
    protected function getDynamicRules(): array
    {
        $mediaType = MediaType::tryFrom($this->mediaType);
        $rules = $this->rules;
        $rules['mediaFile'] = $mediaType !== null
            ? $this->validation->rulesForType($mediaType)['file']
            : 'required|file';

        return $rules;
    }

    /** @return array<string, string> */
    protected function getDynamicMessages(): array
    {
        $mediaType = MediaType::tryFrom($this->mediaType);
        $maxSize = $mediaType !== null ? $this->validation->maxFileSizeForDisplay($mediaType) : '100MB';
        $extensions = $mediaType !== null
            ? $this->validation->allowedExtensionsForDisplay($mediaType)
            : 'MP3, WAV, M4A, MP4, MOV, AVI, MKV';

        return [
            'mediaType.required' => 'Please select a media type.',
            'mediaType.in' => 'Invalid media type selected.',
            'serviceOverride.required' => 'Please select which service this recording is for.',
            'serviceOverride.in' => 'Invalid service selected.',
            'mediaFile.required' => 'Please select a file to upload.',
            'mediaFile.file' => 'The uploaded item must be a file.',
            'mediaFile.mimes' => "Invalid file type. Supported formats: {$extensions}.",
            'mediaFile.max' => "File size cannot exceed {$maxSize}.",
        ];
    }

    /** @return array<string, mixed> */
    private function processingOptions(): array
    {
        return VideoProcessingOptions::forMediaType($this->mediaType, $this->autoTrimVideo);
    }

    private function findAccessibleProcessingLog(string $processingId): ?MediaProcessingLog
    {
        return $this->processingLogQuery()->where('processing_id', $processingId)->first();
    }

    /** @return Builder<MediaProcessingLog> */
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
            'contextChurchService' => $this->contextChurchService(),
        ])->layout('layouts.admin', [
            'title' => 'Upload recording',
            'heading' => 'Upload recording',
        ]);
    }
}
