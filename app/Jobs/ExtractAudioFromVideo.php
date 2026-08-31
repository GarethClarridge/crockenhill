<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonProcessingStepTransitions;
use App\Traits\ChecksCancellation;
use FFMpeg\FFProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ExtractAudioFromVideo - Job to extract audio from video files
 *
 * Part of the new job chain pattern for direct video processing pipeline.
 * Extracts audio optimized for transcription from video files.
 * Uses VideoExtractionService (same as livestream processing) for S3-aware extraction.
 */
class ExtractAudioFromVideo implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VideoExtractionService $videoExtractor,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null,
        ?SermonProcessingStepTransitions $processingStepTransitions = null,
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);
        $processingStepTransitions ??= app(SermonProcessingStepTransitions::class);

        if ($this->abortIfCancelled('ExtractAudioFromVideo')) {
            if ($this->processingLog->isCancelled()) {
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'extracting_audio',
                    'Processing cancelled before audio extraction.',
                );
            }

            return;
        }

        $processingStepTransitions->markAsStarted(
            $this->processingLog->processing_id,
            'extracting_audio',
            'Extracting audio from video.',
        );

        Log::info('Extracting audio from video', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        try {
            $storedFilePath = $this->processingLog->stored_file_path;

            if (! $storedFilePath) {
                throw new \Exception('No stored file path found in processing log');
            }

            // Convert relative storage path to absolute filesystem path
            $disk = config('filesystems.default', 'local');
            $videoPath = Storage::disk($disk)->path($storedFilePath);

            Log::info('ExtractAudioFromVideo path resolution', [
                'processing_id' => $this->processingLog->processing_id,
                'stored_file_path' => $storedFilePath,
                'resolved_absolute_path' => $videoPath,
                'disk' => $disk,
            ]);

            // Verify file exists before extraction
            if (! file_exists($videoPath)) {
                throw new \Exception("Video file not found at path: {$videoPath} (relative: {$storedFilePath})");
            }

            // Get video duration using FFprobe
            $duration = $this->getVideoDuration($videoPath);

            // Create a full-video segment (extract all audio from start to end)
            // This makes direct video processing work exactly like livestream sermon extraction
            $fullVideoSegment = new LivestreamSegment(
                startTime: 0.0,
                endTime: $duration,
                duration: $duration,
                classification: LivestreamSegmentClassification::Speech->value,
                avgRms: -30.0,
                peakRms: -20.0,
                isSermonCandidate: true,
                segmentOrder: 1
            );

            // Use VideoExtractionService (same as livestream processing)
            // This gives us S3-aware extraction, compression, and relative paths
            $audioExtractionResult = $videoExtractor->extractOptimizedAudio(
                $videoPath,
                $fullVideoSegment,
                $this->processingLog->processing_id.'_audio.mp3'
            );

            // Store RELATIVE path (same as livestream processing)
            $sermonAudioPath = $audioExtractionResult['audio_path'];

            // Update processing log with audio path
            $this->processingLog->update([
                'current_step' => 'audio_extraction_complete',
                'status' => 'processing',
                'audio_file_path' => $sermonAudioPath,
                'duration' => $duration,
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata?->toArray() ?? [],
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

            Log::info('Audio extraction from video completed', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $sermonAudioPath,
                'full_path' => $audioExtractionResult['full_path'],
                'compression_applied' => $audioExtractionResult['compression_applied'],
                'original_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
            ]);
            $processingStepTransitions->markAsCompleted(
                $this->processingLog->processing_id,
                'extracting_audio',
                'Audio extracted from video.',
            );

        } catch (\Throwable $e) {
            Log::error('Audio extraction from video failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'Audio extraction failed: '.$e->getMessage());
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'extracting_audio',
                'Audio extraction failed: '.$e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * Get video duration using FFprobe
     */
    private function getVideoDuration(string $videoPath): float
    {
        try {
            $ffprobe = FFProbe::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            return (float) $ffprobe->format($videoPath)->get('duration');
        } catch (\Exception $e) {
            Log::warning('Failed to get video duration, using default', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            return 3600.0; // Default 1 hour
        }
    }

    public function failed(\Throwable $exception): void
    {
        $processingLog = $this->processingLog->fresh();
        $processingStepTransitions = app(SermonProcessingStepTransitions::class);

        if ($processingLog instanceof MediaProcessingLog && $processingLog->isCancelled()) {
            $processingStepTransitions->markAsSkipped(
                $this->processingLog->processing_id,
                'extracting_audio',
                'Processing cancelled before the final audio-extraction attempt.',
            );
        } else {
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'extracting_audio',
                'Audio extraction job failed: '.$exception->getMessage(),
            );
        }

        app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($this->processingLog, 'Audio extraction job failed: '.$exception->getMessage());
    }
}
