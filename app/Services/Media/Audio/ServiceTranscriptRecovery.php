<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-transcribe the windows where the full-service pass looped, and keep
 * whatever the retry actually recovers.
 *
 * A window is chosen because its *original* transcript repeated one low
 * information phrase across minutes. That says nothing about how much of the
 * window holds speech, and in practice it usually holds a great deal: whisper
 * hallucinates a 30-second-chunk loop over the music or quiet at a window's
 * leading edge and then transcribes the rest normally. So the retry is accepted
 * region by region — the sub-windows that still loop are recorded unobservable,
 * everything else is kept.
 *
 * Judging the retry as a single verdict is what made sermon 1148 (2023-04-30,
 * run 1229) complete holding 81 words of closing prayer. Its retry returned
 * 1,405 cues and 3,282 words, of which one 182-second "Amen." run at the very
 * start was pathological; the whole retry was therefore discarded, the whole
 * 2,037-second window banked as unobservable, and the sermon inside it lost. The
 * same shape appears in run 1118 (2,849 words behind a 214-second loop). Neither
 * needed a different decode — the speech was already transcribed.
 */
class ServiceTranscriptRecovery
{
    /**
     * Windows this pass found to hold no sound at all, in absolute seconds.
     *
     * @var list<array{start: float, end: float}>
     */
    private array $silentWindows = [];

    public function __construct(
        private readonly ServiceTranscriptPathologyDetector $detector,
        private readonly ServiceAudioWindowExtractor $extractor,
        private readonly ServiceTranscriptionInterface $transcriptionService,
        private readonly RmsAnalysisService $rmsAnalysis,
        private readonly PathologicalWindowSoundSpans $soundSpans,
        private readonly SupersededTranscriptFallback $supersededFallback,
    ) {}

    /**
     * @param  string|null  $rmsLogContent  the run's astats log, when it has one;
     *                                      without it every window is retried whole
     * @param  ChurchServiceTranscript|null  $superseded  the transcript this pass
     *                                                    replaces, consulted for
     *                                                    any window it cannot decode
     */
    public function recover(
        ChurchServiceTranscript $transcript,
        string $sourcePath,
        string $processingId,
        ?string $rmsLogContent = null,
        ?ChurchServiceTranscript $superseded = null,
    ): ChurchServiceTranscript {
        if (! (bool) config('media-processing.service_structure.transcript_recovery.enabled', true)) {
            return $transcript;
        }

        $this->silentWindows = [];
        $rmsData = is_string($rmsLogContent) && $rmsLogContent !== ''
            ? $this->rmsAnalysis->extractRmsData($rmsLogContent)
            : [];

        $recovered = $this->recoverUsing(
            $transcript,
            fn (int $index, array $window): ?ChurchServiceTranscript => $this->retranscribe(
                $sourcePath,
                $window,
                $processingId,
                $index,
                $rmsData,
                $transcript->source,
            ),
        );

        return $this->supersededFallback->apply(
            $this->withSilentWindowsNamed($recovered),
            $superseded,
        );
    }

    /**
     * Re-label the windows this pass measured as silent.
     *
     * `recoverUsing()` only sees an empty retry, which it banks as
     * `retranscription_failed` — accurate when a decode came back with nothing,
     * misleading when no decode was attempted because the recording holds no
     * sound there. The distinction matters to the acceptance accounting, where a
     * silent stretch of the original service is a property of the recording and
     * a failed decode is unfinished work.
     */
    private function withSilentWindowsNamed(ChurchServiceTranscript $transcript): ChurchServiceTranscript
    {
        if ($this->silentWindows === []) {
            return $transcript;
        }

        $windows = array_map(
            function (array $window): array {
                foreach ($this->silentWindows as $silent) {
                    if (abs($window['start'] - $silent['start']) < 0.01 && abs($window['end'] - $silent['end']) < 0.01) {
                        return ['start' => $window['start'], 'end' => $window['end'], 'reason' => 'window_holds_no_sound'];
                    }
                }

                return $window;
            },
            $transcript->unobservableWindows,
        );

        return ChurchServiceTranscript::fromCues(
            $transcript->cues,
            $transcript->duration,
            $transcript->source,
            $windows,
        );
    }

