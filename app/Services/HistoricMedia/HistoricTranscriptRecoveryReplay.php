<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
use App\Support\ServiceArtifactDisk;
use App\Support\TranscriptPromptEchoDetector;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-applies transcript recovery to runs that banked their retries before the
 * region-wise rule existed, using those banked retries rather than new ones.
 *
 * Runs completed before that fix judged each targeted re-transcription with a
 * single test, so one pathological region anywhere in a retry discarded all of
 * it and the whole window was banked `retranscription_failed`. The speech those
 * retries recovered is not lost: {@see ServiceArtifactStorage::putJson()}
 * archives a retry *before* the recovery decides what to do with it, so every
 * discarded retry is still on the disk that ran it.
 *
 * Replaying from those artifacts rather than re-transcribing is not merely
 * cheaper. Whisper is not bit-deterministic between runs, so a fresh decode
 * answers "what would this audio yield today", where the question here is "what
 * did this run actually have and throw away". Only the banked retry answers the
 * second, and it needs no model, no ffmpeg, and no surviving source recording.
 *
 * **The retry artifact index is the *detected* window index, not the banked
 * one.** A window is banked unobservable only when its retry was rejected, so a
 * run with four detected windows and one banked window still has four artifacts
 * and the banked window may be any of them. Matching by clip duration therefore
 * picks the wrong artifact whenever two windows are close in length — and one
 * run has two windows of exactly 152.00 s. Because
 * {@see ServiceTranscriptPathologyDetector::detect()} is pure and the banked
 * `.raw.json` is the exact provider response, the detected list is re-derivable
 * and the index map is exact instead of approximate.
 *
 * Deletion trigger: delete once every affected historic run is replayed and the
 * Phase 8 closeout retention window has expired.
 */
class HistoricTranscriptRecoveryReplay
{
    public const DISPOSITION_REPLAYABLE = 'replayable';

    public const DISPOSITION_ALREADY_REPLAYED = 'already replayed';

    public const DISPOSITION_UNAFFECTED = 'unaffected';

    public const DISPOSITION_UNRESOLVED = 'unresolved';

