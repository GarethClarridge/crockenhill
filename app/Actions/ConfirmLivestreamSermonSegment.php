<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\ProcessingRunOrchestrator;
use App\Services\VideoStorageService;
use App\Traits\SanitizesLogData;
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
     * @throws \InvalidArgumentException When preconditions are not met
     */
    public function execute(string $processingId, int $segmentId, User $user): void
    {
        $log = DB::transaction(function () use ($processingId, $segmentId, $user): MediaProcessingLog {
            /** @var MediaProcessingLog|null $log */
            $log = MediaProcessingLog::where('processing_id', $processingId)
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                throw new \InvalidArgumentException('Processing log not found.');
            }

            if (! $log->canUseManualSermonReview()) {
                throw new \InvalidArgumentException('Only segmentation-style runs can be confirmed via manual review.');
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

            $this->processingRunTransitions->confirmSermonSegment($log, $segmentId, $user->id);
            $log->refresh();

            Log::warning('Livestream sermon segment confirmed by admin', [
                'admin_id' => $user->id,
                'processing_id' => $processingId,
                'segment_id' => $segmentId,
                'original_filename' => $this->sanitizeForLog((string) $log->original_filename),
            ]);

            return $log;
        });

        // Resolve the orchestrator after the transaction commits so we do not
        // hold a container-resolved collaborator across the DB lock boundary.
        app(ProcessingRunOrchestrator::class)->resumeAfterManualReview($log);
    }

    /**
     * @throws \InvalidArgumentException When the source video file is no longer available
     */
    private function ensureSourceVideoExists(MediaProcessingLog $log): void
    {
        if (! is_string($log->source_file_path) || $log->source_file_path === '') {
            throw new \InvalidArgumentException('No source video path recorded for this run. The file may have been removed.');
        }

        if (! $this->videoStorageService->sourceVideoExistsForPath($log->source_file_path)) {
            throw new \InvalidArgumentException('The source video file is no longer available. This run cannot be resumed.');
        }
    }
}