    /**
     * The recovery rule itself, over retries supplied by the caller.
     *
     * Separated from {@see recover()} so the rule has one implementation rather
     * than two. The pipeline supplies retries by re-transcribing; a replay over
     * a completed run supplies the retries that run already banked, which is the
     * only way to reproduce the decision a past run made rather than approximate
     * it with a fresh decode of the same audio.
     *
     * The window index passed to the resolver is the index within *this*
     * transcript's detected windows, which is what the pipeline names its retry
     * artifacts by. Returning null means the retry could not be obtained at all
     * and is never a verdict on the audio.
     *
     * Deliberately not gated on `transcript_recovery.enabled`: that flag decides
     * whether the pipeline attempts recovery, not whether an operator replaying a
     * banked one is allowed to apply the rule.
     *
     * @param  Closure(int, array{start: float, end: float, reason: string, cue_count: int}): ?ChurchServiceTranscript  $retryFor
     */
    public function recoverUsing(ChurchServiceTranscript $transcript, Closure $retryFor): ChurchServiceTranscript
    {
        $cues = $transcript->cues;
        $unobservableWindows = $transcript->unobservableWindows;

        foreach ($this->detector->detect($transcript) as $index => $window) {
            $retry = $retryFor($index, $window);

            // We never got to look at the audio, so we know nothing new about the
            // window. Flag it — the projector treats any flagged window as reason
            // not to corroborate songs — but keep what we already have. Deleting
            // real cues because ffmpeg was missing loses evidence a later run
            // could still recover.
            if ($retry === null) {
                $unobservableWindows[] = [
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'reason' => 'retranscription_unavailable',
                ];

                continue;
            }

            // We did look, so the original cues are known-bad either way: drop
            // them rather than leave misleading text behind.
            $cues = $this->withoutOverlappingCues($cues, $window['start'], $window['end']);

            // The audio yields nothing at all. Nothing to keep, nothing to place.
            if ($retry->isEmpty()) {
                $unobservableWindows[] = [
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'reason' => 'retranscription_failed',
                ];

                continue;
            }

            // A retry can loop over part of its window and transcribe the rest
            // perfectly, so judge it by region rather than as one verdict. Run
            // 1229's 2,037-second window returned 3,282 words of sermon behind a
            // 182-second "Amen." loop at its leading edge; run 1118's returned
            // 2,849 behind 214 seconds of the same. Rejecting the whole retry
            // banked both windows as unobservable and discarded every recovered
            // word — which is how sermon 1148 completed holding 81 words of
            // closing prayer while its sermon sat in the discarded text.
            $residue = $this->detector->detect($retry);

            foreach ($residue as $pathological) {
                $unobservableWindows[] = [
                    'start' => $pathological['start'] + $window['start'],
                    'end' => $pathological['end'] + $window['start'],
                    'reason' => 'retranscription_failed',
                ];
            }

            // Spans the retry itself could not observe, on the recording clock.
            foreach ($retry->unobservableWindows as $blind) {
                $unobservableWindows[] = [
                    'start' => $blind['start'] + $window['start'],
                    'end' => $blind['end'] + $window['start'],
                    'reason' => $blind['reason'],
                ];
            }

            $recovered = $retry->cues;

            foreach ($residue as $pathological) {
                $recovered = $this->withoutOverlappingCues($recovered, $pathological['start'], $pathological['end']);
            }

            foreach ($recovered as $cue) {
                $cues[] = [
                    'start' => $cue['start'] + $window['start'],
                    'end' => $cue['end'] + $window['start'],
                    'text' => $cue['text'],
                ];
            }
        }

        return ChurchServiceTranscript::fromCues($cues, $transcript->duration, $transcript->source, $unobservableWindows);
    }

