<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscribeSpeechSegments extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

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
            (new WithoutOverlapping('transcribe-speech-segments-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper,
        TranscriptionServiceInterface $transcriptionService
    ): void {
        if (! (bool) config('media-processing.section_classification.transcribe_speech_segments', true)) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS, 'Speech segment transcription disabled');

            return;
        }

        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;
        $this->initializeStepLogging($this->processingLog->processing_id);

        if (
            $this->processingLog->processing_type !== MediaType::Livestream
            || $this->processingLog->isCancelled()
        ) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS, 'Speech segment transcription only runs for active livestream processing');

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::TranscribeSpeechSegments->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('status', ServiceSectionStatus::IDENTIFIED->value)
            ->whereNotIn('section_type', [
                ServiceSectionType::SONG->value,
                ServiceSectionType::SERMON->value,
            ])
            ->orderBy('section_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ServiceSection $section): bool => $this->shouldTranscribe($section));

        if ($sections->isEmpty()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS, 'No eligible speech sections to transcribe');

            return;
        }

        try {
            [$localSourcePath, $cleanupSourcePath] = $this->resolveLocalSourceVideoPath($storageHelper);
        } catch (\Throwable $throwable) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS, 'Source video unavailable');

            Log::warning('Speech segment transcription skipped: source video unavailable', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);

            return;
        }

        $transcribedCount = 0;
        $failedCount = 0;

        try {
            foreach ($sections as $section) {
                if ($this->transcribeSection($section, $localSourcePath, $videoExtractor, $transcriptionService)) {
                    $transcribedCount++;
                } else {
                    $failedCount++;
                }
            }

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
                sprintf('Transcribed %d section(s)%s', $transcribedCount, $failedCount > 0 ? "; {$failedCount} failed" : '')
            );
        } finally {
            if ($cleanupSourcePath) {
                $storageHelper->cleanupTempFile($localSourcePath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            $exception->getMessage()
        );

        Log::error('TranscribeSpeechSegments job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    private function shouldTranscribe(ServiceSection $section): bool
    {
        if ($this->hasTranscript($section)) {
            return false;
        }

        return $this->effectiveDurationSeconds($section) >= $this->minimumDurationSeconds();
    }

    private function effectiveDurationSeconds(ServiceSection $section): float
    {
        return max(0.0, (float) $section->end_time - (float) $section->start_time);
    }

    private function hasTranscript(ServiceSection $section): bool
    {
        $transcript = $section->metadata['transcript'] ?? null;

        return is_string($transcript) && trim($transcript) !== '';
    }

    private function minimumDurationSeconds(): int
    {
        return max(1, (int) config('media-processing.section_classification.speech_transcription_min_duration_seconds', 10));
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveLocalSourceVideoPath(StorageAdapterHelper $storageHelper): array
    {
        $sourceFilePath = $this->requireSourceFilePath();
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if ($this->isS3Disk($tempDisk)) {
            if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                throw new \RuntimeException('Source video not found on temp disk');
            }

            return [
                $storageHelper->downloadToTemp($sourceFilePath, $tempDisk, 'local', 'temp/section-transcription'),
                true,
            ];
        }

        $localSourcePath = Storage::disk($tempDisk)->path($sourceFilePath);
        if (! file_exists($localSourcePath)) {
            throw new \RuntimeException('Source video file not found');
        }

        return [$localSourcePath, false];
    }

    private function transcribeSection(
        ServiceSection $section,
        string $localSourcePath,
        VideoExtractionService $videoExtractor,
        TranscriptionServiceInterface $transcriptionService
    ): bool {
        $audioPath = null;

        try {
            $audioResult = $videoExtractor->extractOptimizedAudio(
                $localSourcePath,
                (object) [
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                ],
                $this->processingLog->processing_id.'_section_'.$section->id.'_speech.mp3'
            );

            $audioPath = $audioResult['audio_path'];
            $transcript = $transcriptionService->transcribe(
                $audioPath,
                $this->processingLog->processing_id.'-section-'.$section->id,
                $this->sermonDisk()
            );

            $metadata = array_merge($section->metadata?->toArray() ?? [], [
                'transcript' => $transcript,
            ]);

            $section->forceFill([
                'metadata' => $metadata,
            ])->save();

            return true;
        } catch (\Throwable $throwable) {
            Log::warning('Failed to transcribe speech section', [
                'processing_id' => $this->processingLog->processing_id,
                'service_section_id' => $section->id,
                'section_type' => $section->section_type->value,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        } finally {
            $this->cleanupExtractedAudio($audioPath);
        }
    }

    private function cleanupExtractedAudio(?string $audioPath): void
    {
        if (! is_string($audioPath) || $audioPath === '') {
            return;
        }

        $disk = $this->sermonDisk();

        try {
            if (Storage::disk($disk)->exists($audioPath)) {
                Storage::disk($disk)->delete($audioPath);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Failed to clean up extracted speech segment audio', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $audioPath,
                'disk' => $disk,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \RuntimeException('No source video path found in processing log');
        }

        return $sourceFilePath;
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }
}
