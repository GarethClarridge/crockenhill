<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\SongLyricEdgeExtension;
use App\Services\ChurchService\Structure\SongMusicalIntroduction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs {@see SongLyricEdgeExtension} once song matching and continuation merging have settled
 * which song each section holds, and before any clip is cut; then {@see SongMusicalIntroduction},
 * so a start the lyrics moved is walked back over the music that introduces it.
 */
class ExtendSongsOverOwnLyrics implements ShouldQueue
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
            (new WithoutOverlapping('extend-songs-over-own-lyrics-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(SongLyricEdgeExtension $extension, SongMusicalIntroduction $introduction): void
    {
        $extension->extend($this->processingLog);
        $introduction->extend($this->processingLog->fresh() ?? $this->processingLog);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Song lyric edge extension failed', [
            'processing_log_id' => $this->processingLog->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
