<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\ServiceStructure;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\AudioTimeline;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What a run banked for the sound stage to be replayed over: its structure, RMS log and audio
 * timeline, each read inside the run's own staging context.
 *
 * **Every read happens inside that context.** H10a lost a pass to this: reading from the ambient
 * disk reports every run unavailable, which is indistinguishable from a corpus with nothing to
 * find. Each reader returns null for an input it cannot read, so a caller reports the run
 * unassessable, never clean.
 */
class BankedRunInputs
{
    public function __construct(private readonly HistoricStagingContextRegistry $stagingContexts) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function within(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        if ($context === null) {
            return $callback();
        }

        return $this->stagingContexts->within($context, $callback);
    }

    public function structure(MediaProcessingLog $run): ?ServiceStructure
    {
        $banked = data_get($run->processing_metadata?->toArray() ?? [], 'service_structure');

        return is_array($banked) ? ServiceStructure::fromArray($banked) : null;
    }

    public function rmsLog(MediaProcessingLog $run): ?string
    {
        return $this->artifact($run->rms_log_path);
    }

    public function audioTimeline(MediaProcessingLog $run): ?AudioTimeline
    {
        $json = $this->artifact($run->audio_timeline_path);

        if ($json === null) {
            return null;
        }

        try {
            return AudioTimeline::fromJson($json);
        } catch (Throwable) {
            return null;
        }
    }

    private function artifact(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));

            return $disk->exists($path) ? (string) $disk->get($path) : null;
        } catch (Throwable) {
            // An unreadable artifact is unassessable, never clean.
            return null;
        }
    }
}
