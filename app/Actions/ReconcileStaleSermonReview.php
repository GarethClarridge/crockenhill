<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles a run left paused at manual sermon review that the structure path
 * has since made redundant: it already detected a high-confidence sermon
 * section, so no human segment pick is needed.
 *
 * These runs predate SermonAutoExtractionPolicy's fix — new runs no longer stall
 * this way. When the source recording survives we resume auto-extraction (the
 * resolver prefers the sermon *section*); when it is gone we cancel the run so
 * the phantom "sermon segment needs selecting" task stops surfacing, leaving the
 * already-detected sermon section in place for the operator.
 */
class ReconcileStaleSermonReview
{
    public function __construct(
        private readonly SermonExtractionPlanResolver $planResolver,
        private readonly MediaProcessingRunTransitionService $transitions,
        private readonly VideoStorageService $videoStorage,
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /**
     * @return 'resumed'|'cleared'|'skipped'
     */
    public function execute(MediaProcessingLog $log, bool $execute): string
    {
        if (! $log->canUseManualSermonReview() || ! $log->requiresManualSermonReview()) {
            return 'skipped';
        }

        if (! $this->planResolver->hasAutoExtractableSermonSection($log)) {
            // A genuine manual selection is still needed — leave it alone.
            return 'skipped';
        }

        $sourceAvailable = is_string($log->source_file_path)
            && $log->source_file_path !== ''
            && $this->videoStorage->sourceVideoExistsForPath($log->source_file_path);

        if (! $execute) {
            return $sourceAvailable ? 'resumed' : 'cleared';
        }

        if (! $sourceAvailable) {
            $this->transitions->markAsCancelled(
                $log,
                'Stale manual sermon review cleared: a detected sermon section already exists and the source recording is no longer available.',
            );

            Log::info('Stale sermon review cleared (source unavailable)', [
                'processing_id' => $log->processing_id,
                'church_service_id' => $log->church_service_id,
            ]);

            return 'cleared';
        }

        $this->transitions->autoResolveManualReviewFromStructure($log);
        $log->refresh();

        Log::info('Stale sermon review auto-resolved from structure section', [
            'processing_id' => $log->processing_id,
            'church_service_id' => $log->church_service_id,
        ]);

        $this->orchestrator->resumeAfterManualReview($log);

        return 'resumed';
    }
}
