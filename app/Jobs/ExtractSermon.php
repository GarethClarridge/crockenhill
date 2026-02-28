<?php

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\StorageAdapterHelper;
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

    public function handle(VideoExtractionService $videoExtractor, VideoStorageService $storageService, StorageAdapterHelper $storageHelper): void
    {
        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;

            if ($this->processingLog->isCancelled()) {
                Log::info('ExtractSermon job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            // Update status to show sermon extraction is starting
            $this->processingLog->markAsProcessing('extraction');

            $extractionBounds = $this->resolveExtractionBounds();

            Log::info('Starting sermon extraction', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start_time' => $extractionBounds['start_time'],
                'sermon_end_time' => $extractionBounds['end_time'],
                'bounds_source' => $extractionBounds['source'],
            ]);

            $sermonSegment = $this->createSermonSegment($extractionBounds['start_time'], $extractionBounds['end_time']);

            $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
            $isS3TempDisk = $this->isS3Disk($tempDisk);
            $sourceFilePath = $this->requireSourceFilePath();

            if ($isS3TempDisk) {
                if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                    throw new \Exception('Original video file not found on S3: '.$sourceFilePath);
                }

                $videoPath = $storageHelper->downloadToTemp(
                    $sourceFilePath,
                    $tempDisk,
                    'local',
                    'temp/extraction'
                );
            } else {
                $videoPath = Storage::disk($tempDisk)->path($sourceFilePath);

                // Wait for file to be available (handles async upload/storage delays)
                $maxAttempts = 5;
                $attempt = 0;
                while (! file_exists($videoPath) && $attempt < $maxAttempts) {
                    $attempt++;
                    Log::warning('Video file not yet available, waiting...', [
                        'processing_id' => $this->processingLog->processing_id,
                        'attempt' => $attempt,
                        'expected_path' => $videoPath,
                    ]);
                    sleep(2);
                }

                if (! file_exists($videoPath)) {
                    throw new \Exception('Original video file not found after waiting: '.$videoPath);
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
                if ($isS3TempDisk) {
                    $storageHelper->cleanupTempFile($videoPath);
                }
            }

            // DEFENSIVE: Verify audio file actually exists before storing path in database
            $audioFullPath = $audioExtractionResult['full_path'];
            $audioFileExists = $this->verifyAudioFileExists($storageService, $sermonAudioPath, $audioFullPath);

            if (! $audioFileExists) {
                throw new \Exception("Audio extraction claimed success but file does not exist: {$audioFullPath}");
            }

            Log::info('Audio file existence verified before database update', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $sermonAudioPath,
                'full_path' => $audioFullPath,
                'file_exists' => $audioFileExists,
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
                'file_exists_check' => $audioFileExists,
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

    private function createSermonSegment(float $startTime, float $endTime): LivestreamSegment
    {
        return new LivestreamSegment(
            startTime: $startTime,
            endTime: $endTime,
            duration: $endTime - $startTime,
            classification: 'speech',
            avgRms: 0.0, // Not needed for extraction
            peakRms: 0.0, // Not needed for extraction
            isSermonCandidate: true,
            segmentOrder: 0
        );
    }

    /**
     * @return array{start_time: float, end_time: float, source: string}
     */
    private function resolveExtractionBounds(): array
    {
        $preferClassifiedSection = (bool) config(
            'media-processing.section_classification.prefer_high_confidence_sermon_section',
            true
        );

        if ($preferClassifiedSection) {
            $preferredSection = $this->findPreferredSermonSection();

            if ($preferredSection instanceof ServiceSection) {
                return [
                    'start_time' => (float) $preferredSection->start_time,
                    'end_time' => (float) $preferredSection->end_time,
                    'source' => 'service_section',
                ];
            }
        }

        $baselineStart = $this->processingLog->sermon_start_time;
        $baselineEnd = $this->processingLog->sermon_end_time;

        if (! is_float($baselineStart) || ! is_float($baselineEnd) || $baselineEnd <= $baselineStart) {
            throw new \Exception('Sermon segment times not found in processing log');
        }

        return [
            'start_time' => $baselineStart,
            'end_time' => $baselineEnd,
            'source' => 'processing_log',
        ];
    }

    private function findPreferredSermonSection(): ?ServiceSection
    {
        $preferredSection = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('section_type', ServiceSectionType::SERMON->value)
            ->where('status', ServiceSectionStatus::IDENTIFIED->value)
            ->where('needs_manual_review', false)
            ->where('metadata->confidence_level', 'high')
            ->whereColumn('end_time', '>', 'start_time')
            ->orderByDesc('duration')
            ->orderByDesc('id')
            ->first();

        return $preferredSection instanceof ServiceSection ? $preferredSection : null;
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

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \Exception('No source video path found in processing log');
        }

        return $sourceFilePath;
    }
}
