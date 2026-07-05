<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\MediaType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\DB;

class LivestreamCreateSermonService
{
    public function __construct(
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
        private readonly ProcessingRunOrchestrator $processingRunOrchestrator,
        private readonly VideoStorageService $videoStorageService,
    ) {}

    /**
     * Resume a livestream processing run using its longest speech segment as the sermon candidate.
     *
     * @return array{processing_id:string,segment_id:int,start_time:float,end_time:float,duration:float}
     *
     * @throws \InvalidArgumentException If the processing log is not found or is in an invalid state
     * @throws \Throwable For unexpected database or orchestration failures
     */
    public function resumeUsingLongestSpeechSegment(string $processingId): array
    {
        $confirmed = DB::transaction(function () use ($processingId): array {
            /** @var MediaProcessingLog|null $processingLog */
            $processingLog = MediaProcessingLog::query()
                ->where('processing_id', $processingId)
                ->lockForUpdate()
                ->first();

            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \InvalidArgumentException("Processing log not found for ID: {$processingId}");
            }

            if ($processingLog->processing_type !== MediaType::Livestream) {
                throw new \InvalidArgumentException('Only livestream runs can be resumed with this command.');
            }

            if (! $processingLog->requiresManualSermonReview()) {
                throw new \InvalidArgumentException(
                    'This command now only resumes livestream runs that are awaiting manual sermon review.'
                );
            }

            $this->ensureSourceVideoExists($processingLog);

            /** @var LivestreamSegment|null $sermonSegment */
            $sermonSegment = $processingLog->segments()
                ->where('classification', LivestreamSegmentClassification::Speech->value)
                ->orderByDesc('duration')
                ->first();

            if (! $sermonSegment instanceof LivestreamSegment) {
                throw new \InvalidArgumentException('No sermon segment found.');
            }

            $this->processingRunTransitions->confirmSermonSegment($processingLog, $sermonSegment->id, null);

            return [
                'processing_id' => $processingLog->processing_id,
                'segment_id' => $sermonSegment->id,
                'start_time' => (float) $sermonSegment->start_time,
                'end_time' => (float) $sermonSegment->end_time,
                'duration' => (float) $sermonSegment->duration,
            ];
        });

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->firstOrFail();

        $this->processingRunOrchestrator->resumeAfterManualReview($processingLog);

        return $confirmed;
    }

    private function ensureSourceVideoExists(MediaProcessingLog $processingLog): void
    {
        if (! is_string($processingLog->source_file_path) || $processingLog->source_file_path === '') {
            throw new \InvalidArgumentException('No source video path recorded for this run. The file may have been removed.');
        }

        if (! $this->videoStorageService->sourceVideoExistsForPath($processingLog->source_file_path)) {
            throw new \InvalidArgumentException('The source video file is no longer available. This run cannot be resumed.');
        }
    }
}
