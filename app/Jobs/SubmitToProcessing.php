<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Services\SermonProcessingService;
use App\Services\SermonMetadataIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class SubmitToProcessing implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(
        SermonProcessingService $sermonProcessingService,
        SermonMetadataIntegrationService $metadataIntegrationService
    ): void
    {
        try {
            Log::info('Starting sermon processing submission', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $this->processingLog->sermon_audio_path
            ]);

            if (!$this->processingLog->sermon_audio_path) {
                throw new \Exception('Sermon audio path not found in processing log');
            }

            $audioPath = Storage::disk(config('livestream-processing.sermon_disk'))
                ->path($this->processingLog->sermon_audio_path);

            if (!file_exists($audioPath)) {
                throw new \Exception('Sermon audio file not found: ' . $audioPath);
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

            // Store video with extracted metadata using the integration service
            $metadataWithSermonId = array_merge($result['metadata'] ?? [], ['sermon_id' => $result['sermon_id']]);
            $finalVideoPath = $metadataIntegrationService->storeVideoWithMetadata(
                $this->processingLog->processing_id,
                $metadataWithSermonId
            );

            // Link video to sermon record
            $metadataIntegrationService->linkVideoToSermon(
                $this->processingLog->processing_id,
                $result['sermon_id']
            );

            $this->processingLog->update([
                'sermon_id' => $result['sermon_id'],
                'status' => 'completed',
                'completed_at' => now(),
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'sermon_processing_id' => $result['processing_id'],
                        'submitted_at' => now()->toISOString(),
                        'sermon_metadata' => $result['metadata'] ?? null,
                        'final_video_path' => $finalVideoPath,
                    ]
                ),
            ]);

            Log::info('Sermon processing submission completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $result['sermon_id'],
                'sermon_processing_id' => $result['processing_id'],
                'final_video_path' => $finalVideoPath
            ]);

            // Job chain will automatically proceed to cleanup job

        } catch (\Exception $e) {
            Log::error('Sermon processing submission failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = 'Sermon processing submission failed: ' . $e->getMessage();
            
            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now(),
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'submission_failed_at' => now()->toISOString(),
                        'submission_error' => $e->getMessage(),
                    ]
                ),
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
            'attempts' => $this->attempts()
        ]);

        $this->processingLog->markAsFailed(
            'Sermon processing submission failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
