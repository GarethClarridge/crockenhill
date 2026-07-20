<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ServiceTranscriptionInterface;
use App\Enums\ProcessingStep;
use App\Enums\ServiceStructureMode;
use App\Models\MediaProcessingLog;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One timestamped transcription pass over the whole service recording.
 *
 * Stores the resulting ChurchServiceTranscript JSON on the temp disk (keyed by
 * processing id, so re-runs overwrite) and records the path in
 * processing_metadata['service_transcript_path'] — mirroring the rms_log_path
 * precedent — for DetectServiceStructure to consume.
 */
class TranscribeFullService extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('transcribe-full-service-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        StorageAdapterHelper $storageHelper,
        ServiceTranscriptionInterface $transcriptionService
    ): void {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        if (! $this->processingLog->usesSegmentationPipeline()) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                'Full-service transcription only runs for segmentation pipelines'
            );

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::TranscribeFullService->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE);

        try {
            [$localSourcePath, $cleanupSourcePath] = $this->resolveLocalSourceVideoPath($storageHelper);
        } catch (\RuntimeException $exception) {
            // Reclassification of a completed run: the temp source video is
            // usually long cleaned up, but the stored transcript survives
            // cleanup for exactly this purpose — the recording has not
            // changed, so it is still valid evidence for detection.
            if ($this->hasStoredTranscript()) {
                $this->logStepSkipped(
                    ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                    'Source media unavailable; reusing the stored full-service transcript'
                );

                return;
            }

            throw $exception;
        }

        try {
            $transcript = $transcriptionService->transcribeService(
                $localSourcePath,
                $this->processingLog->processing_id
            );

            $transcriptPath = $this->storeTranscript($transcript->toArray());

            $this->processingLog->putServiceTranscriptPath($transcriptPath);

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                sprintf('Transcribed %d cue(s) covering %.0fs', count($transcript->cues), $transcript->duration)
            );
        } catch (\Throwable $throwable) {
            Log::error('Full-service transcription failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);

            // Shadow runs are evaluation-only, so transcription failures are
            // recorded without failing the processing run.
            if (ServiceStructureMode::fromConfig() === ServiceStructureMode::Shadow) {
                $this->logStepSkipped(
                    ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                    'Shadow transcription failed: '.$throwable->getMessage()
                );

                return;
            }

            throw $throwable;
        } finally {
            if ($cleanupSourcePath) {
                $storageHelper->cleanupTempFile($localSourcePath);
            }
        }
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
            $exception->getMessage()
        );
    }

    private function hasStoredTranscript(): bool
    {
        return $this->processingLog->hasStoredServiceTranscript();
    }

    /**
     * @param  array<string, mixed>  $transcriptData
     */
    private function storeTranscript(array $transcriptData): string
    {
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $transcriptPath = 'temp/service_transcript_'.$this->processingLog->processing_id.'.json';

        Storage::disk($tempDisk)->put(
            $transcriptPath,
            json_encode($transcriptData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        return $transcriptPath;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveLocalSourceVideoPath(StorageAdapterHelper $storageHelper): array
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \RuntimeException('No source video path found in processing log');
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if ($this->isS3Disk($tempDisk)) {
            if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                throw new \RuntimeException('Source video not found on temp disk');
            }

            return [
                $storageHelper->downloadToTemp($sourceFilePath, $tempDisk, 'local', 'temp/service-transcription'),
                true,
            ];
        }

        $localSourcePath = Storage::disk($tempDisk)->path($sourceFilePath);
        if (! file_exists($localSourcePath)) {
            throw new \RuntimeException('Source video file not found');
        }

        return [$localSourcePath, false];
    }
}
