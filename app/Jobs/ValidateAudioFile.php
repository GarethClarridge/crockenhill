<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\AudioExtractionService;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\StorageAdapterHelper;
use App\Traits\ChecksCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ValidateAudioFile - Job to validate audio files for processing
 *
 * Part of the new job chain pattern for audio processing pipeline.
 * Ensures audio files meet requirements before processing begins.
 */
class ValidateAudioFile implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        AudioExtractionService $audioExtractor,
        StorageAdapterHelper $storageHelper,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);

        if ($this->abortIfCancelled('ValidateAudioFile')) {
            return;
        }

        Log::info('Validating audio file', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        /** @var string|null $localTempPath */
        $localTempPath = null;

        try {
            $storedFilePath = $this->resolveStoredFilePath();
            $originalName = $this->processingLog->original_filename;

            $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
            $sermonStorage = Storage::disk($sermonDisk);
            $diskDriver = config("filesystems.disks.{$sermonDisk}.driver");
            $isS3Disk = is_string($diskDriver) && $diskDriver === 's3';

            Log::info('ValidateAudioFile path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'sermon_disk' => $sermonDisk,
                'is_s3_disk' => $isS3Disk,
            ]);

            if ($isS3Disk) {
                if (! $sermonStorage->exists($storedFilePath)) {
                    throw new RuntimeException("Audio file not found in S3 storage: {$storedFilePath}");
                }

                Log::info('Downloading audio from S3 for validation', [
                    'processing_id' => $this->processingLog->processing_id,
                    's3_path' => $storedFilePath,
                ]);

                $localTempPath = $storageHelper->downloadToTemp($storedFilePath, $sermonDisk, 'local', 'temp/audio-validation');
                $filePath = $localTempPath;
            } else {
                $filePath = $sermonStorage->path($storedFilePath);
            }

            if (! file_exists($filePath)) {
                throw new RuntimeException("Audio file not found at path: {$filePath} (relative: {$storedFilePath})");
            }

            $mimeType = mime_content_type($filePath);
            if ($mimeType === false) {
                throw new RuntimeException('Could not determine audio file MIME type');
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

        } catch (\Throwable $e) {
            Log::error('Audio file validation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'Audio validation failed: '.$e->getMessage());

            throw $e;
        } finally {
            if ($localTempPath !== null) {
                $storageHelper->cleanupTempFile($localTempPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($this->processingLog, 'Audio validation job failed: '.$exception->getMessage());
    }

    private function resolveStoredFilePath(): string
    {
        $attributes = $this->processingLog->getAttributes();
        $storedFilePath = $attributes['source_file_path'] ?? $attributes['stored_file_path'] ?? null;

        throw_unless(
            is_string($storedFilePath) && trim($storedFilePath) !== '',
            RuntimeException::class,
            'No stored file path found in processing log',
        );

        return (string) $storedFilePath;
    }
}
