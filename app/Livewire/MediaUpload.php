<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;

class MediaUpload extends Component
{
    use WithFileUploads;

    /**
     * Log helper that respects test environment
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::info($message, $context);
        }
    }

    /**
     * Log error helper that respects test environment
     */
    private function logError(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::error($message, $context);
        }
    }

    /**
     * Log debug helper that respects test environment
     */
    private function logDebug(string $message, array $context = []): void
    {
        if (! app()->runningUnitTests()) {
            Log::debug($message, $context);
        }
    }

    // Form properties
    public string $mediaType = '';

    public $mediaFile = null;

    public ?string $fileModifiedDate = null;

    // Processing state
    public ?string $processingId = null;

    public ?string $tempFilePath = null;

    public ?string $originalFileName = null;

    public string $status = 'idle'; // idle, uploading, processing, completed, failed

    public string $currentStep = '';

    public int $progressPercentage = 0;

    // Upload progress tracking
    public int $uploadProgress = 0;           // 0-100 percentage

    public ?int $uploadedBytes = null;        // Bytes uploaded so far

    public ?int $totalBytes = null;           // Total file size in bytes

    public bool $isUploading = false;         // Track if upload is in progress

    public bool $uploadCancelled = false;     // Track if user cancelled upload

    public array $processingDetails = [];

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    // UI state
    public bool $showUploadForm = true;

    public bool $showProcessingStatus = false;

    protected array $rules = [
        'mediaType' => 'required|in:audio,video,livestream',
        'mediaFile' => 'required|file|mimes:mp3,wav,m4a,mp4,mov,avi,mkv|max:5242880', // 5GB in KB
    ];

    protected function getDynamicMessages(): array
    {
        $maxSizeMB = match ($this->mediaType) {
            'audio' => config('media-processing.processing.max_file_size', 100 * 1024 * 1024) / (1024 * 1024),
            'video', 'livestream' => 2048, // 2GB in MB
            default => 100
        };

        return [
            'mediaType.required' => 'Please select a media type.',
            'mediaType.in' => 'Invalid media type selected.',
            'mediaFile.required' => 'Please select a file to upload.',
            'mediaFile.file' => 'The uploaded item must be a file.',
            'mediaFile.mimes' => 'Invalid file type. Supported formats: MP3, WAV, M4A, MP4, MOV, AVI, MKV.',
            'mediaFile.max' => "File size cannot exceed {$maxSizeMB}MB.",
        ];
    }

    public function mount(): void
    {
        $this->logInfo('MediaUpload: Component mounting', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Ensure user has permission to upload sermons
        if (! Gate::allows('create', \App\Models\Sermon::class)) {
            $this->logError('MediaUpload: Unauthorized access attempt', [
                'user_id' => Auth::id(),
            ]);
            abort(403, 'Unauthorized to upload sermons.');
        }

        $this->logInfo('MediaUpload: Component mounted successfully', [
            'user_id' => Auth::id(),
        ]);
    }

    public function updatedMediaType(): void
    {
        // Clear previous file selection when media type changes
        $this->mediaFile = null;
        $this->resetErrorBag('mediaFile');
    }

    public function updatedMediaFile(): void
    {
        // Just log that file was selected - don't validate yet
        // Validation will happen in uploadComplete() where we have the actual file
        if (! $this->mediaFile) {
            return;
        }

        // Set upload state - the actual upload will be tracked by JavaScript
        $this->isUploading = true;
        $this->uploadCancelled = false;
        $this->status = 'uploading';
        $this->errorMessage = null;
        $this->uploadProgress = 0;
        $this->uploadedBytes = null;
        $this->totalBytes = null;

        $this->logInfo('MediaUpload: File upload started', [
            'media_type' => $this->mediaType,
            'file_name' => $this->mediaFile->getClientOriginalName(),
        ]);
    }

    public function uploadComplete(): void
    {
        // This is called by JavaScript after livewire-upload-finish event

        if ($this->uploadCancelled) {
            $this->logInfo('Upload complete but was cancelled, ignoring');

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

        // Now validate and start processing
        try {
            $this->validate($this->getDynamicRules(), $this->getDynamicMessages());

            // Save original filename before we lose access to the file object
            $this->originalFileName = $this->mediaFile->getClientOriginalName();

            // Hide upload form and show processing status section
            $this->showUploadForm = false;
            $this->showProcessingStatus = true;
            $this->status = 'processing';
            $this->currentStep = 'Preparing for processing...';
            $this->progressPercentage = 5;
            $this->errorMessage = null;

            // Store file data for processing
            $tempFilePath = $this->mediaFile->store('temp/livewire-upload', 'local');
            $this->tempFilePath = $tempFilePath;

            $this->logInfo('File stored to temp directory', [
                'temp_file_path' => $tempFilePath,
                'full_path' => storage_path('app/'.$tempFilePath),
                'file_exists' => file_exists(storage_path('app/'.$tempFilePath)),
            ]);

            // Call processing directly
            $this->startProcessing();

            $this->logInfo('Media processing started', [
                'processing_id' => $this->processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->handleUploadError('Validation failed: '.$e->getMessage());
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Exception $e) {
            $this->logError('Media processing preparation failed', [
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred: '.$e->getMessage());
        }
    }

    protected function getDynamicRules(): array
    {
        $rules = $this->rules;

        // Set file size limits based on media type
        $maxSizeKB = match ($this->mediaType) {
            'audio' => (config('media-processing.processing.max_file_size', 100 * 1024 * 1024) / 1024), // 100MB default
            'video', 'livestream' => 5 * 1024 * 1024, // 5GB for video files (5GB in KB)
            default => 100 * 1024 // 100MB default
        };

        $this->logInfo('MediaUpload: Dynamic rules calculation', [
            'media_type' => $this->mediaType,
            'max_size_kb' => $maxSizeKB,
            'max_size_mb' => $maxSizeKB / 1024,
            'max_size_gb' => $maxSizeKB / 1024 / 1024,
        ]);

        $rules['mediaFile'] .= '|max:'.(int) $maxSizeKB;

        return $rules;
    }

    public function uploadMedia(): void
    {
        // Fallback for manual upload button (JavaScript-disabled browsers)
        $this->logInfo('Manual upload triggered (JavaScript fallback)');
        $this->uploadComplete();
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

        $fullTempPath = \Illuminate\Support\Facades\Storage::disk('local')->path($this->tempFilePath);

        try {
            // Reconstruct the uploaded file from stored temp file

            // Detect mime type from file extension since we can't rely on Livewire's temp file
            $extension = pathinfo($this->originalFileName, PATHINFO_EXTENSION);
            $mimeType = match (strtolower($extension)) {
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'm4a' => 'audio/mp4',
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'avi' => 'video/x-msvideo',
                'mkv' => 'video/x-matroska',
                default => 'application/octet-stream',
            };

            $originalFile = new \Illuminate\Http\UploadedFile(
                $fullTempPath,
                $this->originalFileName,
                $mimeType,
                null,
                true // Mark as test to avoid validation errors
            );

            $this->logInfo('Processing with file date', [
                'file_modified_date' => $this->fileModifiedDate,
                'original_filename' => $this->originalFileName,
            ]);

            // Start the actual processing
            $processor = app(\App\Services\UnifiedMediaProcessor::class);

            $result = $processor->process($this->mediaType, $originalFile, $this->fileModifiedDate);

            // Update UI to show completion
            if ($result->success) {
                $this->processingId = $result->processingId;
                $this->status = ProcessingStatus::PROCESSING->value;
                $this->currentStep = 'Processing started';
                $this->progressPercentage = 10;
                $this->successMessage = 'Upload complete. Processing has started.';
            } else {
                $this->processingId = $result->processingId;
                $this->status = 'failed';
                $this->errorMessage = $result->message;
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
        } finally {
            // Always clean up the Livewire temp file, regardless of success or failure
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

    public function cancelUpload(): void
    {
        if (! $this->isUploading) {
            return;
        }

        $this->logInfo('Upload cancelled by user', [
            'file_name' => $this->originalFileName ?? 'unknown',
            'upload_progress' => $this->uploadProgress,
        ]);

        // Set cancellation flag to prevent uploadComplete() from executing
        $this->uploadCancelled = true;
        $this->isUploading = false;

        // Reset to idle state
        $this->status = 'idle';
        $this->uploadProgress = 0;
        $this->uploadedBytes = null;
        $this->totalBytes = null;
        $this->mediaFile = null;
        $this->errorMessage = null;

        // Livewire temp file cleanup happens automatically via JavaScript
        // calling Livewire.find(componentId).cancelUpload('mediaFile')
    }

    public function updateUploadProgress(int $progress, int $loaded, int $total): void
    {
        if ($this->uploadCancelled) {
            return; // Ignore updates after cancellation
        }

        $this->uploadProgress = $progress;
        $this->uploadedBytes = $loaded;
        $this->totalBytes = $total;

        $this->logDebug('Upload progress updated', [
            'progress' => $progress,
            'loaded_mb' => round($loaded / 1024 / 1024, 2),
            'total_mb' => round($total / 1024 / 1024, 2),
        ]);
    }

    public function cancelProcessing(): void
    {
        if (! $this->processingId) {
            return;
        }

        try {
            // Use the unified media processor to cancel processing
            $processor = app(\App\Services\UnifiedMediaProcessor::class);
            $result = $processor->cancel($this->processingId);

            if ($result['success']) {
                $this->status = 'cancelled';
                $this->currentStep = 'Processing cancelled';
                $this->errorMessage = 'Processing was cancelled by user.';
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

    public function handleUploadError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->showUploadForm = true;
        $this->showProcessingStatus = true;

        // Reset upload state
        $this->resetUploadState();
    }

    private function handleProcessingError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->currentStep = 'Processing failed';
    }

    public function checkProcessingStatus(): void
    {
        if (! $this->processingId || $this->status === 'completed' || $this->status === 'failed') {
            return;
        }

        try {
            // Use the unified media processor to get status
            $processor = app(\App\Services\UnifiedMediaProcessor::class);
            $statusResponse = $processor->getStatus($this->processingId);

            if ($statusResponse->found) {
                $this->status = $statusResponse->status;
                $this->currentStep = $statusResponse->currentStep ?? $this->currentStep;
                $this->progressPercentage = $statusResponse->progressPercentage ?? $this->progressPercentage;

                if ($statusResponse->status === 'failed') {
                    $this->errorMessage = $statusResponse->errorMessage ?? 'Processing failed';
                    $this->currentStep = 'Processing failed';
                    $this->progressPercentage = 0;
                } elseif ($statusResponse->status === 'completed') {
                    $this->successMessage = 'Processing completed successfully!';
                    $this->currentStep = 'Processing completed!';
                    $this->progressPercentage = 100;
                }

                $this->logDebug('Processing status updated', [
                    'processing_id' => $this->processingId,
                    'status' => $statusResponse->status,
                    'progress' => $statusResponse->progressPercentage,
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to check processing status', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetProcessingState(): void
    {
        $this->processingId = null;
        $this->status = 'idle';
        $this->currentStep = '';
        $this->progressPercentage = 0;
        $this->processingDetails = [];
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    private function resetUploadState(): void
    {
        $this->isUploading = false;
        $this->uploadCancelled = false;
        $this->uploadProgress = 0;
        $this->uploadedBytes = null;
        $this->totalBytes = null;
        $this->mediaFile = null;
    }

    public function render()
    {
        return view('livewire.media-upload');
    }
}
