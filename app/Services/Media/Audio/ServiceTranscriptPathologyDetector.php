<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;

class ServiceTranscriptPathologyDetector
{
    /** @return list<array{start: float, end: float, reason: string, cue_count: int}> */
    public function detect(ChurchServiceTranscript $transcript): array
    {
        $minimumCues = (int) config('media-processing.service_structure.transcript_recovery.min_repeated_cues', 6);
        $minimumDuration = (float) config('media-processing.service_structure.transcript_recovery.min_window_seconds', 120);
        $maximumWords = (int) config('media-processing.service_structure.transcript_recovery.max_phrase_words', 12);
        $maximumGap = (float) config('media-processing.service_structure.transcript_recovery.max_gap_seconds', 60);
        $windows = [];
        $run = [];
        $previousText = null;

        foreach ($transcript->cues as $cue) {
            $text = $this->normaliseText($cue['text']);

            $previousCue = $run === [] ? null : $run[array_key_last($run)];
            $isLowInformation = str_word_count($text) <= $maximumWords;
            $isContiguous = $previousCue === null || $maximumGap >= $cue['start'] - $previousCue['end'];

            if ($isLowInformation && $isContiguous && $text !== '' && $text === $previousText) {
                $run[] = $cue;
            } else {
                $this->appendPathologicalRun($windows, $run, $minimumCues, $minimumDuration);
                $run = $text === '' || ! $isLowInformation ? [] : [$cue];
            }

            $previousText = $isLowInformation ? $text : null;
        }

        $this->appendPathologicalRun($windows, $run, $minimumCues, $minimumDuration);

        return $windows;
    }

    private function normaliseText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $run
     * @param  list<array{start: float, end: float, reason: string, cue_count: int}>  $windows
     */
    private function appendPathologicalRun(array &$windows, array $run, int $minimumCues, float $minimumDuration): void
    {
        if (count($run) < $minimumCues) {
            return;
        }

        $start = $run[0]['start'];
        $end = $run[count($run) - 1]['end'];

        if ($end - $start < $minimumDuration) {
            return;
        }

        $windows[] = [
            'start' => $start,
            'end' => $end,
            'reason' => 'repeated_low_information_cues',
            'cue_count' => count($run),
        ];
    }
}
