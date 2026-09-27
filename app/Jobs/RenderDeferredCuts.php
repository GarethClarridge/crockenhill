<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ExtractForCorpusRerun;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use App\Services\Media\Video\VideoExtractionService;
use App\Support\MediaAssetPath;
use App\Support\WorkerCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Re-encode the videos a corpus re-run's Tier C cut with their render deferred (plan §4.0,
 * "cut now, render later").
 *
 * Tier C smart-cuts a source above the bitrate threshold, so its sermon video, song videos and
 * held candidates sit at the source's bitrate until this renders them from the stored cuts. It
 * reads the run's videos the way promotion does ({@see HistoricAssetPromotion}), wherever they
 * now live, and needs neither the source nor its staging context.
 *
 * Every file is looked for before any is rendered, because a detached drive reads like missing
 * media. The stamp says `rendered` only once every file has been, so a failure leaves the run
 * deferred for release to refuse and the operator to re-dispatch; files already rendered are
 * then skipped by {@see VideoExtractionService::renderForDelivery()}.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RenderDeferredCuts implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A failed render is re-dispatched by the operator, once they know why it failed. */
    public int $tries = 1;

    public int $timeout = 7200;

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
            (new WithoutOverlapping('render-deferred-cuts-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(HistoricAssetPromotion $assets, VideoExtractionService $extractor): void
    {
        $run = $this->processingLog->fresh();

        if (! $run instanceof MediaProcessingLog || ! $run->defersCorpusRerunRender()) {
            return;
        }

        $videos = $this->videoPaths($run, $assets);

        foreach ($videos as ['disk' => $disk, 'path' => $path]) {
            if (! Storage::disk($disk)->exists($path)) {
                throw new RuntimeException("Run {$run->processing_id}'s video {$path} is missing on {$disk}; nothing was rendered. Check the drive is attached.");
            }
        }

        $rendered = 0;

        foreach ($videos as ['disk' => $disk, 'path' => $path]) {
            if ($extractor->renderForDelivery(Storage::disk($disk)->path($path))) {
                $rendered++;
            }
        }

        $counts = [
            'videos' => count($videos),
            'rendered' => $rendered,
            'already_deliverable' => count($videos) - $rendered,
        ];

        $run->amendLatestCorpusRerunStamp([
            'render' => ExtractForCorpusRerun::RENDER_RENDERED,
            'rendered_at' => now()->toIso8601String(),
            'render_counts' => $counts,
            'render_worker_commit' => WorkerCode::bootCommit(),
        ]);

        Log::info('Rendered a corpus re-run\'s deferred cuts', [
            'processing_id' => $run->processing_id,
            ...$counts,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Rendering a corpus re-run\'s deferred cuts failed; the run stays deferred', [
            'processing_log_id' => $this->processingLog->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Each distinct video file the run owns. A published song names its song video's file
     * from its section too, so files are keyed by disk and path.
     *
     * @return list<array{disk: string, path: string}>
     */
    private function videoPaths(MediaProcessingLog $run, HistoricAssetPromotion $assets): array
    {
        $videos = [
            ...$assets->sermonsForRun($run)->map(static fn (Sermon $sermon): array => [
                'disk' => $sermon->assetDisk(MediaAssetPath::disk()),
                'path' => $sermon->video_file_path,
            ])->all(),
            ...$assets->songVideosForRun($run)->map(static fn (SongVideo $video): array => [
                'disk' => filled($video->asset_disk) ? (string) $video->asset_disk : MediaAssetPath::disk(),
                'path' => $video->video_file_path,
            ])->all(),
            ...$assets->heldSectionCandidatesForRun($run)->map(static fn (ServiceSection $section): array => [
                'disk' => $section->extractedAssetDisk(),
                'path' => $section->extracted_video_path,
            ])->all(),
        ];

        $distinct = [];

        foreach ($videos as $video) {
            if (! is_string($video['path']) || $video['path'] === '') {
                continue;
            }

            $distinct["{$video['disk']}|{$video['path']}"] = ['disk' => $video['disk'], 'path' => $video['path']];
        }

        return array_values($distinct);
    }
}
