<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateSermonTranscriptFromService extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private MediaProcessingLog $processingLog,
    ) {}

    public function handle(
        TranscriptStorageService $transcriptStorage,
        ServiceTranscriptReader $serviceTranscripts,
    ): void {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        $this->logStepStart('creating_sermon_transcript');
        $this->updateProcessingRunStep($this->processingLog, 'creating_sermon_transcript');

        $sermon = $this->processingLog->sermon;
        if (! $sermon instanceof Sermon) {
            throw new \RuntimeException("No sermon found for processing log: {$this->processingLog->processing_id}");
        }

        $transcript = $serviceTranscripts->read($this->processingLog);
        $sermonText = trim($transcript->sliceTextForSpans($this->extractedSpans()));

        if ($sermonText === '') {
            throw new \RuntimeException('The full-service transcript contains no sermon text for the extracted bounds.');
        }

        $transcriptPath = $transcriptStorage->storeTranscript($sermon->id, $sermonText);

        $this->processingLog->update(['transcript_file_path' => $transcriptPath]);
        $sermon->update(['transcript_file_path' => $transcriptPath]);

        $this->updateProcessingRunStep($this->processingLog, 'transcription_completed');
        $this->logStepComplete('creating_sermon_transcript', 'Created sermon transcript from full-service transcript');
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed('creating_sermon_transcript', $exception->getMessage());
    }

    /**
     * The source spans the sermon media was cut from, so the transcript
     * describes the recording a listener actually gets.
     *
     * A concatenated extraction joins the preached reading to the sermon and
     * drops the hymn or notices between them; slicing the outer bounds instead
     * banks that intervening material as sermon text and passes it to the
     * analysis that follows. Runs with no recorded plan — older runs, and any
     * whose plan holds no usable span — keep the recorded bounds.
     *
     * @return list<array{start: float, end: float}>
     */
    private function extractedSpans(): array
    {
        $spans = $this->processingLog->recordedSermonExtractionSpans();

        if ($spans !== null) {
            return $spans;
        }

        return [['start' => $this->sermonStartTime(), 'end' => $this->sermonEndTime()]];
    }

    private function sermonStartTime(): float
    {
        if ($this->processingLog->sermon_start_time === null) {
            throw new \RuntimeException('No sermon start time recorded for this run.');
        }

        return (float) $this->processingLog->sermon_start_time;
    }

    private function sermonEndTime(): float
    {
        if ($this->processingLog->sermon_end_time === null) {
            throw new \RuntimeException('No sermon end time recorded for this run.');
        }

        return (float) $this->processingLog->sermon_end_time;
    }
}
