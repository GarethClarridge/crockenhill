<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingRouter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    // Processing state
    public ?string $processingId = null;

    public ?string $tempFilePath = null;

    public string $status = 'idle'; // idle, uploading, processing, completed, failed

    public string $currentStep = '';

    public int $progressPercentage = 0;

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
        // Validation will happen in uploadMedia() where we have the actual file, not Livewire's broken temp reference
        if (!$this->mediaFile) {
            return;
        }

        $this->logInfo('MediaUpload: File uploaded', [
            'media_type' => $this->mediaType,
            'file_exists' => true,
            'file_name' => $this->mediaFile->getClientOriginalName(),
        ]);
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
        $this->validate($this->getDynamicRules(), $this->getDynamicMessages());

        try {
            // Hide upload form and show processing status section immediately
            $this->showUploadForm = false;
            $this->showProcessingStatus = true;
            $this->status = 'processing';
            $this->currentStep = 'Preparing for processing...';
            $this->progressPercentage = 5;
            $this->errorMessage = null;

            // Create a processing record first to get the ID for polling
            $processingId = Str::uuid()->toString();
            $log = MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => $this->mediaType,
                'status' => ProcessingStatus::PENDING,
                'original_filename' => $this->mediaFile->getClientOriginalName(),
                'current_step' => 'preparing',
            ]);

            $this->processingId = $processingId;

            // Store file data for processing
            $tempFilePath = $this->mediaFile->store('temp/livewire-upload', 'local');
            $this->tempFilePath = $tempFilePath;

            // Call processing directly - simple and reliable
            $this->startProcessing();

            $this->logInfo('Media upload prepared for processing', [
                'processing_id' => $processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

        } catch (\Exception $e) {
            $this->logError('Media upload preparation failed', [
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred during upload: '.$e->getMessage());
        }
    }

    public function startProcessing(): void
    {
        $this->logInfo('startProcessing method called', [
            'processing_id' => $this->processingId,
            'temp_file_path' => $this->tempFilePath,
            'media_type' => $this->mediaType,
        ]);

        if (! $this->processingId || ! $this->tempFilePath) {
            $this->logError('Missing processing data', [
                'processing_id' => $this->processingId,
                'temp_file_path' => $this->tempFilePath,
            ]);
            $this->handleUploadError('Missing processing data');

            return;
        }

        try {
            // Reconstruct the uploaded file from stored temp file
            $fullTempPath = storage_path('app/'.$this->tempFilePath);
            $originalFile = new \Illuminate\Http\UploadedFile(
                $fullTempPath,
                $this->mediaFile->getClientOriginalName(),
                $this->mediaFile->getMimeType(),
                null,
                true // Mark as test to avoid validation errors
            );

            // Get the log record
            $log = MediaProcessingLog::where('processing_id', $this->processingId)->first();

            // Start the actual processing
            $processor = app(\App\Services\UnifiedMediaProcessor::class);

            $result = $processor->process($this->mediaType, $originalFile);

            // Clean up temp file
            if (file_exists($fullTempPath)) {
                unlink($fullTempPath);
            }

            // Update UI to show completion
            if ($result->success) {
                $this->status = 'completed';
                $this->currentStep = 'Processing completed!';
                $this->progressPercentage = 100;
                $this->successMessage = 'Your media has been processed successfully.';
            } else {
                $this->status = 'failed';
                $this->errorMessage = $result->message;
            }

            $this->logInfo('Media processing completed', [
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
        }
    }

    public function retryUpload(): void
    {
        $this->resetProcessingState();
        $this->showUploadForm = true;
        $this->showProcessingStatus = false;
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

    private function handleUploadError(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->showUploadForm = true;
        $this->showProcessingStatus = true;
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

    public function render()
    {
        return view('livewire.media-upload');
    }
}
