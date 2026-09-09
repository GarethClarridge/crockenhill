<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay;

/**
 * Find the stretches of a full-service transcript whose text contradicts
 * itself, so a looping decode is held rather than read as evidence.
 *
 * Why this is not {@see ServiceTranscriptPathologyDetector}. That detector
 * defines *recovery windows*: the intervals the pipeline re-transcribes, and
 * whose ordinal position names the banked retry artifacts
 * ({@see HistoricTranscriptRecoveryReplay}). Its
 * output is therefore load-bearing history and must stay stable. This screen
 * asks the cheaper, wider question — "does this text look like something a
 * person said?" — and answers it without deleting a cue or decoding a second of
 * audio.
 *
 * Two measured facts set the rules, both against the 446-run historic corpus
 * exported on 2026-09-09:
 *
 * 1. **Loops are short.** The 2026-09-09 correctness review screened 125 saved
 *    sermon transcripts holding 222 repetition blocks. Locating every one of
 *    them in the service cues gives a median of 24.4 seconds and exactly one at
 *    120 seconds — so the recovery detector's 120-second floor excludes all of
 *    them, and a floor is the wrong instrument. Matching is over the cue *word*
 *    stream rather than cue strings, because the same loop is grouped into cues
 *    differently on either side of a retry and exact cue equality then misses it.
 *
 * 2. **Density measures the same loops over again.** Sustained words-per-minute
 *    at or above the physical ceiling flags 16,614 seconds of the corpus, of
 *    which 30 — one block, in run 929 — are not already inside a repeated-phrase
 *    block. It is kept as a backstop for near-repeats the verbatim rule cannot
 *    see, not as a second rule of its own. Relaxing it towards ordinary speech
 *    is what breaks it: at 250 words per minute it starts returning genuine
 *    preaching.
 *
 * The repeat threshold is where legitimate repetition is preserved. At five or
 * more verbatim back-to-back repeats the corpus offers no genuine example —
 * "let's stand" twenty-three times across 136 seconds is a decode looping over
 * music, not a congregation. Real repetition sits at three and four: "tell me
 * the old old story", "my comfort my comfort my comfort", the doxology. Lowering
 * the threshold to catch those would hold most of the corpus for nothing.
 */
class ServiceTranscriptRepetitionScreen
{
    /**
     * Screen one transcript, returning disjoint blocks in start order.
     *
     * Pure: no configuration is read that a caller cannot see, and the same
     * transcript always screens the same way, so a recorded result can be
     * re-derived rather than trusted.
     *
     * @return list<SuspectTranscriptBlock>
     */
    public function screen(ChurchServiceTranscript $transcript): array
    {
        $words = $this->wordStream($transcript);

        $blocks = $this->repeatedPhraseBlocks($transcript, $words);
        $blocks = [...$blocks, ...$this->implausibleDensityBlocks($transcript, $words, $blocks)];

        usort($blocks, static fn (SuspectTranscriptBlock $left, SuspectTranscriptBlock $right): int => $left->start <=> $right->start);

        return $blocks;
    }