    /** Artifact kind for the replayed transcript, kept distinct so the pre-fix one survives. */
    public const REPLAYED_KIND = 'normalized-region-recovered';

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly ServiceTranscriptReader $serviceTranscripts,
        private readonly ServiceTranscriptRecovery $recovery,
        private readonly ServiceTranscriptPathologyDetector $detector,
        private readonly TranscriptPromptEchoDetector $promptEcho,
        private readonly ServiceArtifactStorage $artifacts,
    ) {}

    /**
     * Classify each run without writing anything.
     *
     * @param  iterable<int, MediaProcessingLog>  $runs
     * @return list<TranscriptRecoveryReplayEntry>
     */
    public function inspect(iterable $runs): array
    {
        $entries = [];

        foreach ($runs as $run) {
            $entries[] = $this->inspectRun($run);
        }

        return $entries;
    }

    /**
     * Bank the replayed transcripts, skipping every other disposition.
     *
     * The pre-fix transcript is left where it is and the replayed one is written
     * under its own artifact kind, so the evidence for what the defect discarded
     * survives the correction of it. The run is then pointed at the new key.
     *
     * @param  list<TranscriptRecoveryReplayEntry>  $entries
     * @return array{replayed: int, failed: int, failures: list<string>}
     */
    public function apply(array $entries): array
    {
        $replayed = 0;
        $failed = 0;
        $failures = [];

        foreach ($entries as $entry) {
            if (! $entry->isReplayable() || ! $entry->recovered instanceof ChurchServiceTranscript) {
                continue;
            }

            $run = MediaProcessingLog::query()->find($entry->logId);

            if (! $run instanceof MediaProcessingLog) {
                $failed++;
                $failures[] = $entry->processingId.': the run no longer exists.';

                continue;
            }

            try {
                $this->withRunContext($run, function () use ($run, $entry): void {
                    $previousPath = $run->serviceTranscriptPath();
                    $previousWindows = $run->serviceTranscriptUnobservableWindows();

                    $path = $this->artifacts->putJson(
                        $run->processing_id,
                        self::REPLAYED_KIND,
                        $entry->recovered->toArray(),
                    );

                    $run->putServiceTranscriptPath($path, $entry->recovered->unobservableWindows);
                    $this->stamp($run, $entry, $previousPath, $previousWindows);
                });
                $replayed++;
            } catch (Throwable $exception) {
                $failed++;
                $failures[] = $entry->processingId.': '.$exception->getMessage();

                Log::error('Transcript recovery replay failed', [
                    'processing_id' => $entry->processingId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['replayed' => $replayed, 'failed' => $failed, 'failures' => $failures];
    }

    /**
     * Record what the replay replaced, so the change is legible and reversible
     * without re-deriving it.
     *
     * The superseded transcript is still on the disk; this names it, which is
     * what turns "the old one is still there somewhere" into a rollback.
     *
     * @param  list<array{start: float, end: float, reason: string}>  $previousWindows
     */
    private function stamp(
        MediaProcessingLog $run,
        TranscriptRecoveryReplayEntry $entry,
        ?string $previousPath,
        array $previousWindows,
    ): void {
        $run->putTranscriptRecoveryReplay([
            'replayed_at' => now()->toIso8601String(),
            'previous_path' => $previousPath,
            'previous_unobservable_windows' => $previousWindows,
            'blind_seconds_before' => $entry->blindSecondsBefore,
            'blind_seconds_after' => $entry->blindSecondsAfter,
            'words_before' => $entry->wordsBefore,
            'words_after' => $entry->wordsAfter,
        ]);
    }

    private function inspectRun(MediaProcessingLog $run): TranscriptRecoveryReplayEntry
    {
        $entry = new TranscriptRecoveryReplayEntry(
            logId: (int) $run->id,
            processingId: (string) $run->processing_id,
            disposition: self::DISPOSITION_UNRESOLVED,
        );

        try {
            return $this->withRunContext($run, fn (): TranscriptRecoveryReplayEntry => $this->replay($run, $entry));
        } catch (Throwable $exception) {
            return $entry->with(self::DISPOSITION_UNRESOLVED, $exception->getMessage());
        }
    }

    private function replay(MediaProcessingLog $run, TranscriptRecoveryReplayEntry $entry): TranscriptRecoveryReplayEntry
    {
        $transcriptPath = $run->serviceTranscriptPath();

        if ($transcriptPath === null) {
            return $entry->with(self::DISPOSITION_UNRESOLVED, 'the run records no full-service transcript.');
        }

        if ($run->transcriptRecoveryReplay() !== null) {
            return $entry->with(self::DISPOSITION_ALREADY_REPLAYED);
        }

        $banked = $this->serviceTranscripts->tryRead($run);

        if (! $banked instanceof ChurchServiceTranscript) {
            return $entry->with(self::DISPOSITION_UNRESOLVED, 'the banked full-service transcript is unreadable.');
        }

        $original = $this->preRecoveryTranscript($run, $transcriptPath);

        if (! $original instanceof ChurchServiceTranscript) {
            return $entry->with(self::DISPOSITION_UNRESOLVED, 'the pre-recovery raw transcript is unreadable.');
        }

        $detected = $this->detector->detect($original);

        if ($detected === []) {
            return $entry->with(self::DISPOSITION_UNAFFECTED, 'the pre-recovery transcript detects no pathological window.');
        }

        $retries = $this->bankedRetries($run, count($detected));
        $recovered = $this->recovery->recoverUsing(
            $original,
            fn (int $index): ?ChurchServiceTranscript => $retries[$index] ?? null,
        );

        return new TranscriptRecoveryReplayEntry(
            logId: (int) $run->id,
            processingId: (string) $run->processing_id,
            disposition: self::DISPOSITION_REPLAYABLE,
            detectedWindows: count($detected),
            retriesFound: count($retries),
            windowCountBefore: count($banked->unobservableWindows),
            windowCountAfter: count($recovered->unobservableWindows),
            blindSecondsBefore: $this->blindSeconds($banked),
            blindSecondsAfter: $this->blindSeconds($recovered),
            wordsBefore: $this->words($banked),
            wordsAfter: $this->words($recovered),
            windowsAfter: $recovered->unobservableWindows,
            recovered: $recovered,
        );
    }

    /**
     * The transcript as recovery first saw it: the banked provider response with
     * the prompt echoes filtered out, exactly as {@see \App\Jobs\TranscribeFullService}
     * assembles it. Rebuilt rather than read back, because the banked transcript
     * has already had the pathological cues deleted — detection over it would
     * find nothing to replay.
     */
    private function preRecoveryTranscript(MediaProcessingLog $run, string $transcriptPath): ?ChurchServiceTranscript
    {
        $rawPath = preg_replace('/\.normalized\.json$/', '.raw.json', $transcriptPath);

        if (! is_string($rawPath) || $rawPath === $transcriptPath) {
            return null;
        }

        $payload = $this->readJson($rawPath);
        $segments = $payload['segments'] ?? null;

        if (! is_array($segments) || $segments === []) {
            return null;
        }

        $cues = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $text = $segment['text'] ?? null;

            if (! is_string($text) || $this->promptEcho->isPromptEcho($text)) {
                continue;
            }

            $cues[] = ['start' => $segment['start'] ?? null, 'end' => $segment['end'] ?? null, 'text' => $text];
        }

        if ($cues === []) {
            return null;
        }

        return ChurchServiceTranscript::fromCues(
            $cues,
            (float) (is_numeric($payload['duration'] ?? null) ? $payload['duration'] : 0.0),
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );
    }

    /**
     * The retry this run banked for each detected window, keyed by window index.
     *
     * An index with no artifact is left absent rather than defaulted: the
     * recovery reads a missing retry as "could not look at the audio", which
     * keeps the original cues, and that is the honest answer when the artifact
     * is gone.
     *
     * @return array<int, ChurchServiceTranscript>
     */
    private function bankedRetries(MediaProcessingLog $run, int $detectedWindows): array
    {
        $retries = [];

        for ($index = 0; $index < $detectedWindows; $index++) {
            $path = sprintf(
                '%s%s/other-%s-recovery-%d.raw.json',
                ServiceArtifactDisk::DURABLE_PREFIX,
                ServiceArtifactStorage::UNRESOLVED_DATE,
                $run->processing_id,
                $index + 1,
            );

            $payload = $this->readJson($path);
            $segments = $payload['segments'] ?? null;

            if (! is_array($segments) || $segments === []) {
                continue;
            }

            $cues = [];

            foreach ($segments as $segment) {
                if (is_array($segment)) {
                    $cues[] = ['start' => $segment['start'] ?? null, 'end' => $segment['end'] ?? null, 'text' => $segment['text'] ?? null];
                }
            }

            $retries[$index] = ChurchServiceTranscript::fromCues(
                $cues,
                (float) (is_numeric($payload['duration'] ?? null) ? $payload['duration'] : 0.0),
                ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
            );
        }

        return $retries;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $disk = Storage::disk(ServiceArtifactDisk::for($path));

        if (! $disk->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function blindSeconds(ChurchServiceTranscript $transcript): float
    {
        return array_sum(array_map(
            static fn (array $window): float => $window['end'] - $window['start'],
            $transcript->unobservableWindows,
        ));
    }

    private function words(ChurchServiceTranscript $transcript): int
    {
        return str_word_count(implode(' ', array_column($transcript->cues, 'text')));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withRunContext(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        if ($context === null) {
            return $callback();
        }

        return $this->stagingContexts->within($context, $callback);
    }
}
