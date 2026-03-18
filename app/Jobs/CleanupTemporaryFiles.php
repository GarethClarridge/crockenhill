<?php

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTemporaryFiles implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoStorageService $storageService): void
    {
        try {
            Log::info('Starting temporary file cleanup', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
            ]);

            // Collect all temporary file paths from the processing log
            $tempFiles = [];

            // Add source file if it's in temp directory
            if ($this->processingLog->source_file_path) {
                $tempFiles[] = $this->processingLog->source_file_path;
            }

            // Add extracted segment paths if they exist in metadata (livestream processing)
            $metadata = $this->processingLog->processing_metadata ?? [];
            if (isset($metadata['extracted_segment_path'])) {
                $tempFiles[] = $metadata['extracted_segment_path'];
            }
            if (isset($metadata['extracted_audio_path'])) {
                $tempFiles[] = $metadata['extracted_audio_path'];
            }
            if (isset($metadata['temp_video_path'])) {
                $tempFiles[] = $metadata['temp_video_path'];
            }

            // Audio processing: cleanup temp files from validation and extraction
            if ($this->processingLog->processing_type === MediaType::Audio) {
                // Audio validation temp files are already cleaned by ValidateAudioFile job
                // Audio transcription chunks are already cleaned by AudioTranscriptionService
                // No additional cleanup needed for audio processing
            }

            // Video processing: cleanup temp files from extraction
            if ($this->processingLog->processing_type === MediaType::Video) {
                // Video extraction temp files are handled by VideoExtractionService
                // Stored file might be in temp directory for processing
                if ($this->processingLog->stored_file_path && str_contains($this->processingLog->stored_file_path, 'temp/')) {
                    $tempFiles[] = $this->processingLog->stored_file_path;
                }
            }

            Log::info('Cleaning up temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'file_count' => count($tempFiles),
                'files' => $tempFiles,
            ]);

            $storageService->cleanupTemporaryFiles($tempFiles);

            // Preserve a non-fatal notification failure signal while still
            // marking the run as completed after cleanup.
            $this->processingLog->markAsCompleted(
                step: $this->completionStep(),
                errorMessage: $this->completionErrorMessage()
            );

            Log::info('Temporary file cleanup completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup some temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Still mark as complete even if cleanup had issues.
            $this->processingLog->markAsCompleted(
                step: $this->completionStep(),
                errorMessage: $this->completionErrorMessage()
            );
        }
    }

    private function completionStep(): string
    {
        $step = $this->processingLog->current_step;

        if (in_array($step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $step;
        }

        return 'completed';
    }

    private function completionErrorMessage(): ?string
    {
        if (in_array($this->processingLog->current_step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $this->processingLog->error_message;
        }

        return null;
    }
}
