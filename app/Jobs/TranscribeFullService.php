<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ProcessingStep;
use App\Enums\ServiceStructureMode;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Services\Processing\ProcessingArtifactReuse;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ServiceArtifactDisk;
use App\Support\TranscriptPromptEchoDetector;
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
 *
 * @phpstan-import-type SuspectTranscriptBlockShape from \App\Data\SuspectTranscriptBlock
 */
class TranscribeFullService extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    /**
     * @param  bool  $mayReuseStoredTranscript  True only when this dispatch is resuming a
     *                                          failed run, where re-running the step was
     *                                          never the point. Defaults to false so a
     *                                          fresh run, and a deliberate re-run with a
     *                                          different model, always transcribe.
     */
    public function __construct(
        private MediaProcessingLog $processingLog,
        private bool $mayReuseStoredTranscript = false,
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
        ServiceTranscriptionInterface $transcriptionService,
        TranscriptPromptEchoDetector $promptEchoDetector,
        ServiceTranscriptRecovery $transcriptRecovery,
        ProcessingArtifactReuse $artifactReuse,
        ServiceTranscriptRepetitionScreen $repetitionScreen,
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

        /**
         * Resuming a run whose stored transcript is still intact does not need
         * the recording transcribed again — this is the most expensive step in
         * the pipeline, and the recording it describes has not changed.
         *
         * Only a retry may do this, and only because the caller said so. A
         * re-run is otherwise *expected* to re-transcribe: WP-A2 requires that
         * a better model can be run over a recording that already has a
         * transcript, so reuse can never be inferred from the artifact's mere
         * presence. {@see ProcessingRunOrchestrator::retryWithChain()} is the
         * one caller that sets this, because a resume is the one case where
         * re-running the step was not the point of dispatching it.
         *
         * Stricter than the source-unavailable fallback below: there a stored
         * transcript is the only evidence left and a weak one beats nothing.
         * Here the source is present, so anything short of a transcript with
         * cues is better re-transcribed than adopted.
         */
        if ($this->mayReuseStoredTranscript && $artifactReuse->serviceTranscriptIsUsable($this->processingLog)) {
            $this->filterStoredTranscript($promptEchoDetector, $repetitionScreen);
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                'Reused the full-service transcript already recorded for this run'
            );

            return;
        }

        try {
            [$localSourcePath, $cleanupSourcePath] = $this->resolveLocalSourceVideoPath($storageHelper);
        } catch (\RuntimeException $exception) {
            // Reclassification of a completed run: the temp source video is
            // usually long cleaned up, but the stored transcript survives
            // cleanup for exactly this purpose — the recording has not
            // changed, so it is still valid evidence for detection.
            if ($this->hasStoredTranscript()) {
                $this->filterStoredTranscript($promptEchoDetector, $repetitionScreen);
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

            $filteredTranscript = $this->filterTranscript($transcript, $promptEchoDetector);
            $recoveredTranscript = $transcriptRecovery->recover(
                $filteredTranscript,
                $localSourcePath,
                $this->processingLog->processing_id,
            );

            $transcriptPath = app(ServiceArtifactStorage::class)->putJson(
                $this->processingLog->processing_id,
                'normalized',
                $recoveredTranscript->toArray(),
            );

            $this->processingLog->putServiceTranscriptPath(
                $transcriptPath,
                $recoveredTranscript->unobservableWindows,
                $this->screenedBlocks($recoveredTranscript, $repetitionScreen),
            );

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
                sprintf('Transcribed %d cue(s) covering %.0fs', count($recoveredTranscript->cues), $recoveredTranscript->duration)
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

    private function filterStoredTranscript(
        TranscriptPromptEchoDetector $detector,
        ServiceTranscriptRepetitionScreen $repetitionScreen,
    ): void {
        $path = $this->processingLog->serviceTranscriptPath();
        if ($path === null) {
            return;
        }

        $artifactDisk = ServiceArtifactDisk::for($path);
        $transcript = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk($artifactDisk)->get($path), true)
        );

        $filtered = $this->filterTranscript($transcript, $detector);

        Storage::disk($artifactDisk)->put(
            $path,
            json_encode($filtered->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        // Reuse is the branch that most needs the screen recorded. A resumed or
        // reclassified run adopts a transcript this code has never screened —
        // for historic runs, one decoded before the screen existed — and would
        // otherwise carry the reused transcript forward with no record of what
        // it is worth.
        $this->processingLog->putServiceTranscriptPath(
            $path,
            $filtered->unobservableWindows,
            $this->screenedBlocks($filtered, $repetitionScreen),
        );
    }

    /**
     * What the repetition screen makes of the transcript this run will hand on.
     *
     * Recorded here rather than at the sermon step so structure detection and
     * analysis both run with it already on the row: the loop is in the
     * full-service transcript, and by the time a sermon section exists the
     * detector has already read the looping text as though it were speech.
     *
     * @return list<SuspectTranscriptBlockShape>
     */
    private function screenedBlocks(
        ChurchServiceTranscript $transcript,
        ServiceTranscriptRepetitionScreen $repetitionScreen,
    ): array {
        return array_map(
            static fn (SuspectTranscriptBlock $block): array => $block->toArray(),
            $repetitionScreen->screen($transcript),
        );
    }

    private function filterTranscript(
        ChurchServiceTranscript $transcript,
        TranscriptPromptEchoDetector $detector,
    ): ChurchServiceTranscript {
        return ChurchServiceTranscript::fromCues(
            array_values(array_filter(
                $transcript->cues,
                fn (array $cue): bool => ! $detector->isPromptEcho($cue['text'])
            )),
            $transcript->duration,
            $transcript->source,
            $transcript->unobservableWindows,
        );
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
