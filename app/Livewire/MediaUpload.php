<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingLog;
use App\Services\MediaProcessingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class MediaUpload extends Component
{
    use WithFileUploads;

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
        'mediaFile' => 'required|file|mimes:mp3,wav,m4a,mp4,mov,avi,mkv',
    ];

    protected function getDynamicMessages(): array
    {
        $maxSizeMB = match ($this->mediaType) {
            'audio' => config('sermon-processing.processing.max_file_size', 100 * 1024 * 1024) / (1024 * 1024),
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
        // Ensure user has permission to upload sermons
        if (! Gate::allows('create', \App\Models\Sermon::class)) {
            abort(403, 'Unauthorized to upload sermons.');
        }
    }

    public function updatedMediaType(): void
    {
        // Clear previous file selection when media type changes
        $this->mediaFile = null;
        $this->resetErrorBag('mediaFile');
    }

    public function updatedMediaFile(): void
    {
        Log::info('MediaUpload: File uploaded', [
            'media_type' => $this->mediaType,
            'file_exists' => $this->mediaFile !== null,
            'file_size' => $this->mediaFile ? $this->mediaFile->getSize() : null,
            'file_name' => $this->mediaFile ? $this->mediaFile->getClientOriginalName() : null,
            'mime_type' => $this->mediaFile ? $this->mediaFile->getMimeType() : null,
        ]);

        try {
            // Get dynamic rules for debugging
            $rules = $this->getDynamicRules();
            $messages = $this->getDynamicMessages();
            
            Log::info('MediaUpload: Validation rules', [
                'rules' => $rules,
                'messages' => $messages,
            ]);

            // Validate file immediately when uploaded with dynamic rules
            $this->validateOnly('mediaFile', $rules, $messages);
            
            Log::info('MediaUpload: File validation passed');
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('MediaUpload: Validation failed', [
                'errors' => $e->errors(),
                'file_size' => $this->mediaFile ? $this->mediaFile->getSize() : null,
                'file_name' => $this->mediaFile ? $this->mediaFile->getClientOriginalName() : null,
                'rules' => $this->getDynamicRules(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('MediaUpload: Unexpected error during validation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function getDynamicRules(): array
    {
        $rules = $this->rules;

        // Set file size limits based on media type
        $maxSizeKB = match ($this->mediaType) {
            'audio' => (config('sermon-processing.processing.max_file_size', 100 * 1024 * 1024) / 1024), // 100MB default
            'video', 'livestream' => 5 * 1024 * 1024, // 5GB for video files (5GB in KB)
            default => 100 * 1024 // 100MB default
        };

        Log::info('MediaUpload: Dynamic rules calculation', [
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
            $log = SermonProcessingLog::create([
                'processing_id' => $processingId,
                'source_type' => $this->mediaType,
                'status' => ProcessingStatus::PENDING,
                'original_filename' => $this->mediaFile->getClientOriginalName(),
                'current_step' => 'preparing',
                'source_metadata' => [
                    'file_size' => $this->mediaFile->getSize(),
                    'mime_type' => $this->mediaFile->getMimeType(),
                ],
            ]);

            $this->processingId = $processingId;

            // Store file data for processing
            $tempFilePath = $this->mediaFile->store('temp/livewire-upload', 'local');
            $this->tempFilePath = $tempFilePath;

            // Call processing directly - simple and reliable
            $this->startProcessing();

            Log::info('Media upload prepared for processing', [
                'processing_id' => $processingId,
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

        } catch (\Exception $e) {
            Log::error('Media upload preparation failed', [
                'error' => $e->getMessage(),
                'media_type' => $this->mediaType,
                'user_id' => Auth::id(),
            ]);

            $this->handleUploadError('An unexpected error occurred during upload: '.$e->getMessage());
        }
    }

    public function startProcessing(): void
    {
        Log::info('startProcessing method called', [
            'processing_id' => $this->processingId,
            'temp_file_path' => $this->tempFilePath,
            'media_type' => $this->mediaType,
        ]);

        if (! $this->processingId || ! $this->tempFilePath) {
            Log::error('Missing processing data', [
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
            $log = SermonProcessingLog::where('processing_id', $this->processingId)->first();

            // Start the actual processing
            $mediaProcessingService = app(MediaProcessingService::class);

            $result = match ($this->mediaType) {
                'audio' => $mediaProcessingService->processAudio($originalFile, $log),
                'video' => $mediaProcessingService->processVideo($originalFile, $log),
                'livestream' => $mediaProcessingService->processLivestream($originalFile, $log),
                default => throw new \InvalidArgumentException('Invalid media type')
            };

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

            Log::info('Media processing completed', [
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

            /** @phpstan-ignore-next-line */
            if ($this->processingId) {
                $log = SermonProcessingLog::where('processing_id', $this->processingId)->first();
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
            // Use the controller directly instead of HTTP to avoid token issues
            $controller = app(\App\Http\Controllers\AutomatedSermonController::class);
            $result = $controller->cancelProcessing($this->processingId);

            if ($result['success']) {
                $this->status = 'cancelled';
                $this->currentStep = 'Processing cancelled';
                $this->errorMessage = 'Processing was cancelled by user.';
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
