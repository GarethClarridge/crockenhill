<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Data\TranscriptCoverageGap;

/**
 * Finds stretches where the recording carries sustained sound that the
 * transcript does not account for.
 *
 * **Why this is not another transcript screen.**
 * {@see ServiceTranscriptRepetitionScreen} judges a transcript by its own shape
 * — repetition, density, cadence — which means a pathology that looks like
 * plausible text escapes it, and so does a pathology that two different decoders
 * both produce. This screen never reads the transcript's shape. It asks the RMS
 * log where the recording had sound, and then asks the transcript how much it
 * has to say about those seconds. Energy is measured without a language model,
 * so it stays sensitive exactly where decoders fail together.
 *
 * That makes it the instrument H10 needs: it can count defects the repetition
 * screen missed without a second decode and without listening.
 *
 * **A gap is a candidate, not a defect.** Sustained sound with no words can be
 * singing the transcript never caught, an organ voluntary, a congregation
 * moving, or an open microphone over a quiet hall. Song spans are excluded by
 * the caller precisely because they would otherwise dominate the output. What
 * survives is worth a human minute, not a hold — this screen raises none.
 */
class ServiceTranscriptCoverageScreen
{
    public function __construct(private readonly PathologicalWindowSoundSpans $soundSpans) {}

    /**
     * The stretches of unaccounted-for sound in one recording.
     *
     * @param  list<array{time: float, rms: float}>  $rmsData  from {@see RmsAnalysisService::extractRmsData()}
     * @param  list<array{start: float, end: float}>  $excludedSpans  Song sections and anything else whose
     *                                                                silence in the transcript is expected
     * @return list<TranscriptCoverageGap>
     */
    public function screen(array $rmsData, ChurchServiceTranscript $transcript, array $excludedSpans = []): array
    {
        if ($rmsData === [] || $transcript->duration <= 0.0) {
            return [];
        }

        $windowSeconds = $this->config('window_seconds', 60.0);
        $minSoundedShare = $this->config('min_sounded_share', 0.75);
        $maxWordsPerMinute = $this->config('max_words_per_minute', 30.0);
        $minGapSeconds = $this->config('min_gap_seconds', 90.0);

        if ($windowSeconds <= 0.0) {
            return [];
        }

        $sounded = $this->soundSpans->for($rmsData, 0.0, $transcript->duration);
        $candidates = [];

        for ($start = 0.0; $start < $transcript->duration; $start += $windowSeconds) {
            $end = min($start + $windowSeconds, $transcript->duration);
            $soundedSeconds = $this->overlapSeconds($sounded, $start, $end);
            $span = $end - $start;

            if ($span <= 0.0 || $soundedSeconds / $span < $minSoundedShare) {
                continue;
            }

            if ($this->overlapSeconds($excludedSpans, $start, $end) > 0.0) {
                continue;
            }

            $words = $this->words($transcript, $start, $end);

            if ($words / max($soundedSeconds, 0.001) * 60 >= $maxWordsPerMinute) {
                continue;
            }

            $candidates[] = ['start' => $start, 'end' => $end, 'sounded' => $soundedSeconds, 'words' => $words];
        }

        return $this->merge($candidates, $minGapSeconds);
    }

    /**
     * Adjacent under-transcribed windows are one gap, not several: the window
     * is an instrument for measuring, never a claim about where the defect
     * begins and ends.
     *
     * @param  list<array{start: float, end: float, sounded: float, words: int}>  $candidates
     * @return list<TranscriptCoverageGap>
     */
    private function merge(array $candidates, float $minGapSeconds): array
    {
        $gaps = [];
        $current = null;

        foreach ($candidates as $candidate) {
            if ($current !== null && abs($candidate['start'] - $current['end']) < 0.001) {
                $current['end'] = $candidate['end'];
                $current['sounded'] += $candidate['sounded'];
                $current['words'] += $candidate['words'];

                continue;
            }

            if ($current !== null) {
                $gaps[] = $current;
            }

            $current = $candidate;
        }

        if ($current !== null) {
            $gaps[] = $current;
        }

        $merged = [];

        foreach ($gaps as $gap) {
            if ($minGapSeconds > $gap['end'] - $gap['start']) {
                continue;
            }

            $merged[] = new TranscriptCoverageGap(
                start: $gap['start'],
                end: $gap['end'],
                soundedSeconds: $gap['sounded'],
                words: $gap['words'],
            );
        }

        return $merged;
    }

    /**
     * @param  list<array{start: float, end: float}>  $spans
     */
    private function overlapSeconds(array $spans, float $start, float $end): float
    {
        $seconds = 0.0;

        foreach ($spans as $span) {
            $overlap = min($end, $span['end']) - max($start, $span['start']);

            if ($overlap > 0.0) {
                $seconds += $overlap;
            }
        }

        return $seconds;
    }

    private function words(ChurchServiceTranscript $transcript, float $start, float $end): int
    {
        $words = 0;

        foreach ($transcript->cues as $cue) {
            if ($cue['end'] <= $start || $cue['start'] >= $end) {
                continue;
            }

            $words += count(preg_split('/\s+/u', trim($cue['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        return $words;
    }

    private function config(string $key, float $default): float
    {
        return (float) config("media-processing.service_structure.coverage_screen.{$key}", $default);
    }
}
