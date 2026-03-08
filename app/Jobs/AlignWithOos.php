<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\OosAlignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlignWithOos implements ShouldQueue
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

        if (
            $this->processingLog->processing_type !== MediaType::Livestream
            || $this->processingLog->isCancelled()
        ) {
            return;
        }

        $result = $alignmentService->alignForProcessingLog($this->processingLog);

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
        Log::error('AlignWithOos job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
