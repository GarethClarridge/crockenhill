<?php

declare(strict_types=1);

namespace App\Services\Strategies;

use App\Contracts\AudioExtractionServiceInterface;
use App\Contracts\ProcessingStrategyInterface;
use App\Services\ProcessingResult;
use App\Services\SermonProcessingService;
use App\Services\VideoProcessingService;
use Illuminate\Http\UploadedFile;

/**
 * DirectVideoProcessingStrategy - Strategy for direct video processing
 *
 * Handles sermon video uploads that don't require segmentation.
 * Will be refactored to use job chains instead of synchronous processing in Phase 3.
 */
class DirectVideoProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private VideoProcessingService $videoProcessor,
        private AudioExtractionServiceInterface $audioExtractor,
        private SermonProcessingService $sermonProcessor
    ) {}

    /**
     * Check if this strategy supports the given processing type
     */
    public function supports(string $type): bool
    {
        return $type === 'sermon_video';
    }

    /**
     * Process the uploaded video file using async job chains
     */
    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        // Use async job chains for consistency with other processing types
        return $this->dispatchVideoProcessingJobs($file, $options);
    }

    /**
     * Validate video file for processing
     */
    public function validateFile(UploadedFile $file): array
    {
        $config = $this->getConfiguration();
        $errors = [];

        // Check file size
        if ($file->getSize() > $config['max_file_size']) {
            $maxSizeMB = round($config['max_file_size'] / (1024 * 1024));
            $errors[] = "File size exceeds maximum limit of {$maxSizeMB}MB for sermon video processing";
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $config['allowed_extensions'])) {
            $allowed = implode(', ', $config['allowed_extensions']);
            $errors[] = "File extension '{$extension}' not allowed for sermon video. Allowed: {$allowed}";
        }

        // Check file validity
        if (!$file->isValid()) {
            $errors[] = 'Uploaded video file is corrupted or invalid';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get configuration for direct video processing
     */
    public function getConfiguration(): array
    {
        return [
            'type' => 'sermon_video',
            'description' => 'Direct sermon video file',
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'max_file_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
            'queue' => 'video-processing',
        ];
    }

    /**
     * Dispatch video processing jobs asynchronously
     */
    private function dispatchVideoProcessingJobs(UploadedFile $file, array $options = []): ProcessingResult
    {
        try {
            // Generate unique processing ID
            $processingId = \Illuminate\Support\Str::uuid()->toString();

            // Store video file temporarily
            $tempPath = $file->store('temp/video-processing');

            // Create processing log
            $processingLog = \App\Models\SermonProcessingLog::create([
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'stored_file_path' => $tempPath,
                'status' => \App\Enums\ProcessingStatus::PENDING,
                'current_step' => 'video_processing_initiated',
            ]);

            // Get pipeline builder and create job chain
            $pipelineBuilder = app(\App\Services\ProcessingPipelineBuilder::class);
            $jobs = $pipelineBuilder->buildDirectVideoPipeline($processingLog);

            // Dispatch job chain
            \Illuminate\Support\Facades\Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    \Illuminate\Support\Facades\Log::error('Video processing job chain failed', [
                        'processing_id' => $processingLog->processing_id,
                        'error' => $e->getMessage(),
                    ]);

                    $processingLog->update([
                        'status' => \App\Enums\ProcessingStatus::FAILED,
                        'error_message' => 'Video processing chain failed: ' . $e->getMessage(),
                        'completed_at' => now(),
                    ]);
                })
                ->onQueue('video-processing')
                ->dispatch();

            return \App\Services\ProcessingResult::success(
                processingId: $processingId,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.sermons.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            return \App\Services\ProcessingResult::failure(
                processingId: 'failed-' . \Illuminate\Support\Str::uuid(),
                message: 'Failed to initiate video processing: ' . $e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_INITIATION_FAILED'
            );
        }
    }
}