    /**
     * Re-transcribe the sound inside one window, or null when the attempt could
     * not be made at all. A null is an infrastructure outcome, never a verdict
     * on the audio.
     *
     * The window is decoded span by span rather than whole, so a stretch that
     * is mostly silence does not hand the model the very conditions that made
     * it loop. Cues come back relative to the window start, which is what
     * {@see recoverUsing()} offsets.
     *
     * @param  array{start: float, end: float, reason: string, cue_count: int}  $window
     * @param  list<array{time: float, rms: float}>  $rmsData
     * @param  string  $source  the recovered transcript's source, carried onto
     *                          the per-window result so it stays self-describing
     */
    private function retranscribe(
        string $sourcePath,
        array $window,
        string $processingId,
        int $index,
        array $rmsData,
        string $source,
    ): ?ChurchServiceTranscript {
        $spans = $this->soundSpans->for($rmsData, $window['start'], $window['end']);

        // Measured silence, so there is nothing to decode and no reason to pay
        // for a provider call that can only confirm it. Recorded as a silent
        // window rather than a failed retry.
        if ($spans === []) {
            $this->silentWindows[] = ['start' => $window['start'], 'end' => $window['end']];

            Log::info('Targeted transcript re-transcription skipped: the window holds no sound', [
                'processing_id' => $processingId,
                'window_start' => $window['start'],
                'window_end' => $window['end'],
            ]);

            return ChurchServiceTranscript::fromCues([], $window['end'] - $window['start'], $source);
        }

        $cues = [];
        $uncovered = [];
        $attempted = false;

        foreach ($spans as $position => $span) {
            // Each span is decoded on its own and placed back relative to the
            // window, because `recoverUsing()` offsets what it gets by the
            // window start. Decoding the window whole is what reproduces the
            // loop: 1340's clip was five-sixths silence.
            // A window that was not narrowed keeps the historic artifact name,
            // `…-recovery-N` by detected-window index, so banked retries stay
            // addressable by the same rule the replay relies on. Only a window
            // actually split into spans gains a sub-index.
            $retry = $this->retranscribeSpan(
                $sourcePath,
                $span,
                $processingId,
                count($spans) === 1
                    ? (string) ($index + 1)
                    : sprintf('%d-%d', $index + 1, $position + 1),
            );

            $offset = $span['start'] - $window['start'];

            // Sound this pass did not turn into words: not "nothing was said". The window's
            // original cues go once any span decodes, so the span is banked unobservable
            // (I1, operator ruling 2026-10-05); measured silence never reaches here.
            if ($retry === null || $retry->isEmpty()) {
                $uncovered[] = ['start' => $offset, 'end' => $span['end'] - $window['start'], 'reason' => 'retranscription_failed'];
            }

            if ($retry === null) {
                continue;
            }

            $attempted = true;

            foreach ($retry->cues as $cue) {
                $cues[] = [
                    'start' => $cue['start'] + $offset,
                    'end' => $cue['end'] + $offset,
                    'text' => $cue['text'],
                ];
            }
        }

        // Every span failed to extract or decode, so the audio was never seen.
        if (! $attempted) {
            return null;
        }

        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // Wholly uncovered, the retry is empty and recoverUsing() banks the whole window.
        return ChurchServiceTranscript::fromCues($cues, $window['end'] - $window['start'], $source, $cues === [] ? [] : $uncovered);
    }

    /**
     * Decode one sound-bearing span, or null when the attempt could not be made.
     *
     * @param  array{start: float, end: float}  $span
     */
    private function retranscribeSpan(string $sourcePath, array $span, string $processingId, string $label): ?ChurchServiceTranscript
    {
        $clipPath = null;

        try {
            $clipPath = $this->extractor->extract($sourcePath, $span['start'], $span['end'], $processingId);

            // No priming. The configured full-service prompt describes a whole
            // service, and on a music-only window it makes the model invent
            // service-shaped speech instead of transcribing what is there —
            // reproducing the pathology this retry exists to clear.
            return $this->transcriptionService->transcribeService($clipPath, $processingId.'-recovery-'.$label, '');
        } catch (Throwable $throwable) {
            Log::warning('Targeted transcript re-transcription could not be attempted', [
                'processing_id' => $processingId,
                'window_start' => $span['start'],
                'window_end' => $span['end'],
                'error' => $throwable->getMessage(),
            ]);

            return null;
        } finally {
            if ($clipPath !== null) {
                $this->extractor->delete($clipPath);
            }
        }
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return list<array{start: float, end: float, text: string}>
     */
    private function withoutOverlappingCues(array $cues, float $start, float $end): array
    {
        return array_values(array_filter(
            $cues,
            static fn (array $cue): bool => $cue['end'] <= $start || $cue['start'] >= $end,
        ));
    }
}
