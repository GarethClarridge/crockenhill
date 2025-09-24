<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioExtractionServiceInterface;
use App\Models\SermonProcessingLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ValidateAudioFile - Job to validate audio files for processing
 *
 * Part of the new job chain pattern for audio processing pipeline.
 * Ensures audio files meet requirements before processing begins.
 */
class ValidateAudioFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private SermonProcessingLog $processingLog
    ) {}

    public function handle(AudioExtractionServiceInterface $audioExtractor): void
    {
        Log::info('Validating audio file', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        try {
            // Create temporary UploadedFile for validation
            $storedFilePath = $this->processingLog->stored_file_path;
            $originalName = $this->processingLog->original_filename;

            if (!$storedFilePath) {
                throw new \Exception('No stored file path found in processing log');
            }

            // Convert relative path to absolute path for file operations
            $sermonDisk = config('sermon-processing.storage.disk', 'public');
            $filePath = \Illuminate\Support\Facades\Storage::disk($sermonDisk)->path($storedFilePath);

            Log::info('ValidateAudioFile path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'resolved_absolute_path' => $filePath,
                'sermon_disk' => $sermonDisk,
            ]);

            // Ensure file exists before attempting to get MIME type
            if (! file_exists($filePath)) {
                throw new \Exception("Audio file not found at path: {$filePath} (relative: {$storedFilePath})");
            }

            $mimeType = mime_content_type($filePath);
            if ($mimeType === false) {
                throw new \Exception('Could not determine audio file MIME type');
            }

            $file = new UploadedFile(
                $filePath,
                $originalName,
                $mimeType,
                null,
                true
            );

            $audioExtractor->validateAudioFile($file);

            $this->processingLog->update([
                'current_step' => 'audio_validation_complete',
                'status' => 'processing',
            ]);

            Log::info('Audio file validation completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Audio file validation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => 'Audio validation failed: '.$e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->processingLog->update([
            'status' => 'failed',
            'error_message' => 'Audio validation job failed: '.$exception->getMessage(),
        ]);
    }
}