    /**
     * The blocks that overlap any of the given spans.
     *
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @param  list<array{start: float, end: float}>  $spans
     * @return list<SuspectTranscriptBlock>
     */
    public function within(array $blocks, array $spans): array
    {
        return array_values(array_filter(
            $blocks,
            static function (SuspectTranscriptBlock $block) use ($spans): bool {
                foreach ($spans as $span) {
                    if ($block->overlaps((float) $span['start'], (float) $span['end'])) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * Every word of the transcript in order, each carrying the timing of the cue
     * it came from.
     *
     * A block's bounds are the bounds of the cues its first and last words fall
     * in, which is deliberately generous: a loop that begins mid-cue took the
     * whole cue with it, and holding a second too much costs nothing while
     * holding a second too little leaves the reviewer reading the loop.
     *
     * @return list<array{word: string, start: float, end: float}>
     */
    private function wordStream(ChurchServiceTranscript $transcript): array
    {
        $stream = [];

        foreach ($transcript->cues as $cue) {
            foreach ($this->normalisedWords($cue['text']) as $word) {
                $stream[] = ['word' => $word, 'start' => $cue['start'], 'end' => $cue['end']];
            }
        }

        return $stream;
    }

    /** @return list<string> */
    private function normalisedWords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';

        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Maximal runs of a phrase repeated verbatim back to back.
     *
     * Scanned left to right, taking the longest run available at each position
     * and resuming after it, so one loop yields one block however many phrase
     * lengths happen to describe it — an eight-word phrase repeated twelve times
     * is also a sixteen-word phrase repeated six times, and reporting both would
     * double-count the same seconds.
     *
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @return list<SuspectTranscriptBlock>
     */
    private function repeatedPhraseBlocks(ChurchServiceTranscript $transcript, array $words): array
    {
        $minimumRepeats = (int) config('media-processing.service_structure.repetition_screen.min_repeats', 5);
        $minimumWords = (int) config('media-processing.service_structure.repetition_screen.min_repeated_words', 40);
        $shortestPhrase = (int) config('media-processing.service_structure.repetition_screen.min_phrase_words', 3);
        $longestPhrase = (int) config('media-processing.service_structure.repetition_screen.max_phrase_words', 25);

        $total = count($words);
        $blocks = [];
        $index = 0;

        while ($index < $total) {
            $run = null;

            for ($length = $shortestPhrase; $length <= $longestPhrase; $length++) {
                // Even a perfect run of this phrase length cannot reach the
                // minimum repeats before the stream ends, and every longer
                // phrase is worse, so nothing after this point can match.
                if ($index + $length * $minimumRepeats > $total) {
                    break;
                }

                $repeats = 1;
                $next = $index + $length;

                while ($next + $length <= $total && $this->phrasesMatch($words, $index, $next, $length)) {
                    $repeats++;
                    $next += $length;
                }

                if ($repeats < $minimumRepeats || $repeats * $length < $minimumWords) {
                    continue;
                }

                if ($run === null || $next > $run['end']) {
                    $run = ['end' => $next, 'length' => $length, 'repeats' => $repeats];
                }
            }

            if ($run === null) {
                $index++;

                continue;
            }

            $blocks[] = $this->block(
                $words,
                $index,
                $run['end'],
                SuspectTranscriptBlock::REASON_REPEATED_PHRASE,
                implode(' ', array_column(array_slice($words, $index, $run['length']), 'word')),
                $run['repeats'],
            );

            $index = $run['end'];
        }

        return $blocks;
    }

    /**
     * @param  list<array{word: string, start: float, end: float}>  $words
     */
    private function phrasesMatch(array $words, int $left, int $right, int $length): bool
    {
        for ($offset = 0; $offset < $length; $offset++) {
            if ($words[$left + $offset]['word'] !== $words[$right + $offset]['word']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sustained stretches whose word rate exceeds what a speaker can produce.
     *
     * Only the parts not already held as a repeated phrase are returned, so the
     * blocks stay disjoint and the covered seconds mean what they say.
     *
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<SuspectTranscriptBlock>  $repeated
     * @return list<SuspectTranscriptBlock>
     */
    private function implausibleDensityBlocks(ChurchServiceTranscript $transcript, array $words, array $repeated): array
    {
        $ceiling = (float) config('media-processing.service_structure.repetition_screen.max_words_per_minute', 400);
        $minimumSeconds = (float) config('media-processing.service_structure.repetition_screen.density_window_seconds', 30);

        $total = count($words);
        $marked = [];
        $end = 0;

        for ($start = 0; $start < $total; $start++) {
            $end = max($end, $start + 1);

            while ($end < $total && $words[$end - 1]['end'] - $words[$start]['start'] < $minimumSeconds) {
                $end++;
            }

            $seconds = $words[$end - 1]['end'] - $words[$start]['start'];

            if ($seconds < $minimumSeconds) {
                break;
            }

            if (($end - $start) / $seconds * 60 >= $ceiling) {
                $marked[] = ['start' => $words[$start]['start'], 'end' => $words[$end - 1]['end']];
            }
        }

        $held = array_map(
            static fn (SuspectTranscriptBlock $block): array => ['start' => $block->start, 'end' => $block->end],
            $repeated,
        );

        $blocks = [];

        foreach ($this->subtract($this->union($marked), $this->union($held)) as $interval) {
            if ($minimumSeconds > $interval['end'] - $interval['start']) {
                continue;
            }

            $blocks[] = $this->intervalBlock($transcript, $interval['start'], $interval['end']);
        }

        return $blocks;
    }

    /**
     * @param  list<array{word: string, start: float, end: float}>  $words
     */
    private function block(
        array $words,
        int $from,
        int $to,
        string $reason,
        ?string $phrase = null,
        ?int $repeats = null,
    ): SuspectTranscriptBlock {
        $start = $words[$from]['start'];
        $end = $words[$to - 1]['end'];
        $seconds = $end - $start;

        return new SuspectTranscriptBlock(
            start: $start,
            end: $end,
            reason: $reason,
            words: $to - $from,
            // A zero-length block is a decode that emitted many words against a
            // single instant. The corpus holds such blocks, and their rate is
            // unbounded rather than measurable, so it is recorded as null: `INF`
            // is not JSON-encodable and would fail the write that stores it.
            wordsPerMinute: $seconds > 0.0 ? ($to - $from) / $seconds * 60 : null,
            phrase: $phrase,
            repeats: $repeats,
        );
    }

    private function intervalBlock(ChurchServiceTranscript $transcript, float $start, float $end): SuspectTranscriptBlock
    {
        $words = 0;

        foreach ($transcript->cues as $cue) {
            if ($cue['end'] > $start && $cue['start'] < $end) {
                $words += count($this->normalisedWords($cue['text']));
            }
        }

        $seconds = $end - $start;

        return new SuspectTranscriptBlock(
            start: $start,
            end: $end,
            reason: SuspectTranscriptBlock::REASON_IMPLAUSIBLE_DENSITY,
            words: $words,
            wordsPerMinute: $seconds > 0.0 ? $words / $seconds * 60 : null,
        );
    }

    /**
     * @param  list<array{start: float, end: float}>  $intervals
     * @return list<array{start: float, end: float}>
     */
    private function union(array $intervals): array
    {
        usort($intervals, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $merged = [];

        foreach ($intervals as $interval) {
            $last = array_key_last($merged);

            if ($last !== null && $interval['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $interval['end']);

                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }

    /**
     * @param  list<array{start: float, end: float}>  $from
     * @param  list<array{start: float, end: float}>  $remove
     * @return list<array{start: float, end: float}>
     */
    private function subtract(array $from, array $remove): array
    {
        $remaining = [];

        foreach ($from as $interval) {
            $pieces = [$interval];

            foreach ($remove as $cut) {
                $next = [];

                foreach ($pieces as $piece) {
                    if ($cut['end'] <= $piece['start'] || $cut['start'] >= $piece['end']) {
                        $next[] = $piece;

                        continue;
                    }

                    if ($piece['start'] < $cut['start']) {
                        $next[] = ['start' => $piece['start'], 'end' => $cut['start']];
                    }

                    if ($piece['end'] > $cut['end']) {
                        $next[] = ['start' => $cut['end'], 'end' => $piece['end']];
                    }
                }

                $pieces = $next;
            }

            $remaining = [...$remaining, ...$pieces];
        }

        return $remaining;
    }
}
