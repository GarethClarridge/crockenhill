<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTimeline;

/**
 * Word ownership in the last voiced run (WP19 R2; E24/E28). Sustained speech can look like a
 * sung phrase on levels alone. Two windows must hear a word of a next-line bigram, outside
 * the bound song's lyrics, with speech stronger than both singing and choir. Earlier agreed
 * words are kept even when the lyric match misses them. No distance limit is added.
 *
 * @phpstan-type Ownership array{decodes: list<array{start: float, end: float, words: list<array{start: float, end: float, word: string}>}>, lyrics: string, cues: list<array{start: float, end: float, text: string}>, next_line: string, timeline: list<array{start: float, end: float, music: float, speech: float, singing: float, choir?: float}>}
 */
class SongEndSpokenWords
{
    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return Ownership|null
     */
    public function evidence(MediaProcessingLog $log, ?string $lyrics, array $cues, ?string $nextLine, ?AudioTimeline $timeline): ?array
    {
        if ($lyrics === null || trim($lyrics) === '' || $timeline === null) {
            return null;
        }

        return ['decodes' => app(OutputEdgeWordTimings::class)->bankedWindows($log, includeWide: true),
            'lyrics' => $lyrics, 'cues' => $cues, 'next_line' => $nextLine ?? '', 'timeline' => $timeline->windows];
    }

    /**
     * @param  Ownership|null  $evidence
     * @param  array{speech: float, last_sung: float, frame_seconds: float, sung_runs?: list<array{start: float, end: float}>}  $voice
     * @return array{speech: float, last_sung: float, sounds: list<float>}|null
     */
    public function inLastRun(?array $evidence, array $voice): ?array
    {
        $runs = $voice['sung_runs'] ?? [];
        if ($evidence === null || $runs === [] || trim($evidence['lyrics']) === '') {
            return null;
        }
        $run = $runs[array_key_last($runs)];
        $halfFrame = $voice['frame_seconds'] / 2;
        $lyric = app(SongTailLastLyric::class);
        $terms = $lyric->terms($evidence['lyrics']);
        $phrases = $lyric->phrases($terms);
        $lyricPairs = $this->pairs($terms);
        $lyricTriples = [];
        for ($index = 2; $index < count($terms); $index++) {
            $lyricTriples[] = implode(' ', array_slice($terms, $index - 2, 3));
        }
        $lines = [$evidence['next_line']];
        foreach ($evidence['cues'] as $cue) {
            $line = $lyric->terms($cue['text']);
            $holdsLyric = false;
            for ($index = 2; $index < count($line); $index++) {
                $holdsLyric = $holdsLyric || in_array(implode(' ', array_slice($line, $index - 2, 3)), $lyricTriples, true);
            }
            if ($cue['end'] > $run['start'] - 0.5 && $cue['start'] <= $voice['speech'] + 6.0 && ! $holdsLyric) {
                $lines[] = $cue['text'];
            }
        }
        $spokenPairs = array_diff(array_merge([], ...array_map(fn (string $line): array => $this->pairs($lyric->terms($line)), $lines)), $lyricPairs);
        $found = [];
        foreach ($evidence['decodes'] as $decode) {
            if ($decode['end'] < $run['start'] - 1.0 || $decode['start'] > $run['end'] + 1.0) {
                continue;
            }
            $decode['words'] = $lyric->normaliseWords($decode['words']);
            foreach ($decode['words'] as $index => $word) {
                $text = $lyric->term($word['word']);
                $previous = $index > 0 ? $lyric->term($decode['words'][$index - 1]['word']) : '';
                $midpoint = ($word['start'] + $word['end']) / 2;
                if ($text === '' || $previous === '' || $word['end'] - $word['start'] > AgreedEdgeWords::WORD_SECONDS
                    || $midpoint < $run['start'] - $halfFrame || $midpoint > $run['end'] + $halfFrame
                    || $word['start'] <= $decode['start'] + SongTailLastLyric::EDGE || $word['end'] >= $decode['end'] - SongTailLastLyric::EDGE) {
                    continue;
                }
                $sung = $lyric->sung($phrases, $index >= 2 ? $lyric->term($decode['words'][$index - 2]['word']) : '', $previous, $text);
                $pairStart = round(max($decode['words'][$index - 1]['start'], $run['start']), 2);
                $window = array_find($evidence['timeline'], static fn (array $window): bool => $window['start'] <= $pairStart && $pairStart < $window['end']);
                $spoken = ! $sung && in_array($previous.' '.$text, $spokenPairs, true)
                    && $window !== null && $window['speech'] > $window['singing'] && $window['speech'] > ($window['choir'] ?? 0.0);
                $found[] = ['window' => $decode['start'], 'text' => $text, 'start' => round($word['start'], 2), 'end' => round($word['end'], 2),
                    'pair_start' => $pairStart, 'lyric' => $sung, 'spoken' => $spoken];
            }
        }
        $agreed = array_values(array_filter($found, static fn (array $word): bool => array_any($found, static fn (array $other): bool => $other['window'] !== $word['window']
            && $other['text'] === $word['text'] && abs($other['start'] - $word['start']) <= AgreedEdgeWords::TOLERANCE)));
        $lyricEnds = array_column(array_filter($agreed, static fn (array $word): bool => $word['lyric']), 'end');
        $lastLyric = $lyricEnds === [] ? null : max($lyricEnds);
        $spoken = array_values(array_filter($agreed, static fn (array $word): bool => $word['spoken'] && ($lastLyric === null || $word['pair_start'] >= $lastLyric - $halfFrame)));
        if ($spoken === []) {
            return null;
        }
        $talking = min(array_column($spoken, 'pair_start'));
        $before = array_column(array_filter($agreed, static fn (array $word): bool => $word['end'] <= $talking + $halfFrame && $word['start'] < $talking - $halfFrame), 'end');
        $kept = array_filter([$lastLyric, $before === [] ? null : max($before)], static fn (?float $time): bool => $time !== null);
        $lastSung = min($talking, $kept === [] ? $run['start'] : max($kept));
        $previousRun = count($runs) > 1 ? $runs[count($runs) - 2]['end'] : null;
        $sounds = array_filter([...$kept, $previousRun], static fn (?float $time): bool => $time !== null);

        return ['speech' => min($talking, $voice['speech']), 'last_sung' => $lastSung, 'sounds' => array_values($sounds)];
    }

