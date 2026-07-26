<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\LivestreamChurchServiceProjectionService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProjectLivestreamServiceStructure extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @param  bool  $refining  Whether this is the pass that runs after song matching.
     *                          The earlier pass anchors on automated title text alone,
     *                          so its merge findings are provisional and must not set
     *                          review state this pass would otherwise be able to clear.
     */
    public function __construct(
        private MediaProcessingLog $processingLog,
        private bool $refining = true,
    ) {}

    public function handle(LivestreamChurchServiceProjectionService $projectionService): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog)) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE, 'Livestream projection only runs for active livestream processing');

            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
                'Livestream projection only runs for active livestream processing'
            );

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::ProjectLivestreamServiceStructure->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE);

        $result = $projectionService->project($this->processingLog, $this->refining);

        if (! $result['projected']) {
            $this->logStepSkipped(
                ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
                $result['reason']
            );

            Log::info('Livestream service structure projection skipped', [
                'processing_id' => $this->processingLog->processing_id,
                'reason' => $result['reason'],
                'church_service_id' => $result['church_service_id'],
            ]);

            return;
        }

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
            sprintf(
                'Projected %d item(s) into church service #%d',
                $result['items_projected'],
                $result['church_service_id']
            )
        );
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
            $exception->getMessage()
        );
    }
}
