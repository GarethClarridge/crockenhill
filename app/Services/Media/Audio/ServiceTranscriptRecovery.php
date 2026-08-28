<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use Illuminate\Support\Facades\Log;
use Throwable;

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

            // We did look, and the audio yields nothing usable. The original cues
            // are known-bad, so drop them rather than leave misleading text behind.
            if ($retry->isEmpty() || $this->detector->detect($retry) !== []) {
                $cues = $this->withoutOverlappingCues($cues, $window['start'], $window['end']);
                $unobservableWindows[] = [
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'reason' => 'retranscription_failed',
                ];

                continue;
            }

            $cues = $this->withoutOverlappingCues($cues, $window['start'], $window['end']);
            foreach ($retry->cues as $cue) {
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
