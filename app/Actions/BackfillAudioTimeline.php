<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ClassifyServiceAudio;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\AudioClassifier;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Processing\ProcessingArtifactReuse;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Give an existing run the audio timeline a fresh run records (music and silence plan §6.7).
 *
 * Classification goes through {@see ClassifyServiceAudio::classify()}, so a backfilled
 * timeline has the same input, preprocessing, hash and duration check as a fresh one. The input
 * is the run's recorded `audio` artifact and nothing else.
 *
 * Most historic runs lost that record (2026-09-25: 17 of 442 completed runs keep it): the
 * processing-metadata lost update fixed in `0be07a053` saved a stale snapshot over it, though
 * the file was archived. So a run with no `audio` entry is re-attached first, from the exact
 * name {@see ServiceArtifactStorage::archiveAudio()} writes, and only when that file exists and
 * lasts as long as the RMS log says the recording did. Anything else is refused and reported;
 * no run is ever classified from a different file.
 *
 * Create-once: a run whose timeline is already usable is left alone, so the backfill resumes.
 *
 * Delete once every eligible historic run has a timeline and canary 5 has run on it.
 */
final readonly class BackfillAudioTimeline
{
    public const string REATTACHED_BY = 'historic-import:classify-audio';

    public function __construct(
        private HistoricStagingContextRegistry $stagingContexts,
        private ProcessingArtifactReuse $artifactReuse,
        private ServiceArtifactStorage $artifactStorage,
        private ExtractedMediaDurationProbe $durationProbe,
        private RmsAnalysisService $rmsAnalysis,
    ) {}

    /**
     * @return array{outcome: 'done'|'classify'|'reattach'|'refused'|'classified'|'reattached'|'failed', reason: string}
     */
    public function execute(MediaProcessingLog $run, bool $execute): array
    {
        return $this->withinRunContext($run, function () use ($run, $execute): array {
            if ($this->artifactReuse->audioTimelineIsUsable($run)) {
                return ['outcome' => 'done', 'reason' => 'already has a usable audio timeline'];
            }

            $recorded = ClassifyServiceAudio::recordedAudio($run);
            $audio = $recorded ?? $this->artifactStorage->audioLocation($run->processing_id);
            $refusal = $this->refusal($run, $audio);

            if ($refusal !== null) {
                return ['outcome' => 'refused', 'reason' => ($recorded === null ? 'no audio artifact recorded; ' : '').$refusal];
            }

            if (! $execute) {
                return $recorded === null
                    ? ['outcome' => 'reattach', 'reason' => "would re-attach {$audio['disk']}:{$audio['path']}, then classify it"]
                    : ['outcome' => 'classify', 'reason' => "would classify {$audio['disk']}:{$audio['path']}"];
            }

            if ($recorded === null) {
                $this->reattach($run, $audio);
            }

            try {
                $path = (new ClassifyServiceAudio($run))->classify(
                    app(AudioClassifier::class),
                    $this->rmsAnalysis,
                    $this->artifactStorage,
                    app(StorageAdapterHelper::class),
                );
            } catch (Throwable $throwable) {
                return ['outcome' => 'failed', 'reason' => $throwable->getMessage()];
            }

            return [
                'outcome' => $recorded === null ? 'reattached' : 'classified',
                'reason' => "timeline recorded at {$path}",
            ];
        });
    }

    /**
     * Why this audio cannot be classified for the run, or null when it can.
     *
     * @param  array{disk: string, path: string}  $audio
     */
    private function refusal(MediaProcessingLog $run, array $audio): ?string
    {
        $rmsEnd = $this->rmsLogEnd($run);

        if ($rmsEnd === null) {
            return 'no readable RMS log to check the audio against';
        }

        try {
            if (! Storage::disk($audio['disk'])->exists($audio['path'])) {
                return "audio missing at {$audio['disk']}:{$audio['path']}";
            }

            $duration = $this->durationProbe->durationOf(Storage::disk($audio['disk'])->path($audio['path']));
        } catch (Throwable $throwable) {
            return "audio at {$audio['disk']}:{$audio['path']} cannot be probed: {$throwable->getMessage()}";
        }

        if (abs($duration - $rmsEnd) > AudioClassifier::WINDOW_SECONDS) {
            return sprintf('audio lasts %.1fs but the RMS log ends at %.1fs: not the same recording', $duration, $rmsEnd);
        }

        return null;
    }

    /**
     * @param  array{disk: string, path: string}  $audio
     */
    private function reattach(MediaProcessingLog $run, array $audio): void
    {
        $run->writeProcessingMetadata(static function (array $metadata) use ($audio): array {
            $artifacts = is_array($metadata[ServiceArtifactStorage::METADATA_KEY] ?? null) ? $metadata[ServiceArtifactStorage::METADATA_KEY] : [];
            $artifacts[] = [
                'kind' => 'audio',
                'disk' => $audio['disk'],
                'path' => $audio['path'],
                'recorded_at' => now()->toIso8601String(),
                'reattached_by' => self::REATTACHED_BY,
            ];
            $metadata[ServiceArtifactStorage::METADATA_KEY] = array_values($artifacts);

            return $metadata;
        });
    }

    private function rmsLogEnd(MediaProcessingLog $run): ?float
    {
        $path = $run->rms_log_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));
            $samples = $disk->exists($path) ? $this->rmsAnalysis->extractRmsData((string) $disk->get($path)) : [];
        } catch (Throwable) {
            return null;
        }

        return $samples === [] ? null : $samples[count($samples) - 1]['time'];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withinRunContext(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        return $context === null ? $callback() : $this->stagingContexts->within($context, $callback);
    }
}
