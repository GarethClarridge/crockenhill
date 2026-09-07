<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
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
    public function __construct(
        private readonly ServiceTranscriptPathologyDetector $detector,
        private readonly ServiceAudioWindowExtractor $extractor,
        private readonly ServiceTranscriptionInterface $transcriptionService,
    ) {}

    public function recover(ChurchServiceTranscript $transcript, string $sourcePath, string $processingId): ChurchServiceTranscript
    {
        if (! (bool) config('media-processing.service_structure.transcript_recovery.enabled', true)) {
            return $transcript;
        }

        $cues = $transcript->cues;
        $unobservableWindows = $transcript->unobservableWindows;

        foreach ($this->detector->detect($transcript) as $index => $window) {
            $retry = $this->retranscribe($sourcePath, $window, $processingId, $index);

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
     * Re-transcribe one window in isolation, or null when the attempt could not
     * be made at all. A null is an infrastructure outcome, never a verdict on
     * the audio.
     *
     * @param  array{start: float, end: float, reason: string, cue_count: int}  $window
     */
    private function retranscribe(string $sourcePath, array $window, string $processingId, int $index): ?ChurchServiceTranscript
    {
        $clipPath = null;

        try {
            $clipPath = $this->extractor->extract($sourcePath, $window['start'], $window['end'], $processingId);

            // No priming. The configured full-service prompt describes a whole
            // service, and on a music-only window it makes the model invent
            // service-shaped speech instead of transcribing what is there —
            // reproducing the pathology this retry exists to clear.
            return $this->transcriptionService->transcribeService($clipPath, $processingId.'-recovery-'.($index + 1), '');
        } catch (Throwable $throwable) {
            Log::warning('Targeted transcript re-transcription could not be attempted', [
                'processing_id' => $processingId,
                'window_start' => $window['start'],
                'window_end' => $window['end'],
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
