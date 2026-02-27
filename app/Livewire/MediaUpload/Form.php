<?php

declare(strict_types=1);

namespace App\Livewire\MediaUpload;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Livewire\Traits\HasConditionalLogging;
use App\Livewire\Traits\WithUploadLifecycle;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\MediaValidationService;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use HasConditionalLogging;
    use WithFileUploads;
    use WithUploadLifecycle;

    // Processing state
    public ?string $processingId = null;

    public ?string $tempFilePath = null;

    public string $status = 'idle';

    public string $currentStep = '';

    public int $progressPercentage = 0;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public ?string $cancelledMessage = null;

    public bool $showProcessingStatus = false;

    private UnifiedMediaProcessor $processor;

    protected MediaValidationService $validation;

    public function boot(UnifiedMediaProcessor $processor, MediaValidationService $validation): void
    {
        $this->processor = $processor;
        $this->validation = $validation;
    }

    public function mount(): void
    {
        $this->logInfo('MediaUpload: Component mounting', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toDateTimeString(),
        ]);

        if (! Gate::allows('create', Sermon::class)) {
            $this->logError('MediaUpload: Unauthorized access attempt', [
                'user_id' => Auth::id(),
            ]);
            abort(403, 'Unauthorized to upload sermons.');
        }

        $this->logInfo('MediaUpload: Component mounted successfully', [
            'user_id' => Auth::id(),
        ]);
    }

    public function startProcessing(): void
    {
        $this->logInfo('startProcessing method called', [
            'processing_id' => $this->processingId,
            'temp_file_path' => $this->tempFilePath,
            'media_type' => $this->mediaType,
        ]);

        if (! $this->tempFilePath) {
            $this->logError('Missing processing data', [
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

            $this->logInfo('Processing with file date', [
                'file_modified_date' => $this->fileModifiedDate,
                'original_filename' => $this->originalFileName,
            ]);

            $processor = $this->getProcessor();
            $result = $processor->process($this->mediaType, $originalFile, $this->fileModifiedDate);

            if ($result->success) {
                $this->processingId = $result->processingId;
                $this->status = ProcessingStatus::PROCESSING->value;
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

            $this->logInfo('Media processing initiated', [
                'processing_id' => $this->processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
                'success' => $result->success,
            ]);

        } catch (\Exception $e) {
            $this->logError('Media processing failed', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            if ($this->processingId) {
                $log = MediaProcessingLog::where('processing_id', $this->processingId)->first();
                $log?->markAsFailed('Processing failed: '.$e->getMessage());
            }

            $this->status = 'failed';
            $this->errorMessage = 'Processing failed: '.$e->getMessage();
            $this->successMessage = null;
            $this->cancelledMessage = null;
        } finally {
            if (file_exists($fullTempPath)) {
                try {
                    unlink($fullTempPath);
                    $this->logInfo('Cleaned up Livewire temp file', ['path' => $fullTempPath]);
                } catch (\Exception $e) {
                    $this->logError('Failed to clean up Livewire temp file', [
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

    #[On('media-upload:cancel-processing')]
    public function handleCancelProcessingRequest(): void
    {
        $this->cancelProcessing();
    }

    #[On('media-upload:retry-upload')]
    public function handleRetryUploadRequest(): void
    {
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
            $this->logError('Failed to cancel processing', [
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

            if ($nextStatus === 'failed') {
                $this->errorMessage = $log->error_message ?? 'Processing failed';
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
                $this->logDebug('Processing status updated', [
                    'processing_id' => $this->processingId,
                    'status' => $nextStatus,
                    'progress' => $nextProgress,
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to check processing status', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleProcessingError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->successMessage = null;
        $this->cancelledMessage = null;
        $this->currentStep = 'Processing failed';
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
        ]);
    }
}
