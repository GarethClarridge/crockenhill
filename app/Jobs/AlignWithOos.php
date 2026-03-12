<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\OosAlignmentService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlignWithOos extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(OosAlignmentService $alignmentService): void
    {
        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;
        $this->initializeStepLogging($this->processingLog->processing_id);

        if (
            $this->processingLog->processing_type !== MediaType::Livestream
            || $this->processingLog->isCancelled()
        ) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::ALIGN_WITH_OOS, 'OoS alignment only runs for active livestream processing');

            return;
        }

        $this->logStepStart(ChurchServiceProcessingTimeline::ALIGN_WITH_OOS);

        $result = $alignmentService->alignForProcessingLog($this->processingLog);

        if (! $result['aligned']) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::ALIGN_WITH_OOS, 'No matching order of service available for alignment');
        } else {
            $this->logStepComplete(
                ChurchServiceProcessingTimeline::ALIGN_WITH_OOS,
                sprintf(
                    'Matched %d song section(s); %d review trigger(s)',
                    $result['matched_song_sections'],
                    count($result['review_triggers'])
                )
            );
        }

        Log::info('OoS alignment pass completed', [
            'processing_id' => $this->processingLog->processing_id,
            'aligned' => $result['aligned'],
            'review_triggers' => $result['review_triggers'],
            'matched_song_sections' => $result['matched_song_sections'],
            'unmatched_song_sections' => $result['unmatched_song_sections'],
            'structure_mismatches' => $result['structure_mismatches'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::ALIGN_WITH_OOS,
            $exception->getMessage()
        );

        Log::error('AlignWithOos job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
