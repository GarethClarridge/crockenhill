<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Exceptions\RecordedVideoOutputMismatch;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\MediaDiskReachability;
use App\Services\Media\RecordedVideoOutput;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssessSermonVideoQuality extends ProcessingJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        private ?MediaProcessingLog $processingLog = null,
        private ?int $sermonId = null,
    ) {
        $this->timeout = max(1, (int) config('media-processing.video_quality.probe.timeout_seconds', 900)) + 300;
        $this->uniqueFor = max($this->uniqueFor, $this->timeout + 120);
    }

    public function handle(
        SermonVideoQualityAssessmentService $assessmentService,
        FrameExtractionService $frameExtractionService,
        SermonExposurePolicy $exposurePolicy,
        MediaDiskReachability $diskReachability,
    ): void {
        $startedAt = microtime(true);
        $processingLog = $this->processingLog?->fresh();

        if ($processingLog instanceof MediaProcessingLog) {
            $this->processingLog = $processingLog;
            $this->sermonId ??= $processingLog->sermon_id;
            $this->initializeStepLogging($processingLog->processing_id);

            if ($this->isCancelled()) {
                Log::info('AssessSermonVideoQuality job cancelled', [
                    'processing_id' => $processingLog->processing_id,
                ]);

                return;
            }
        }

        if (! (bool) config('media-processing.video_quality.enabled', true)) {
            Log::info('Sermon video quality assessment skipped: feature disabled', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $this->sermonId,
            ]);

            return;
        }

        $sermon = $this->resolveSermon($processingLog);

        if (! $sermon instanceof Sermon) {
            Log::warning('AssessSermonVideoQuality: sermon not found', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $this->sermonId,
            ]);

            return;
        }

        if (! $sermon->hasVideo()) {
            Log::info('Sermon video quality assessment skipped: sermon has no video', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $sermon->id,
            ]);

            return;
        }

        $outputRun = $processingLog ?? $this->owningRun($sermon);
        $outputs = app(RecordedVideoOutput::class);
        $output = null;
        $localVideoPath = null;

        try {
            if (! $outputRun instanceof MediaProcessingLog) {
                throw new RecordedVideoOutputMismatch('recorded_video_output_missing');
            }
            $output = $outputs->verified($outputRun, 'sermon');
            if ($diskReachability->unreachableReason($output['disk']) !== null) {
                throw new RecordedVideoOutputMismatch('recorded_video_disk_unreachable');
            }
            $this->logStepStart('assessing_video_quality', 'Assessing sermon video quality');

            ['result' => $result, 'localVideoPath' => $localVideoPath] = $assessmentService->assessAndRetainLocalPath(
                sermon: $sermon,
                videoPath: $output['path'],
                disk: $output['disk'],
            );
            if ($result->reason === 'missing_video_file') {
                throw new RecordedVideoOutputMismatch('recorded_video_file_missing');
            }
            $outputs->verified($outputRun->fresh() ?? $outputRun, 'sermon');
            $this->persistResult($sermon, $outputRun, $result, $output);
            $this->logStepComplete('assessing_video_quality', 'Video quality assessment completed: '.$result->status->value);

            Log::info('Sermon video quality assessment completed', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $sermon->id,
                'video_path' => $output['path'],
                'asset_disk' => $output['disk'],
                'sha256' => $output['sha256'],
                'size' => $output['size'],
                'output_provenance' => $output['provenance'],
                'verdict' => $result->status->value,
                'reason' => $result->reason,
                'dead_seconds' => $result->deadSeconds,
                'usable_share' => $result->usableShare,
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // If thumbnail generation will run next, keep the local temp file and pass the path
            // forward via processing_metadata so GenerateThumbnail avoids a second S3 download.
            if ($localVideoPath !== null && $processingLog !== null && $exposurePolicy->shouldGenerateVideoThumbnail($sermon)) {
                $processingLog->update([
                    'processing_metadata' => array_merge(
                        $processingLog->processing_metadata?->toArray() ?? [],
                        ['cached_local_video_path' => $localVideoPath],
                    ),
                ]);
                $localVideoPath = null; // GenerateThumbnail now owns cleanup
            }
        } catch (RecordedVideoOutputMismatch $exception) {
            $this->persistResult($sermon, $outputRun, SermonVideoQualityAssessmentResult::failed($exception->reason), null);
            $this->logStepFailed('assessing_video_quality', $exception->reason);
            Log::error('Sermon video quality assessment refused: recorded output invalid', [
                'processing_id' => $outputRun?->processing_id,
                'sermon_id' => $sermon->id,
                'reason' => $exception->reason,
                'recorded_output' => $output,
            ]);

            throw $exception;
        } catch (\Throwable $e) {
            $result = SermonVideoQualityAssessmentResult::failed();
            $this->persistResult($sermon, $outputRun, $result, $output);
            $this->logStepComplete('assessing_video_quality', 'Video quality assessment failed safely');

            Log::warning('Sermon video quality assessment failed safely', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $sermon->id,
                'video_path' => $sermon->video_file_path,
                'error' => $e->getMessage(),
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } finally {
            // Clean up only if we still own the file (thumbnail generation won't run)
            $frameExtractionService->cleanupDownloadedVideo($localVideoPath);
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        $id = $this->sermonId ?? $this->processingLog?->sermon_id;

        return (string) ($id ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'video-quality-assessment',
            'sermon:'.$this->sermonId,
            'non-critical',
        ];
    }

    private function resolveSermon(?MediaProcessingLog $processingLog): ?Sermon
    {
        if ($this->sermonId !== null) {
            return Sermon::query()->find($this->sermonId);
        }

        if (! $processingLog instanceof MediaProcessingLog || $processingLog->sermon_id === null) {
            return null;
        }

        $this->sermonId = $processingLog->sermon_id;

        return Sermon::query()->find($processingLog->sermon_id);
    }

    /** @param array<string, mixed>|null $output */
    private function persistResult(
        Sermon $sermon,
        ?MediaProcessingLog $processingLog,
        SermonVideoQualityAssessmentResult $result,
        ?array $output,
    ): void {
        $sermon->forceFill([
            'video_quality_status' => $result->status,
            'video_quality_reason' => $result->reason,
            'video_quality_assessed_at' => now(),
        ])->save();

        ($processingLog ?? $this->owningRun($sermon))?->putVideoQualityMetadata([
            ...$result->toArray(),
            'asset_disk' => $output['disk'] ?? null,
            'video_path' => $output['path'] ?? null,
            'sha256' => $output['sha256'] ?? null,
            'size' => $output['size'] ?? null,
            'graded_output' => $output,
        ]);
    }

    /**
     * The run that published the sermon, for a verdict reached outside the
     * pipeline.
     *
     * `sermons:assess-video-quality` dispatches with a sermon id alone, and the
     * 13 historic verdicts written that way left their evidence in laravel.log
     * only. It is used for the record alone: the thumbnail handoff above still
     * keys on the pipeline's own run, because no thumbnail job follows a
     * command run to clean up the local copy.
     */
    private function owningRun(Sermon $sermon): ?MediaProcessingLog
    {
        $publishedRunId = $sermon->publishedServiceSection?->media_processing_log_id;

        if ($publishedRunId !== null) {
            return MediaProcessingLog::query()->find($publishedRunId);
        }

        return $sermon->livestreamProcessing ?? $sermon->latestProcessingLog;
    }
}
