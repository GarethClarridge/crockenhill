<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SafeInvalidArgumentException;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Traits\SanitizesLogData;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfirmLivestreamSermonSegment
{
    use SanitizesLogData;

    public function __construct(
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
        private readonly VideoStorageService $videoStorageService,
    ) {}

    /**
     * Confirm a speech segment as the sermon for a segmentation-style run awaiting manual review.
     *
     * Validates all preconditions, persists confirmation metadata, and dispatches
     * the post-review processing chain. Returns the dispatched batch.
     *
     * @throws SafeInvalidArgumentException When preconditions are not met
     * @throws BindingResolutionException If the orchestrator cannot be resolved
     * @throws \Throwable For unexpected database or orchestration failures
     */
    public function execute(string $processingId, int $segmentId, User $user): void
    {
        $log = DB::transaction(function () use ($processingId, $segmentId, $user): MediaProcessingLog {
            /** @var MediaProcessingLog|null $log */
            $log = MediaProcessingLog::query()
                ->where('processing_id', $processingId)
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                throw new SafeInvalidArgumentException('Processing log not found.');
            }

            if (! $log->canUseManualSermonReview()) {
                throw new SafeInvalidArgumentException('Only segmentation-style runs can be confirmed via manual review.');
            }

            if (! $log->requiresManualSermonReview()) {
                throw new SafeInvalidArgumentException('This run is not currently awaiting manual sermon review.');
            }

            $segment = LivestreamSegment::query()
                ->where('id', $segmentId)
                ->where('media_processing_log_id', $log->id)
                ->first();

            if ($segment === null) {
                throw new SafeInvalidArgumentException('Segment not found on this processing run.');
            }

            if (! $segment->isSpeech()) {
                throw new SafeInvalidArgumentException('Only speech segments may be confirmed as the sermon.');
            }

            $this->ensureSourceVideoExists($log);

            $this->processingRunTransitions->confirmSermonSegment($log, $segmentId, $user->id);
            $log->refresh();

            Log::warning('Livestream sermon segment confirmed by admin', $this->sanitizeArrayForLog([
                'admin_id' => $user->id,
                'processing_id' => $processingId,
                'segment_id' => $segmentId,
                'original_filename' => (string) $log->original_filename,
            ]));

            return $log;
        });

        // Resolve the orchestrator after the transaction commits so we do not
        // hold a container-resolved collaborator across the DB lock boundary.
        app(ProcessingRunOrchestrator::class)->resumeAfterManualReview($log);
    }

    /**
     * @throws SafeInvalidArgumentException When the source video file is no longer available
     */
    private function ensureSourceVideoExists(MediaProcessingLog $log): void
    {
        if (! is_string($log->source_file_path) || $log->source_file_path === '') {
            throw new SafeInvalidArgumentException('No source video path recorded for this run. The file may have been removed.');
        }

        if (! $this->videoStorageService->sourceVideoExistsForPath($log->source_file_path)) {
            throw new SafeInvalidArgumentException('The source video file is no longer available. This run cannot be resumed.');
        }
    }
}
