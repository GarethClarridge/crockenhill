<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RedetectForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\CorpusRerunGuard;
use App\Support\WorkerCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * End a corpus re-run transcription round (plan §4.0, Tier A) by recording the text it wrote.
 *
 * The round stops before detection, so its run joins a Tier B round ({@see RedetectForCorpusRerun})
 * on whichever commit is frozen then. On the same snapshot, that round must tell the change this
 * round made from any other: {@see CorpusRerunGuard} lets through exactly the text recorded here.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RecordCorpusRerunTranscription implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        private readonly MediaProcessingLog $processingLog,
    ) {
        $this->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('record-corpus-rerun-transcription-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(): void
    {
        $run = $this->processingLog->fresh();

        if (! $run instanceof MediaProcessingLog || $run->isCancelled()) {
            return;
        }

        $run->amendLatestCorpusRerunStamp([
            'transcript_sha256' => $run->serviceTranscriptSha256(),
            'transcribed_at' => now()->toIso8601String(),
            // The code that actually ran; the stamp's `git_commit` is the dispatching command's.
            'worker_commit' => WorkerCode::bootCommit(),
            'worker_code_revision' => WorkerCode::bootRevision(),
        ]);

        Log::info('Recorded a corpus re-run transcription round', [
            'processing_id' => $run->processing_id,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Recording a corpus re-run transcription round failed', [
            'processing_log_id' => $this->processingLog->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
