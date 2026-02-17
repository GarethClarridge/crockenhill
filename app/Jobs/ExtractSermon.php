<?php

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\VideoExtractionService;
use App\Services\VideoStorageService;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractSermon implements ShouldQueue
{
    use DetectsStorageType, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoExtractionService $videoExtractor, VideoStorageService $storageService): void
    {
        try {
            // Update status to show sermon extraction is starting
            $this->processingLog->markAsProcessing('extraction');

            Log::info('Starting sermon extraction', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start_time' => $this->processingLog->sermon_start_time,
                'sermon_end_time' => $this->processingLog->sermon_end_time,
            ]);

            if (! $this->processingLog->sermon_start_time || ! $this->processingLog->sermon_end_time) {
                throw new \Exception('Sermon segment times not found in processing log');
            }

            $sermonSegment = $this->createSermonSegment();

            // Get temp disk and check if it's S3-compatible
            $tempDisk = config('media-processing.storage.temp_disk');
            $isS3TempDisk = $this->isS3Disk($tempDisk);
            $localTempPath = null;

            if ($isS3TempDisk) {
                // For S3 temp disks, verify file exists using Storage disk
                if (! Storage::disk($tempDisk)->exists($this->processingLog->source_file_path)) {
                    throw new \Exception('Original video file not found on S3: '.$this->processingLog->source_file_path);
                }

                // Download to local temp for processing
                $localTempPath = storage_path('app/temp/'.basename($this->processingLog->source_file_path).'_'.time());
                $this->ensureDirectoryExists(dirname($localTempPath));

                $videoStream = Storage::disk($tempDisk)->readStream($this->processingLog->source_file_path);
                file_put_contents($localTempPath, $videoStream);
                $videoPath = $localTempPath;
            } else {
                // For local temp disks, use direct path
                $videoPath = Storage::disk($tempDisk)->path($this->processingLog->source_file_path);

                if (! file_exists($videoPath)) {
                    throw new \Exception('Original video file not found: '.$videoPath);
                }
            }

            try {
                $sermonVideoPath = $videoExtractor->extractSegmentAsFile(
                    $videoPath,
                    $sermonSegment,
                    $this->processingLog->processing_id.'_sermon.mp4'
                );

                $audioExtractionResult = $videoExtractor->extractOptimizedAudio(
                    $videoPath,
                    $sermonSegment,
                    $this->processingLog->processing_id.'_sermon.mp3'
                );

                $sermonAudioPath = $audioExtractionResult['audio_path'];
            } finally {
                // Clean up temporary S3 download file if we created one
                if ($isS3TempDisk && $localTempPath && file_exists($localTempPath)) {
                    unlink($localTempPath);
                }
            }

            // DEFENSIVE: Verify audio file actually exists before storing path in database
            $audioFullPath = $audioExtractionResult['full_path'];
            $fileExists = $this->verifyAudioFileExists($storageService, $sermonAudioPath, $audioFullPath);

            if (! $fileExists) {
                throw new \Exception("Audio extraction claimed success but file does not exist: {$audioFullPath}");
            }

            Log::info('Audio file existence verified before database update', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $sermonAudioPath,
                'full_path' => $audioFullPath,
                'file_exists' => $fileExists,
                'verification_method' => $this->isS3Path($audioFullPath) ? 's3_storage' : 'local_filesystem',
            ]);

            $this->processingLog->update([
                'video_file_path' => $sermonVideoPath,
                'audio_file_path' => $sermonAudioPath,
                'current_step' => 'extraction_complete',
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'audio_compression' => [
                            'original_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                            'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                            'compression_applied' => $audioExtractionResult['compression_applied'],
                            'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                            'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                        ],
                    ]
                ),
            ]);

            if (! $audioExtractionResult['valid_for_transcription']) {
                Log::warning('Audio file still too large after compression', [
                    'processing_id' => $this->processingLog->processing_id,
                    'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                    'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                ]);
            }

            Log::info('Sermon extraction completed', [
                'processing_id' => $this->processingLog->processing_id,
                'video_path' => $sermonVideoPath,
                'audio_path' => $sermonAudioPath,
                'audio_full_path' => $audioExtractionResult['full_path'],
                'compression_applied' => $audioExtractionResult['compression_applied'],
                'original_audio_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                'final_audio_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                'compression_ratio' => $audioExtractionResult['compression_ratio'],
                'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                'file_exists_check' => $this->verifyAudioFileExists($storageService, $sermonAudioPath, $audioExtractionResult['full_path']),
            ]);

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            Log::error('Sermon extraction failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->processingLog->markAsFailed('Sermon extraction failed: '.$e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    private function createSermonSegment(): LivestreamSegment
    {
        return new LivestreamSegment(
            startTime: $this->processingLog->sermon_start_time,
            endTime: $this->processingLog->sermon_end_time,
            duration: $this->processingLog->sermon_end_time - $this->processingLog->sermon_start_time,
            classification: 'speech',
            avgRms: 0.0, // Not needed for extraction
            peakRms: 0.0, // Not needed for extraction
            isSermonCandidate: true,
            segmentOrder: 0
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractSermon job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->processingLog->markAsFailed(
            'Sermon extraction failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(1);
    }

    /**
     * Verify audio file exists using appropriate method for storage type
     */
    private function verifyAudioFileExists(VideoStorageService $storageService, string $audioPath, string $fullPath): bool
    {
        // For S3 URLs, use Storage disk to verify existence
        if ($this->isS3Path($fullPath)) {
            $diskName = config('media-processing.storage.sermon_disk', 'public');

            return Storage::disk($diskName)->exists($audioPath);
        }

        // For local paths, use file_exists
        return file_exists($fullPath);
    }

    /**
     * Ensure directory exists (for local operations only)
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new \Exception("Failed to create directory: {$directory}");
            }
        }
    }
}
