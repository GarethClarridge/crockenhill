<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioExtractionServiceInterface;
use App\Models\SermonProcessingLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ExtractAudioFromVideo - Job to extract audio from video files
 *
 * Part of the new job chain pattern for direct video processing pipeline.
 * Extracts audio optimized for transcription from video files.
 */
class ExtractAudioFromVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private SermonProcessingLog $processingLog
    ) {}

    public function handle(AudioExtractionServiceInterface $audioExtractor): void
    {
        Log::info('Extracting audio from video', [
            'processing_id' => $this->processingLog->processing_id,
        ]);

        try {
            $videoPath = $this->processingLog->stored_file_path;

            // Get video duration (placeholder - would need FFMpeg integration)
            $duration = 3600; // Default 1 hour, should be extracted from video metadata

            // Extract audio using the service
            $audioPath = $audioExtractor->extractFromVideo($videoPath, $duration);

            // Update processing log with audio path
            $this->processingLog->update([
                'current_step' => 'audio_extraction_complete',
                'status' => 'processing',
                'audio_file_path' => $audioPath,
            ]);

            Log::info('Audio extraction from video completed', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $audioPath,
            ]);

        } catch (\Exception $e) {
            Log::error('Audio extraction from video failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => 'Audio extraction failed: '.$e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->processingLog->update([
            'status' => 'failed',
            'error_message' => 'Audio extraction job failed: '.$exception->getMessage(),
        ]);
    }
}
