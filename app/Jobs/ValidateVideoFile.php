<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\MediaValidationService;
use App\Traits\ChecksCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ValidateVideoFile - Job to validate video files for processing
 *
 * Part of the new job chain pattern for direct video processing pipeline.
 * Ensures video files meet requirements before processing begins.
 */
class ValidateVideoFile implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        MediaValidationService $mediaValidation,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);

        if ($this->abortIfCancelled('ValidateVideoFile')) {
            return;
        }

        Log::info('Validating video file', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        try {
            $storedFilePath = $this->processingLog->stored_file_path;

            if (! $storedFilePath) {
                throw new \Exception('No stored file path found in processing log');
            }

            // Convert relative storage path to absolute filesystem path
            $disk = config('filesystems.default', 'local');
            $filePath = Storage::disk($disk)->path($storedFilePath);

            Log::info('ValidateVideoFile path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'resolved_absolute_path' => $filePath,
                'disk' => $disk,
            ]);

            if (! file_exists($filePath)) {
                throw new \Exception("Video file not found at path: {$filePath} (relative: {$storedFilePath})");
            }

            $mediaValidation->validateLocalFile(MediaType::Video, $filePath);

            $this->processingLog->update([
                'current_step' => 'video_validation_complete',
                'status' => 'processing',
            ]);

            Log::info('Video file validation completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Video file validation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'Video validation failed: '.$e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($this->processingLog, 'Video validation job failed: '.$exception->getMessage());
    }
}
