<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioExtractionServiceInterface;
use App\Models\MediaProcessingLog;
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
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(AudioExtractionServiceInterface $audioExtractor): void
    {
        Log::info('Validating audio file', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        $tempFilePath = null;

        try {
            // Create temporary UploadedFile for validation
            $storedFilePath = $this->processingLog->stored_file_path;
            $originalName = $this->processingLog->original_filename;

            if (! $storedFilePath) {
                throw new \Exception('No stored file path found in processing log');
            }

            // Determine storage disk and check if it's S3-compatible
            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            $isS3Disk = $this->isS3Disk($sermonDisk);

            Log::info('ValidateAudioFile path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'sermon_disk' => $sermonDisk,
                'is_s3_disk' => $isS3Disk,
            ]);

            // For S3-compatible disks, download to local temp for validation
            if ($isS3Disk) {
                // Ensure file exists on S3
                if (! \Illuminate\Support\Facades\Storage::disk($sermonDisk)->exists($storedFilePath)) {
                    throw new \Exception("Audio file not found in S3 storage: {$storedFilePath}");
                }

                // Download to local temp directory
                $tempDir = 'temp/audio-validation';
                \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory($tempDir);

                $tempFilePath = $tempDir.'/'.basename($storedFilePath);
                $localTempPath = \Illuminate\Support\Facades\Storage::disk('local')->path($tempFilePath);

                Log::info('Downloading audio from S3 for validation', [
                    'processing_id' => $this->processingLog->processing_id,
                    's3_path' => $storedFilePath,
                    'temp_path' => $localTempPath,
                ]);

                // Download file from S3
                $s3Contents = \Illuminate\Support\Facades\Storage::disk($sermonDisk)->get($storedFilePath);
                \Illuminate\Support\Facades\Storage::disk('local')->put($tempFilePath, $s3Contents);

                $filePath = $localTempPath;
            } else {
                // For local disks, use direct path
                $filePath = \Illuminate\Support\Facades\Storage::disk($sermonDisk)->path($storedFilePath);
            }

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
        } finally {
            // Clean up temporary file if it was created
            if ($tempFilePath && \Illuminate\Support\Facades\Storage::disk('local')->exists($tempFilePath)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($tempFilePath);
                Log::info('Cleaned up temporary validation file', [
                    'processing_id' => $this->processingLog->processing_id,
                    'temp_path' => $tempFilePath,
                ]);
            }
        }
    }

    /**
     * Check if a disk is S3-compatible
     */
    private function isS3Disk(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        return isset($diskConfig['driver']) && $diskConfig['driver'] === 's3';
    }

    public function failed(\Throwable $exception): void
    {
        $this->processingLog->update([
            'status' => 'failed',
            'error_message' => 'Audio validation job failed: '.$exception->getMessage(),
        ]);
    }
}
