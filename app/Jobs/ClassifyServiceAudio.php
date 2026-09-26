<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStep;
use App\Enums\ServiceStructureMode;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioClassifier;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Processing\ProcessingArtifactReuse;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ServiceArtifactDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where the service recording holds music and speech, for structure detection to read.
 *
 * Whisper cannot hear congregational singing, and the RMS level cannot tell a leader at a
 * microphone from it, so detection guessed at songs from the announcement (canary 4, run 1304).
 * This runs the audio classifier over the run's archived service audio and stores the timeline
 * beside the RMS log. `DetectServiceStructure` refuses a run without one.
 *
 * The input is the `audio` artifact `TranscribeFullService` archives, not the source recording:
 * historic sources may be cleaned up, and the corpus backfill must classify exactly the audio a
 * fresh run would. The timeline must describe the same audio as the RMS log, so a duration that
 * disagrees with the log's by more than one window is refused.
 */
#[FailOnTimeout]
class ClassifyServiceAudio extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ARTIFACT_KIND = 'classes';

    public int $tries = 2;

    public int $timeout = AudioClassifier::TIMEOUT_SECONDS + 300;

    /**
     * @param  bool  $mayReuseRecordedTimeline  True only when this dispatch is resuming a failed
     *                                          run, as for {@see GenerateRmsLog}
     */
    public function __construct(
        private MediaProcessingLog $processingLog,
        private bool $mayReuseRecordedTimeline = false,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('classify-service-audio-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        AudioClassifier $classifier,
        ProcessingArtifactReuse $artifactReuse,
        RmsAnalysisService $rmsAnalysis,
        ServiceArtifactStorage $artifactStorage,
        StorageAdapterHelper $storageHelper,
    ): void {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        if (! $this->processingLog->usesSegmentationPipeline()) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO,
                'Audio classification only runs for segmentation pipelines'
            );

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::ClassifyServiceAudio->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO);

        if ($this->mayReuseRecordedTimeline && $artifactReuse->audioTimelineIsUsable($this->processingLog)) {
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO,
                'Reused the audio timeline already recorded for this run'
            );

            return;
        }

        try {
            $path = $this->classify($classifier, $rmsAnalysis, $artifactStorage, $storageHelper);
        } catch (\Throwable $throwable) {
            Log::error('Service audio classification failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $throwable->getMessage(),
            ]);

            // Shadow runs are evaluation-only, as for full-service transcription.
            if (ServiceStructureMode::fromConfig() === ServiceStructureMode::Shadow) {
                $this->logStepSkipped(
                    ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO,
                    'Shadow audio classification failed: '.$throwable->getMessage()
                );

                return;
            }

            throw $throwable;
        }

        $this->logStepComplete(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO, "Audio timeline recorded at {$path}");
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_AUDIO, $exception->getMessage());
    }

    /**
     * Classify the recorded audio, check it against the RMS log and store it. Shared with the
     * corpus backfill so both classify exactly the same input the same way.
     *
     * @return string The recorded timeline path
     *
     * @throws RuntimeException When there is no audio to classify or the result disagrees with the RMS log
     */
    public function classify(
        AudioClassifier $classifier,
        RmsAnalysisService $rmsAnalysis,
        ServiceArtifactStorage $artifactStorage,
        StorageAdapterHelper $storageHelper,
    ): string {
        $audio = self::recordedAudio($this->processingLog);

        if ($audio === null) {
            throw new RuntimeException('No archived service audio is recorded for this run; TranscribeFullService archives it.');
        }

        $rmsEnd = $this->rmsLogEnd($rmsAnalysis);

        if (! Storage::disk($audio['disk'])->exists($audio['path'])) {
            throw new RuntimeException("Archived service audio is missing: {$audio['disk']}:{$audio['path']}");
        }

        $isDownloaded = $storageHelper->isS3CompatibleDisk(Storage::disk($audio['disk']));
        $localPath = $storageHelper->downloadToTemp($audio['path'], $audio['disk'], 'local', 'temp/audio-classification');

        try {
            $payload = $classifier->classify($localPath);
        } finally {
            if ($isDownloaded) {
                $storageHelper->cleanupTempFile($localPath);
            }
        }

        $timeline = AudioTimeline::fromArray($payload);

        if (abs($timeline->audioSeconds - $rmsEnd) > $timeline->windowSeconds) {
            throw new RuntimeException(sprintf(
                'Classified audio lasts %.1fs but the RMS log ends at %.1fs: they do not describe the same recording.',
                $timeline->audioSeconds,
                $rmsEnd,
            ));
        }

        $path = $artifactStorage->putJson($this->processingLog->processing_id, self::ARTIFACT_KIND, $payload, [
            'model_revision' => $timeline->modelRevision,
            'input_sha256' => $payload['input_sha256'],
        ]);

        // `putJson()` recorded the artifact through its own copy of the row; saving this job's
        // stale copy over it would drop the entry (the lost update 0be07a053 fixed for transcripts).
        $this->processingLog->refresh();
        $this->processingLog->forceFill(['audio_timeline_path' => $path])->save();

        return $path;
    }

    /**
     * The run's archived service audio: the latest `audio` artifact it recorded.
     *
     * @return array{disk: string, path: string}|null
     */
    public static function recordedAudio(MediaProcessingLog $processingLog): ?array
    {
        $audio = null;

        foreach (ServiceArtifactStorage::recordedFor($processingLog) as $artifact) {
            if ($artifact['kind'] === 'audio') {
                $audio = ['disk' => $artifact['disk'], 'path' => $artifact['path']];
            }
        }

        return $audio;
    }

    private function rmsLogEnd(RmsAnalysisService $rmsAnalysis): float
    {
        $path = $this->processingLog->rms_log_path;

        if (! is_string($path) || $path === '' || ! Storage::disk(ServiceArtifactDisk::for($path))->exists($path)) {
            throw new RuntimeException('No RMS log is recorded for this run; GenerateRmsLog must run first.');
        }

        $samples = $rmsAnalysis->extractRmsData((string) Storage::disk(ServiceArtifactDisk::for($path))->get($path));

        if ($samples === []) {
            throw new RuntimeException('The RMS log holds no frames to check the classified duration against.');
        }

        return $samples[count($samples) - 1]['time'];
    }
}
