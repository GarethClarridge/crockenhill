<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\SongContinuationMerger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MergeSongContinuations implements ShouldQueue
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
            (new WithoutOverlapping('merge-song-continuations-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(SongContinuationMerger $merger): void
    {
        $merger->merge($this->processingLog, conservative: true);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Song continuation merge failed', [
            'processing_log_id' => $this->processingLog->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
