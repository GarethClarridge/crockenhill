<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

/**
 * Narrow a looping transcript window to the parts of it that actually carry sound.
 *
 * A window is selected because the transcript repeated one phrase across
 * minutes, which says nothing about where the audio has speech. Run 1340
 * (2021-01-17) is the shape that matters: its window ran 1355–1595, but the
 * recording holds nothing but a −52 dB noise floor to 1520 and then sixty
 * seconds of digital silence, with speech resuming only around 1576. Whisper
 * had filled the whole stretch with eight thirty-second "Thank you." cues, and
 * the final one overran the resumption of speech by nineteen seconds — so the
 * sermon's opening sentence sat inside a window banked `retranscription_failed`.
 *
 * Re-transcribing the window whole reproduces that: a 240-second clip that is
 * five-sixths silence gives the model nothing to anchor on and it loops again.
 * A seventy-second clip around the speech edge transcribes the same sentence
 * cleanly. So the retry is aimed at the sound, not at the window.
 *
 * An empty result is a finding, not a failure: the window holds no sound worth
 * decoding, which is cheaper to record than to pay a provider to confirm.
 */
class PathologicalWindowSoundSpans
{
    /**
     * Treated as sound rather than room noise. The shared analysis default
     * (−45 dB) sits between 1340's −52 dB noise floor and its −26 dB speech,
     * so the two separate without a window-specific threshold.
     */
    private const float SOUND_FLOOR_DB = -45.0;

    /** Bridged rather than split: ordinary speech pauses are shorter than this. */
    private const float MAX_INTERNAL_GAP_SECONDS = 5.0;

    /** Below this a span is a cough or a click, not speech worth a decode. */
    private const float MIN_SPAN_SECONDS = 4.0;

    /**
     * Added either side, so a decode starts before the first word rather than
     * on it, and the model has a moment of context to settle on.
     */
    private const float MARGIN_SECONDS = 2.0;

    /**
     * The sound-bearing spans inside one window, in absolute recording seconds.
     *
     * Returns the whole window unchanged when there is no RMS data to judge it
     * by: without measurements the window is unnarrowed, never assumed silent.
     *
     * @param  list<array{time: float, rms: float}>  $rmsData  from {@see RmsAnalysisService::extractRmsData()}
     * @return list<array{start: float, end: float}>
     */
    public function for(array $rmsData, float $windowStart, float $windowEnd): array
    {
        if ($rmsData === [] || $windowEnd <= $windowStart) {
            return [['start' => $windowStart, 'end' => $windowEnd]];
        }

        $sounded = [];

        foreach ($rmsData as $sample) {
            if ($sample['time'] < $windowStart || $sample['time'] > $windowEnd) {
                continue;
            }

            if ($sample['rms'] > self::SOUND_FLOOR_DB) {
                $sounded[] = $sample['time'];
            }
        }

        if ($sounded === []) {
            return [];
        }

        sort($sounded);

        $spans = [];
        $start = $sounded[0];
        $previous = $sounded[0];

        foreach ($sounded as $time) {
            if ($time - $previous > self::MAX_INTERNAL_GAP_SECONDS) {
                $spans[] = ['start' => $start, 'end' => $previous];
                $start = $time;
            }

            $previous = $time;
        }

        $spans[] = ['start' => $start, 'end' => $previous];

        return array_values(array_map(
            fn (array $span): array => [
                'start' => max($windowStart, $span['start'] - self::MARGIN_SECONDS),
                'end' => min($windowEnd, $span['end'] + self::MARGIN_SECONDS),
            ],
            array_filter($spans, fn (array $span): bool => $span['end'] - $span['start'] >= self::MIN_SPAN_SECONDS),
        ));
    }
}
