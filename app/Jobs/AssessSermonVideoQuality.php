<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonVideoQualityAssessmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssessSermonVideoQuality extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private ?MediaProcessingLog $processingLog = null,
        private ?int $sermonId = null,
    ) {}

    public function handle(SermonVideoQualityAssessmentService $assessmentService): void
    {
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

        try {
            $this->logStepStart('assessing_video_quality', 'Assessing sermon video quality');

            $result = $assessmentService->assess(
                sermon: $sermon,
                videoPath: $sermon->video_file_path,
                disk: (string) config('media-processing.storage.sermon_disk', 'public'),
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
        }
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
