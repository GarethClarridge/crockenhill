<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

#[FailOnTimeout]
class TranscribeOutputEdges extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3300;

    public function __construct(private MediaProcessingLog $processingLog)
    {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('output-edge-words-'.$this->processingLog->id))->releaseAfter(30)->expireAfter($this->timeout + 120)];
    }

    public function handle(PrepareOutputEdgeWordTimings $prepare, SermonExtractionPlanResolver $resolver): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->markProcessingRunAsProcessing($this->processingLog, 'transcribe_output_edges');
        $this->logStepStart('transcribe_output_edges');
        $composition = $resolver->compose($this->processingLog);
        $spans = [];
        foreach ($this->processingLog->serviceSections()->orderBy('start_time')->get() as $section) {
            if (in_array($section->id, $composition['selected_section_ids'], true)
                || in_array($section->section_type->value, ['song', 'bible_reading', 'short_talk'], true)) {
                $spans[] = ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time];
            }
        }
        $summary = $prepare->prepare($this->processingLog->fresh() ?? throw new \RuntimeException('Processing run disappeared'), $spans);
        $this->processingLog->refresh();
        $resolver->compose($this->processingLog);
        $this->logStepComplete('transcribe_output_edges', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed('transcribe_output_edges', $exception->getMessage());
    }
}
