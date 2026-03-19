<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MediaType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\LivestreamFailureHandler;
use App\Services\ProcessingPipelineBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class ConfirmLivestreamSermonSegment
{
    public function __construct(
        private readonly ProcessingPipelineBuilder $pipelineBuilder
    ) {}

    /**
     * Confirm a speech segment as the sermon for a livestream run awaiting manual review.
     *
     * Validates all preconditions, persists confirmation metadata, and dispatches
     * the post-review processing chain. Returns the dispatched batch.
     *
     * @throws \InvalidArgumentException When preconditions are not met
     */
    public function execute(string $processingId, int $segmentId, User $user): void
    {
        $queueName = (string) config('media-processing.queues.livestream', 'livestream-processing');

        /** @var array<int, object> $jobs */
        $jobs = DB::transaction(function () use ($processingId, $segmentId, $user): array {
            /** @var MediaProcessingLog|null $log */
            $log = MediaProcessingLog::where('processing_id', $processingId)
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                throw new \InvalidArgumentException('Processing log not found.');
            }

            if ($log->processing_type !== MediaType::Livestream) {
                throw new \InvalidArgumentException('Only livestream runs can be confirmed via manual review.');
            }

            if (! $log->requiresManualSermonReview()) {
                throw new \InvalidArgumentException('This run is not currently awaiting manual sermon review.');
            }

            $segment = LivestreamSegment::where('id', $segmentId)
                ->where('media_processing_log_id', $log->id)
                ->first();

            if ($segment === null) {
                throw new \InvalidArgumentException('Segment not found on this processing run.');
            }

            if (! $segment->isSpeech()) {
                throw new \InvalidArgumentException('Only speech segments may be confirmed as the sermon.');
            }

            $this->ensureSourceVideoExists($log);

            $log->confirmSermonSegment($segmentId, $user->id);
            $log->refresh();

            return $this->pipelineBuilder->buildLivestreamPostReviewChainJobs($log);
        });

        Bus::chain($jobs)
            ->catch(fn (\Throwable $e) => app(LivestreamFailureHandler::class)->handle($processingId, $e))
            ->onQueue($queueName)
            ->dispatch();
    }

    /**
     * @throws \InvalidArgumentException When the source video file is no longer available
     */
    private function ensureSourceVideoExists(MediaProcessingLog $log): void
    {
        if (! is_string($log->source_file_path) || $log->source_file_path === '') {
            throw new \InvalidArgumentException('No source video path recorded for this run. The file may have been removed.');
        }

        if (! $log->sourceVideoExists()) {
            throw new \InvalidArgumentException('The source video file is no longer available. This run cannot be resumed.');
        }
    }
}
