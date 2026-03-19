<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\AudioExtractionService;
use App\Services\StorageAdapterHelper;
use App\Traits\DetectsStorageType;
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
    use DetectsStorageType, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(AudioExtractionService $audioExtractor, StorageAdapterHelper $storageHelper): void
    {
        Log::info('Validating audio file', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        $localTempPath = null;

        try {
            $storedFilePath = $this->processingLog->stored_file_path;
            $originalName = $this->processingLog->original_filename;

            if (! $storedFilePath) {
                throw new \Exception('No stored file path found in processing log');
            }

            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            $isS3Disk = $this->isS3Disk($sermonDisk);

            Log::info('ValidateAudioFile path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'sermon_disk' => $sermonDisk,
                'is_s3_disk' => $isS3Disk,
            ]);

            if ($isS3Disk) {
                if (! \Illuminate\Support\Facades\Storage::disk($sermonDisk)->exists($storedFilePath)) {
                    throw new \Exception("Audio file not found in S3 storage: {$storedFilePath}");
                }

                Log::info('Downloading audio from S3 for validation', [
                    'processing_id' => $this->processingLog->processing_id,
                    's3_path' => $storedFilePath,
                ]);

                $localTempPath = $storageHelper->downloadToTemp($storedFilePath, $sermonDisk, 'local', 'temp/audio-validation');
                $filePath = $localTempPath;
            } else {
                $filePath = \Illuminate\Support\Facades\Storage::disk($sermonDisk)->path($storedFilePath);
            }

            if (! file_exists($filePath)) {
                throw new \Exception("Audio file not found at path: {$filePath} (relative: {$storedFilePath})");
            }

            $mimeType = mime_content_type($filePath);
            if ($mimeType === false) {
                throw new \Exception('Could not determine audio file MIME type');
            }

            $file = new UploadedFile($filePath, $originalName, $mimeType, null, true);

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

            $this->processingLog->markAsFailed('Audio validation failed: '.$e->getMessage());

            throw $e;
        } finally {
            if ($localTempPath) {
                $storageHelper->cleanupTempFile($localTempPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->processingLog->markAsFailed('Audio validation job failed: '.$exception->getMessage());
    }
}
