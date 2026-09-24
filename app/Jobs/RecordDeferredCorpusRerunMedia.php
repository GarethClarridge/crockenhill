<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * End a corpus re-run detection round by recording what would be cut, without cutting it
 * (plan §4.0).
 *
 * Two verdicts the full chain reaches only after media is cut do not need the media:
 *
 * - the sermon's extraction plan, including a held sermon's refusal to be cut, which
 *   {@see SermonExtractionPlanResolver} resolves from sections alone; it is written to the run's
 *   corpus re-run stamp, never to `sermon_extraction_plan`, which records what *was* cut;
 * - each song's publication review, which {@see SongPublicationHandler::requiresApproval()}
 *   decides from the span, the song link and banked boundary evidence, and records on the
 *   section; publication status is left alone, because no clip exists to publish.
 *
 * Everything that needs media (talk speaker review, sermon text and audio checks, video
 * quality) is decided when the frozen commit's re-extraction cuts it.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RecordDeferredCorpusRerunMedia implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

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
            (new WithoutOverlapping('record-deferred-corpus-rerun-media-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(SermonExtractionPlanResolver $planResolver, SectionPublicationHandlerFactory $handlers): void
    {
        $run = $this->processingLog->fresh();

        if (! $run instanceof MediaProcessingLog || $run->isCancelled()) {
            return;
        }

        $songsHeld = 0;

        ServiceSection::query()
            ->where('media_processing_log_id', $run->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get()
            ->each(function (ServiceSection $section) use ($handlers, &$songsHeld): void {
                $handler = $handlers->forSection($section);

                if (! $handler instanceof SongPublicationHandler || ! $handler->isEligible($section)) {
                    return;
                }

                if ($handler->requiresApproval($section)) {
                    $songsHeld++;
                }

                if ($section->isDirty()) {
                    $section->save();
                }
            });

        $run->amendLatestCorpusRerunStamp([
            'deferred_extraction_plan' => $this->extractionPlan($run, $planResolver),
            'media_recorded_at' => now()->toIso8601String(),
        ]);

        Log::info('Recorded a corpus re-run round without cutting media', [
            'processing_id' => $run->processing_id,
            'songs_held_for_review' => $songsHeld,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Recording a corpus re-run round without media failed', [
            'processing_log_id' => $this->processingLog->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * The plan re-extraction would cut, in the shape `sermon_extraction_plan` records, or why
     * none can be resolved. A service without a sermon has no plan, as extraction would find.
     *
     * @return array<string, mixed>
     */
    private function extractionPlan(MediaProcessingLog $run, SermonExtractionPlanResolver $planResolver): array
    {
        if ($run->assertedSermonAbsence() !== null) {
            return ['reason' => 'sermon_absent', 'segments' => []];
        }

        try {
            $plan = $planResolver->resolve($run);
        } catch (\Throwable $exception) {
            return ['reason' => 'unresolved', 'error' => $exception->getMessage(), 'segments' => []];
        }

        return [
            'source' => $plan['source'],
            'mode' => $plan['mode'],
            'strategy' => $plan['metadata']['strategy'] ?? null,
            'reason' => $plan['metadata']['reason'] ?? null,
            'sermon_boundary' => $plan['metadata']['sermon_boundary'] ?? null,
            'segments' => $plan['segments'],
        ];
    }
}
