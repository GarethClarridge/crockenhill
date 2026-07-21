<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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

    public function handle(TranscriptStorageService $transcriptStorage): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        $this->logStepStart('creating_sermon_transcript');
        $this->updateProcessingRunStep($this->processingLog, 'creating_sermon_transcript');

        $sermon = $this->processingLog->sermon;
        if (! $sermon instanceof Sermon) {
            throw new \RuntimeException("No sermon found for processing log: {$this->processingLog->processing_id}");
        }

        $transcript = $this->loadServiceTranscript();
        $sermonText = trim($transcript->sliceText($this->sermonStartTime(), $this->sermonEndTime()));

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

    private function loadServiceTranscript(): ChurchServiceTranscript
    {
        $transcriptPath = $this->processingLog->serviceTranscriptPath();
        if ($transcriptPath === null) {
            throw new \RuntimeException('No full-service transcript recorded for this run.');
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        if (! Storage::disk($tempDisk)->exists($transcriptPath)) {
            throw new \RuntimeException('The recorded full-service transcript is unavailable.');
        }

        /** @var array<string, mixed> $transcriptData */
        $transcriptData = json_decode((string) Storage::disk($tempDisk)->get($transcriptPath), true, 512, JSON_THROW_ON_ERROR);

        return ChurchServiceTranscript::fromArray($transcriptData);
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