    /**
     * C1's word check: two independent windows hear a short, unstacked non-lyric word near
     * the voice return. The decoder's neighbouring lyric bigrams protect sung words.
     *
     * @param  list<array{start: float, end: float, words: list<array{start: float, end: float, word: string}>}>  $decodes
     */
    public function nearTalking(array $decodes, float $talking, ?string $lyrics): bool
    {
        $term = static fn (string $word): string => preg_replace('/[^a-z]/', '', strtolower($word)) ?? '';
        $lyricTerms = array_values(array_filter(array_map($term, preg_split('/\s+/', $lyrics ?? '') ?: [])));
        $pairs = $this->pairs($lyricTerms);
        $found = [];
        foreach ($decodes as $decode) {
            $words = $decode['words'];
            foreach ($words as $index => $word) {
                $text = $term($word['word']);
                if ($text === '' || $word['end'] - $word['start'] < 0.03 || $word['end'] - $word['start'] > AgreedEdgeWords::WORD_SECONDS
                    || $word['start'] <= $decode['start'] + 1.0 || $word['end'] >= $decode['end'] - 1.0
                    || $word['start'] < $talking - 0.5 - 1e-6 || $word['start'] >= $talking + 1.0 - 0.05
                    || count(array_filter($words, static fn (array $other): bool => abs($other['start'] - $word['start']) < 0.011)) >= 3) {
                    continue;
                }
                $found[] = ['window' => $decode['start'], 'text' => $text, 'start' => round($word['start'], 2),
                    'lyric' => in_array(($index > 0 ? $term($words[$index - 1]['word']) : '').' '.$text, $pairs, true)
                        || in_array($text.' '.(isset($words[$index + 1]) ? $term($words[$index + 1]['word']) : ''), $pairs, true)];
            }
        }

        return array_any($found, static fn (array $word): bool => ! $word['lyric'] && array_any($found,
            static fn (array $other): bool => $other['window'] !== $word['window'] && $other['text'] === $word['text']
                && abs($other['start'] - $word['start']) <= AgreedEdgeWords::TOLERANCE));
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function pairs(array $terms): array
    {
        $pairs = [];
        for ($index = 1; $index < count($terms); $index++) {
            $pairs[] = $terms[$index - 1].' '.$terms[$index];
        }

        return $pairs;
    }
}
