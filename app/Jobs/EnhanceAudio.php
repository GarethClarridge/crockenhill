<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioEnhancementService;
use App\Services\StorageAdapterHelper;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EnhanceAudio - Optional, non-fatal audio quality improvement step.
 *
 * Applies FFmpeg noise reduction, dynamic normalisation, and loudness normalisation
 * before transcription. If enhancement fails for any reason, processing continues
 * with the original file — the job never marks the run as failed.
 */
#[FailOnTimeout]
class EnhanceAudio extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries — enhancement is best-effort and failure should not block the pipeline.
     */
    public int $tries = 1;

    /**
     * Allow up to 10 minutes for large files (FFmpeg two-pass loudnorm can be slow).
     */
    public int $timeout = 600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    public function handle(AudioEnhancementService $enhancer, StorageAdapterHelper $storageHelper): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            Log::info('EnhanceAudio: job cancelled or log missing', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        $this->logStepStart('audio_enhancement');

        $localTempPath = null;

        try {
            $relativePath = $this->resolveAudioPath();

            if (empty($relativePath)) {
                Log::warning('EnhanceAudio: no audio path found, skipping', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);
                $this->logStepSkipped('audio_enhancement', 'No audio path available');
                $this->processingLog->update(['current_step' => 'audio_enhancement_skipped']);

                return;
            }

            $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');

            $localInputPath = $storageHelper->downloadToTemp(
                $relativePath,
                $sermonDisk,
                'local',
                'temp/audio-enhancement'
            );

            // Track the temp download so we can clean it up if it was created for S3
            if ($this->isS3Disk($sermonDisk)) {
                $localTempPath = $localInputPath;
            }

            $enhancedPath = $enhancer->enhance($localInputPath, $this->processingLog->processing_id);

            if ($enhancedPath === null) {
                $this->logStepSkipped('audio_enhancement', 'Enhancement disabled or failed');
                $this->processingLog->update(['current_step' => 'audio_enhancement_skipped']);

                return;
            }

            $this->processingLog->update([
                'enhanced_audio_file_path' => $enhancedPath,
                'current_step' => 'audio_enhancement_complete',
            ]);

            $this->logStepComplete('audio_enhancement');

            Log::info('EnhanceAudio: completed', [
                'processing_id' => $this->processingLog->processing_id,
                'enhanced_path' => $enhancedPath,
            ]);

        } catch (\Throwable $e) {
            // Non-fatal: log and allow the pipeline to continue
            Log::warning('EnhanceAudio: unexpected error, skipping enhancement', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);
            $this->logStepSkipped('audio_enhancement', 'Unexpected error: '.$e->getMessage());
            $this->processingLog->update(['current_step' => 'audio_enhancement_skipped']);
        } finally {
            if ($localTempPath && file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }

    /**
     * Resolve the relative audio file path from the processing log.
     *
     * For direct audio uploads use the stored source file.
     * For video/livestream uploads use the extracted audio file.
     */
    private function resolveAudioPath(): ?string
    {
        return match ($this->processingLog->processing_type) {
            MediaType::Audio => $this->processingLog->source_file_path,
            MediaType::Video,
            MediaType::Livestream => $this->processingLog->audio_file_path,
        };
    }
}
