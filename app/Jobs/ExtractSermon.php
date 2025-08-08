<?php

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Models\LivestreamProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractSermon implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $timeout = 3600;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(VideoStorageService $storageService): void
    {
        try {
            Log::info('Starting sermon extraction', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start_time' => $this->processingLog->sermon_start_time,
                'sermon_end_time' => $this->processingLog->sermon_end_time
            ]);

            if (!$this->processingLog->sermon_start_time || !$this->processingLog->sermon_end_time) {
                throw new \Exception('Sermon segment times not found in processing log');
            }

            $sermonSegment = $this->createSermonSegment();
            $videoPath = Storage::disk(config('livestream-processing.temp_disk'))
                ->path($this->processingLog->original_file_path);

            if (!file_exists($videoPath)) {
                throw new \Exception('Original video file not found: ' . $videoPath);
            }

            $sermonVideoPath = $storageService->extractVideoSegment(
                $videoPath,
                $sermonSegment,
                $this->processingLog->processing_id . '_sermon.mp4'
            );

            $audioExtractionResult = $storageService->extractOptimizedAudioFromSegment(
                $videoPath,
                $sermonSegment,
                $this->processingLog->processing_id . '_sermon.mp3'
            );

            $sermonAudioPath = $audioExtractionResult['audio_path'];

            $this->processingLog->update([
                'sermon_video_path' => $sermonVideoPath,
                'sermon_audio_path' => $sermonAudioPath,
                'status' => 'extraction_complete',
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'audio_compression' => [
                            'original_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                            'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                            'compression_applied' => $audioExtractionResult['compression_applied'],
                            'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                            'valid_for_transcription' => $audioExtractionResult['valid_for_transcription']
                        ]
                    ]
                )
            ]);

            if (!$audioExtractionResult['valid_for_transcription']) {
                Log::warning('Audio file still too large after compression', [
                    'processing_id' => $this->processingLog->processing_id,
                    'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                    'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2)
                ]);
            }

            Log::info('Sermon extraction completed', [
                'processing_id' => $this->processingLog->processing_id,
                'video_path' => $sermonVideoPath,
                'audio_path' => $sermonAudioPath,
                'compression_applied' => $audioExtractionResult['compression_applied'],
                'final_audio_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                'valid_for_transcription' => $audioExtractionResult['valid_for_transcription']
            ]);

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            Log::error('Sermon extraction failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->processingLog->markAsFailed('Sermon extraction failed: ' . $e->getMessage());

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
            'attempts' => $this->attempts()
        ]);

        $this->processingLog->markAsFailed(
            'Sermon extraction failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(1);
    }
}
