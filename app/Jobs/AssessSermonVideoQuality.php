<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\MediaDiskReachability;
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

    public int $timeout = 300;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        private ?MediaProcessingLog $processingLog = null,
        private ?int $sermonId = null,
    ) {}

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

        $disk = $sermon->assetDisk();

        /**
         * An unreachable disk answers every read exactly as a deleted file
         * would, so assessing across one manufactures `missing_video_file`
         * verdicts for assets that are present and fine. Hold the existing
         * state instead: nothing is written, and the sermon stays eligible for
         * the same backfill once the volume is back.
         */
        $unreachableReason = $diskReachability->unreachableReason($disk);

        if ($unreachableReason !== null) {
            Log::warning('Sermon video quality assessment deferred: owning disk unreachable', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $sermon->id,
                'disk' => $disk,
                'reason' => $unreachableReason,
                'retained_status' => $sermon->videoQualityStatus()->value,
            ]);

            return;
        }

        $localVideoPath = null;

        try {
            $this->logStepStart('assessing_video_quality', 'Assessing sermon video quality');

            ['result' => $result, 'localVideoPath' => $localVideoPath] = $assessmentService->assessAndRetainLocalPath(
                sermon: $sermon,
                videoPath: $sermon->video_file_path,
                disk: $disk,
            );

            if ($this->wouldDiscardSettledEvidence($sermon, $result)) {
                Log::warning('Sermon video quality assessment held: file unreadable but a settled verdict exists', [
                    'processing_id' => $processingLog?->processing_id,
                    'sermon_id' => $sermon->id,
                    'disk' => $disk,
                    'video_path' => $sermon->video_file_path,
                    'retained_status' => $sermon->videoQualityStatus()->value,
                ]);

                $this->logStepComplete('assessing_video_quality', 'Video quality assessment held: evidence unreadable');

                return;
            }

            $this->persistResult($sermon, $processingLog, $result);
            $this->logStepComplete('assessing_video_quality', 'Video quality assessment completed: '.$result->status->value);

            Log::info('Sermon video quality assessment completed', [
                'processing_id' => $processingLog?->processing_id,
                'sermon_id' => $sermon->id,
                'video_path' => $sermon->video_file_path,
                'verdict' => $result->status->value,
                'reason' => $result->reason,
                'sample_count' => $result->sampleCount,
                'aggregate_score' => $result->aggregateScore,
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
        } catch (\Throwable $e) {
            $result = SermonVideoQualityAssessmentResult::failed();
            $this->persistResult($sermon, $processingLog, $result);
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

    /**
     * Would writing this result replace a real verdict with an access failure?
     *
     * `missing_video_file` says only that the file could not be read on this
     * run. Where an earlier run *did* read it and reached a verdict, the file's
     * later absence is a custody problem -- staging cleaned up, an asset not
     * promoted, a volume swapped -- and the quality judgement it produced is
     * still the best evidence anyone has about that video. Overwriting it
     * destroys that evidence and cannot be undone by re-running: the file is
     * exactly what is missing.
     *
     * So a settled verdict outranks an unreadable file. Thirteen published
     * sermons on this machine sit in that position, their assets long cleaned
     * up; a corpus-wide `--all` pass would otherwise demote eleven approvals to
     * a missing-file state that describes this workstation, not the recording.
     *
     * A sermon that has never been assessed has nothing to lose, so it records
     * the failure as before and stays eligible for the targeted replay.
     */
    private function wouldDiscardSettledEvidence(Sermon $sermon, SermonVideoQualityAssessmentResult $result): bool
    {
        if ($result->reason !== 'missing_video_file') {
            return false;
        }

        return $sermon->videoQualityStatus() !== SermonVideoQualityStatus::Unassessed;
    }

    private function persistResult(
        Sermon $sermon,
        ?MediaProcessingLog $processingLog,
        SermonVideoQualityAssessmentResult $result,
    ): void {
        $sermon->forceFill([
            'video_quality_status' => $result->status,
            'video_quality_reason' => $result->reason,
            'video_quality_assessed_at' => now(),
        ])->save();

        $processingLog?->putVideoQualityMetadata($result->toArray());
    }
}
