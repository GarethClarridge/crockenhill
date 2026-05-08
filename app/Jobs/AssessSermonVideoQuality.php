<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\SermonExposurePolicy;
use App\Services\SermonVideoQualityAssessmentService;
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

        $localVideoPath = null;

        try {
            $this->logStepStart('assessing_video_quality', 'Assessing sermon video quality');

            $disk = (string) config('media-processing.storage.sermon_disk', 'public');

            ['result' => $result, 'localVideoPath' => $localVideoPath] = $assessmentService->assessAndRetainLocalPath(
                sermon: $sermon,
                videoPath: $sermon->video_file_path,
                disk: $disk,
            );

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
            return Sermon::find($this->sermonId);
        }

        if (! $processingLog instanceof MediaProcessingLog || $processingLog->sermon_id === null) {
            return null;
        }

        $this->sermonId = $processingLog->sermon_id;

        return Sermon::find($processingLog->sermon_id);
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
