<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay;

/**
 * Fill a window this pass could not decode from the transcript it replaced.
 *
 * A re-transcription overwrites the run's transcript, and until now that threw
 * away whatever a previous pass had recovered for the same audio. On
 * 2026-09-17 five macro-song re-runs did exactly that: each had been through a
 * recovery replay on 09-08/09, and each came back pointing at a fresh
 * `normalized.json` whose windows the new pass had failed to decode — while the
 * recovered file, holding real speech for those windows, sat on the disk
 * untouched. 146 live runs carry a recovery replay stamp, so the exposure is
 * not confined to those five.
 *
 * So when a window ends the pass as `retranscription_failed`, the superseded
 * transcript is consulted before the window is written off. This is the same
 * reasoning {@see HistoricTranscriptRecoveryReplay}
 * records for replaying banked retries: the question is not "what would this
 * audio yield today" — today's pass already answered that, badly — but "what
 * did this run once have". Only the superseded transcript answers it, and it
 * needs no model and no provider call.
 *
 * Carried cues keep a window entry of their own rather than clearing it. The
 * text is real, but it did not come from this decode, and a consumer weighing
 * whether to corroborate a song from it should be able to see that.
 */
class SupersededTranscriptFallback
{
    /** Reason recorded for a window whose text came from the previous transcript. */
    public const string REASON = 'carried_from_superseded_transcript';

    public function __construct(private readonly ServiceTranscriptPathologyDetector $detector) {}

    /**
     * Splice the superseded transcript's speech into the windows that failed.
     *
     * Only `retranscription_failed` windows are eligible. A window recorded
     * `window_holds_no_sound` is measured silence, so there is nothing to carry
     * and an older transcript claiming otherwise was hallucinating; a window
     * recorded `retranscription_unavailable` still has its original cues.
     */
    public function apply(ChurchServiceTranscript $recovered, ?ChurchServiceTranscript $superseded): ChurchServiceTranscript
    {
        if (! $superseded instanceof ChurchServiceTranscript || $superseded->isEmpty()) {
            return $recovered;
        }

        $cues = $recovered->cues;
        $windows = [];
        $changed = false;

        foreach ($recovered->unobservableWindows as $window) {
            if ($window['reason'] !== 'retranscription_failed') {
                $windows[] = $window;

                continue;
            }

            $carried = $this->speechWithin($superseded, (float) $window['start'], (float) $window['end']);

            if ($carried === []) {
                $windows[] = $window;

                continue;
            }

            foreach ($carried as $cue) {
                $cues[] = $cue;
            }

            $windows[] = ['start' => $window['start'], 'end' => $window['end'], 'reason' => self::REASON];
            $changed = true;
        }

        if (! $changed) {
            return $recovered;
        }

        usort($cues, static fn (array $first, array $second): int => $first['start'] <=> $second['start']);

        return ChurchServiceTranscript::fromCues($cues, $recovered->duration, $recovered->source, $windows);
    }

    /**
     * The superseded transcript's cues inside a window, less anything that
     * loops there too — an older transcript can be pathological in the same
     * place, and carrying its loop forward would re-create the defect this
     * whole path exists to clear.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function speechWithin(ChurchServiceTranscript $superseded, float $start, float $end): array
    {
        $within = array_values(array_filter(
            $superseded->cues,
            static fn (array $cue): bool => $cue['start'] < $end
                && $cue['end'] > $start
                && trim($cue['text']) !== '',
        ));

        if ($within === []) {
            return [];
        }

        $residue = $this->detector->detect(
            ChurchServiceTranscript::fromCues($within, $end, $superseded->source),
        );

        foreach ($residue as $pathological) {
            $within = array_values(array_filter(
                $within,
                static fn (array $cue): bool => $cue['end'] <= $pathological['start'] || $cue['start'] >= $pathological['end'],
            ));
        }

        return $within;
    }
}
