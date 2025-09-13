<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Services\SermonMetadataIntegrationService;
use App\Services\SermonProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SubmitToProcessing implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(
        SermonProcessingService $sermonProcessingService,
        SermonMetadataIntegrationService $metadataIntegrationService
    ): void {
        try {
            // Update status to show transcription processing is starting
            $this->processingLog->update(['status' => 'transcription']);

            Log::info('Starting sermon processing submission', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $this->processingLog->sermon_audio_path,
            ]);

            if (! $this->processingLog->sermon_audio_path) {
                throw new \Exception('Sermon audio path not found in processing log');
            }

            // Get sermon disk configuration - should use 'public' for web accessibility
            $sermonDisk = config('livestream-processing.sermon_disk', 'public');
            $audioPath = Storage::disk($sermonDisk)
                ->path($this->processingLog->sermon_audio_path);

            if (! file_exists($audioPath)) {
                throw new \Exception('Sermon audio file not found: '.$audioPath);
            }

            // Validate that the file is accessible via the public disk for web serving
            if ($sermonDisk === 'public' && ! Storage::disk('public')->exists($this->processingLog->sermon_audio_path)) {
                throw new \Exception('Sermon audio file not accessible via public disk: '.$this->processingLog->sermon_audio_path);
            }

            $uploadedFile = new UploadedFile(
                $audioPath,
                basename($audioPath),
                'audio/mp3',
                null,
                true
            );

            $metadata = [
                'source_type' => 'livestream',
                'livestream_processing_id' => $this->processingLog->processing_id,
                'original_filename' => $this->processingLog->original_filename,
                'segment_start_time' => $this->processingLog->sermon_start_time,
                'segment_end_time' => $this->processingLog->sermon_end_time,
                'video_file_path' => $this->processingLog->sermon_video_path,
            ];

            $result = $sermonProcessingService->processSermonAudio($uploadedFile, $metadata);

            // Store video in permanent location
            $finalVideoPath = $metadataIntegrationService->storeVideoForSermon(
                $this->processingLog->processing_id,
                $result['sermon_id']
            );

            // Link video to sermon record
            $metadataIntegrationService->linkVideoToSermon(
                $this->processingLog->processing_id,
                $result['sermon_id'],
                $finalVideoPath
            );

            // Dispatch thumbnail generation job after sermon creation
            // Use the final video path for thumbnail generation
            $this->dispatchThumbnailGeneration($result['sermon_id'], $finalVideoPath);

            // Validate that the sermon record has a valid audio file path
            if ($result['sermon_id']) {
                $sermon = \App\Models\Sermon::find($result['sermon_id']);
                if ($sermon && $sermon->filename) {
                    // Verify the audio file is accessible for web serving
                    if (! Storage::disk('public')->exists($sermon->filename)) {
                        Log::warning('Sermon audio file may not be web-accessible', [
                            'sermon_id' => $result['sermon_id'],
                            'filename' => $sermon->filename,
                            'processing_id' => $this->processingLog->processing_id,
                        ]);
                    }
                }
            }

            $this->processingLog->update([
                'sermon_id' => $result['sermon_id'],
                'status' => 'completed',
                'completed_at' => now(),
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'sermon_processing_id' => $result['processing_id'],
                        'final_video_path' => $finalVideoPath,
                    ]
                ),
            ]);

            Log::info('Sermon processing submission completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $result['sermon_id'],
                'sermon_processing_id' => $result['processing_id'],
                'final_video_path' => $finalVideoPath,
            ]);

            // Job chain will automatically proceed to cleanup job

        } catch (\Exception $e) {
            Log::error('Sermon processing submission failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = 'Sermon processing submission failed: '.$e->getMessage();

            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SubmitToProcessing job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->processingLog->markAsFailed(
            'Sermon processing submission failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /**
     * Dispatch thumbnail generation job for the created sermon
     */
    private function dispatchThumbnailGeneration(int $sermonId, string $videoPath): void
    {
        try {
            // Check if thumbnail generation is enabled
            if (! config('thumbnail-generation.enabled', true)) {
                Log::info('Thumbnail generation disabled, skipping', [
                    'sermon_id' => $sermonId,
                ]);

                return;
            }

            // Get the full path to the video file
            $sermonDisk = config('livestream-processing.sermon_disk', 'public');
            $fullVideoPath = Storage::disk($sermonDisk)->path($videoPath);

            // Verify video file exists before dispatching job
            if (! file_exists($fullVideoPath)) {
                Log::warning('Video file not found for thumbnail generation', [
                    'sermon_id' => $sermonId,
                    'video_path' => $videoPath,
                    'full_path' => $fullVideoPath,
                ]);

                return;
            }

            // Dispatch thumbnail generation job to dedicated queue
            GenerateThumbnail::dispatch($sermonId, $fullVideoPath)
                ->onQueue(config('thumbnail-generation.queue.name', 'thumbnails'));

            Log::info('Thumbnail generation job dispatched', [
                'sermon_id' => $sermonId,
                'video_path' => $fullVideoPath,
                'processing_id' => $this->processingLog->processing_id,
            ]);

        } catch (\Exception $e) {
            // Log error but don't throw - thumbnail generation should never block processing
            Log::warning('Failed to dispatch thumbnail generation job', [
                'sermon_id' => $sermonId,
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
                'processing_id' => $this->processingLog->processing_id,
            ]);
        }
    }
}
