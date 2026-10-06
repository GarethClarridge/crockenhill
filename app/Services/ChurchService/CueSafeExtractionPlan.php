<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;

/** Put output edges in measured word pauses; no-word windows retain whole-cue widening. */
class CueSafeExtractionPlan
{
    /** Seconds within which an output edge counts as sitting on a cue's boundary. */
    private const CUE_BOUNDARY_TOLERANCE = 0.05;

    /** Seconds from the cue boundary within which its edge word must be heard to anchor the cut. */
    private const ANCHOR_REACH = 2.0;

    /** Words of the cue's opening or closing run that must be heard in order to anchor the cut. */
    private const ANCHOR_RUN = 3;

    /**
     * The audit reason for an edge on a cue boundary whose opening or closing run is heard more
     * than once within reach: the words cannot say which occurrence the cue starts or ends with.
     */
    public const AMBIGUOUS_CUE_ANCHOR = 'ambiguous_cue_anchor';

    /**
     * How far past a song's end its outro may run before the rule gives up and the word pause
     * places the edge as for speech.
     */
    private const SONG_END_REACH = 30.0;

    /** A cue starting this close before a song's end can be the speech that ends it (1304's benediction). */
    private const SONG_END_LEAD = 0.5;

    /** Seconds of a song's own singing before its end that the pause ending it may reach back into. */
    private const SONG_END_PAUSE_REACH = 1.0;

    /** A cue mostly below the run's threshold is whisper hearing words in silence (1028's "Thank you."). */
    private const SILENT_CUE_SHARE = 0.8;

    /** Seconds below the threshold that count as the silence a song ends at. */
    private const SONG_END_SILENCE_SECONDS = 1.0;

    /** Seconds of the silence kept after the last sound, so the fade is not clipped. */
    private const SONG_END_SILENCE_TAIL = 0.3;

    /** @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>} */
    public function forSection(ServiceSection $section): array
    {
        return $this->forSpans(
            $section->processingLog,
            [['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]],
            songEnds: $section->section_type === ServiceSectionType::Song,
        );
    }

    /**
     * Where the speech that ends each span would start, were the span a song: the edges whose word
     * timings {@see self::forSection()} reads for a song's end, beyond the span's own.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return list<float>
     */
    public function songEndSpeechEdges(MediaProcessingLog $log, array $spans): array
    {
        $cues = app(OutputEdgeWordTimings::class)->cues($log);
        $levels = $this->levels($log);
        $edges = [];

        foreach ($spans as $span) {
            $cue = $this->speechAfterSong($cues, (float) $span['end_time'], $levels);

            if ($cue !== null) {
                $edges[] = $cue['start'];
            }
        }

        return $edges;
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  bool  $songEnds  Each span is a song: its end runs on to silence or the next speech
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>}
     */
    public function forSpans(MediaProcessingLog $log, array $spans, bool $songEnds = false): array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        $levels = $songEnds ? $this->levels($log) : null;
        $audit = [];
        foreach ($spans as $index => &$span) {
            foreach (['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $original = $span[$key];
                $time = $original;
                $window = $evidence->window($cues, $original, $log->duration);
                if ($songEnds && $edge === 'end') {
                    $decision = $this->songEnd($log, $cues, $window, $original, $levels);
                    if ($decision !== null) {
                        $span[$key] = $decision['time'];
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                            'time' => $decision['time'], 'seconds_added' => abs($decision['time'] - $original),
                            'seconds_moved' => $decision['time'] - $original, 'window' => $window,
                            'cue' => $window['cues'][0] ?? null, 'cues' => $window['cues'] ?? [],
                            ...$decision, 'text_disagreement' => false];

                        continue;
                    }
                }
                if ($window !== null) {
                    $payload = $evidence->read($log, $window);
                    $words = $payload['words'];
                    if ($words !== []) {
                        $decision = $this->cueBoundaryPause($words, $window, $original, $edge) ?? $this->pause($words, $window, $original);
                        $span[$key] = $decision['time'];
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                            'time' => $decision['time'], 'seconds_added' => abs($decision['time'] - $original),
                            'seconds_moved' => $decision['time'] - $original, 'window' => $window,
                            'cue' => $window['cues'][0], 'cues' => $window['cues'],
                            ...$decision, 'reason' => isset($decision['candidate_anchors']) ? self::AMBIGUOUS_CUE_ANCHOR : 'word_pause',
                            'text_disagreement' => $this->disagrees($words, $window['cues'])];

                        continue;
                    }
                }
                $included = [];
                do {
                    $before = $time;
                    foreach ($cues as $cue) {
                        if ($cue['start'] < $time - 0.001 && $cue['end'] > $time + 0.001) {
                            $time = $edge === 'start' ? min($time, $cue['start']) : max($time, $cue['end']);
                            if (! in_array($cue, $included, true)) {
                                $included[] = $cue;
                            }
                        }
                    }
                } while ($before !== $time);
                $span[$key] = $time;
                if ($time !== $original || $window !== null) {
                    $included = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] < max($time, $original) && $cue['end'] > min($time, $original)));
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                        'time' => $time, 'seconds_added' => abs($time - $original), 'seconds_moved' => $time - $original,
                        'cue' => $included[0] ?? ($window['cues'][0] ?? null), 'cues' => $included,
                        'window' => $window, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                        'reason' => 'no_words_whole_cue', 'text_disagreement' => false];
                }
            }
        }
        unset($span);
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span['start_time'] <= $merged[$last]['end_time']) {
                $merged[$last]['end_time'] = max($merged[$last]['end_time'], $span['end_time']);

                continue;
            }
            $merged[] = $span;
        }

        return ['segments' => $merged, 'cue_edge_widening' => $audit];
    }

    /**
     * The edges a plan could not place: an ambiguous cue anchor. Their cut keeps every occurrence
     * the cue might start or end with, so nothing of the cue is dropped, but whether the extra
     * words belong to this output is a question; a consumer must not execute the plan unasked.
     *
     * @param  list<array<string, mixed>>  $audit  a plan's `cue_edge_widening`
     * @return list<array{span_index: int, edge: string, original_time: float}>
     */
    public static function unresolvedEdges(array $audit): array
    {
        return array_values(array_map(
            static fn (array $entry): array => ['span_index' => (int) $entry['span_index'], 'edge' => (string) $entry['edge'], 'original_time' => (float) $entry['original_time']],
            array_filter($audit, static fn (array $entry): bool => ($entry['reason'] ?? null) === self::AMBIGUOUS_CUE_ANCHOR),
        ));
    }

    /**
     * Held sections a final cut reaches into, other than the sections it is cutting. Holds are
     * checked against section bounds before edges widen into words and cues; a widened edge can
     * then carry held content into the output through a cue it shares with the held section.
     *
     * @param  list<array{start_time: float, end_time: float}>  $segments
     * @param  list<int>  $cutSectionIds
     * @return list<int>
     */
    public function heldSectionsCrossed(MediaProcessingLog $log, array $segments, array $cutSectionIds): array
    {
        return array_values($log->serviceSections()->orderBy('start_time')->orderBy('id')->get()
            ->filter(static fn (ServiceSection $section): bool => ! in_array($section->id, $cutSectionIds, true)
                && HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? [])
                && array_any($segments, static fn (array $segment): bool => $segment['start_time'] < (float) $section->end_time - 0.001
                    && $segment['end_time'] > (float) $section->start_time + 0.001))
            ->map(static fn (ServiceSection $section): int => $section->id)->all());
    }

    /**
     * A song ends when someone starts talking or at silence, whichever comes first (operator,
     * 2026-10-06), not at its last word: a sung last word is held, and the outro has no words at
     * all. The speech is the first cue at or just before the end that is not whisper hearing words
     * in silence, and the cut goes in the pause just before its first word. That pause may reach a
     * second back into the section, for speech that starts as the singing stops, but no further:
     * the largest pause near the old edge sat before 1250 §3131's last sung line. Silence is the
     * run's level below its threshold for a second where no word is sounding. Neither within reach
     * leaves the edge to the word pause, as for speech.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}|null  $window  The window around the song's end
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return array{time: float, chosen_pause: array{start: float, end: float}|null, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, reason: string, speech_cue: array{start: float, end: float, text: string}|null}|null
     */
    private function songEnd(MediaProcessingLog $log, array $cues, ?array $window, float $end, ?array $levels): ?array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $heard = $window !== null ? $evidence->read($log, $window)['words'] : [];
        $speech = $this->speechAfterSong($cues, $end, $levels);
        $onset = null;

        if ($speech !== null) {
            $speechWindow = $evidence->window($cues, $speech['start'], $log->duration);
            $speechWords = $speechWindow !== null ? $evidence->read($log, $speechWindow)['words'] : [];
            $heard = [...$heard, ...$speechWords];
            $onset = $speechWords === [] || $speechWindow === null
                ? ['time' => $speech['start'], 'chosen_pause' => null, 'word_before' => null, 'word_after' => null]
                : $this->pauseBeforeSpeech($speechWords, $speechWindow, $speech['start'], min($end, $speech['start']) - self::SONG_END_PAUSE_REACH);
        }

        $until = $onset['time'] ?? $end + self::SONG_END_REACH;
        $lastSound = $end;

        foreach ($heard as $word) {
            if ($word['start'] < $until) {
                $lastSound = max($lastSound, $word['end']);
            }
        }

        $silence = $levels !== null ? $this->silenceOnset($levels, $lastSound, $until) : null;

        if ($silence !== null) {
            return ['time' => $silence, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                'reason' => 'song_end_silence', 'speech_cue' => $speech];
        }

        return $onset === null ? null : [...$onset, 'reason' => 'song_end_speech', 'speech_cue' => $speech];
    }

    /**
     * The pause the speech after a song starts out of: the one before its cue's opening words when
     * they are heard, otherwise the largest pause before a word that ends no earlier than the floor.
     * The cut sits just inside its end, so the outro runs right up to the first word.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}|null
     */
    private function pauseBeforeSpeech(array $words, array $window, float $speechStart, float $floor): ?array
    {
        $anchored = $this->cueBoundaryPause($words, $window, $speechStart, 'start');

        if ($anchored !== null && ! isset($anchored['candidate_anchors']) && $anchored['chosen_pause']['end'] >= $floor) {
            $pause = ['start' => $anchored['chosen_pause']['start'], 'end' => $anchored['chosen_pause']['end'],
                'word_before' => $anchored['word_before'], 'word_after' => $anchored['word_after']];
        } else {
            $eligible = array_values(array_filter($this->pauses($words, $window),
                static fn (array $pause): bool => $pause['word_after'] !== null && $pause['end'] >= $floor));

            if ($eligible === []) {
                return ['time' => $speechStart, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null];
            }

            usort($eligible, static fn (array $a, array $b): int => ($b['length'] <=> $a['length']) ?: ($a['start'] <=> $b['start']));
            $pause = $eligible[0];
        }

        return ['time' => $pause['end'] - min(0.1, ($pause['end'] - $pause['start']) / 2),
            'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    /**
     * The first cue at or just before a song's end that is not whisper hearing words in silence.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return array{start: float, end: float, text: string}|null
     */
    private function speechAfterSong(array $cues, float $end, ?array $levels): ?array
    {
        $after = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] >= $end - self::SONG_END_LEAD
            && $cue['start'] <= $end + self::SONG_END_REACH));
        usort($after, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($after as $cue) {
            if ($levels === null) {
                return $cue;
            }

            $inside = array_filter($levels['samples'], static fn (array $sample): bool => $sample['time'] >= $cue['start'] && $sample['time'] < $cue['end']);
            $silent = array_filter($inside, static fn (array $sample): bool => $sample['rms'] < $levels['threshold']);

            if ($inside === [] || count($silent) / count($inside) < self::SILENT_CUE_SHARE) {
                return $cue;
            }
        }

        return null;
    }

    /**
     * Where the first second of silence after the last sound starts, plus a short tail, or null.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     */
    private function silenceOnset(array $levels, float $from, float $until): ?float
    {
        $runStart = null;

        foreach ($levels['samples'] as $sample) {
            if ($sample['time'] < $from) {
                continue;
            }

            if ($sample['rms'] >= $levels['threshold']) {
                if ($runStart !== null && $runStart >= $until) {
                    return null;
                }
                $runStart = null;

                continue;
            }

            $runStart ??= $sample['time'];

            if ($runStart >= $until) {
                return null;
            }

            if ($sample['time'] - $runStart >= self::SONG_END_SILENCE_SECONDS - 0.0001) {
                return $runStart + self::SONG_END_SILENCE_TAIL;
            }
        }

        return null;
    }

    /**
     * The run's sound levels and the threshold its silence is measured against, as
     * {@see SongSpeechEdges} reads them, or null when the run has no level log.
     *
     * @return array{samples: list<array{time: float, rms: float}>, threshold: float}|null
     */
    private function levels(MediaProcessingLog $log): ?array
    {
        $path = $log->rms_log_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $content = Storage::disk(ServiceArtifactDisk::for($path))->get($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($content) || $content === '') {
            return null;
        }

        $rms = app(RmsAnalysisService::class);
        $samples = $rms->extractRmsData($content);

        if ($samples === []) {
            return null;
        }

        try {
            $threshold = (float) $rms->determineThreshold($content)['threshold'];
        } catch (SegmentationException) {
            $threshold = $rms->getRmsThreshold();
        }

        usort($samples, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return ['samples' => $samples, 'threshold' => $threshold];
    }

    /**
     * An edge on a cue boundary already decided the cue is in the output, so the cut goes in the
     * pause just outside that cue's own words: before its first word at a start, after its last
     * word at an end. The largest-pause rule cannot see this side: the window margin beyond the
     * cue can be the largest gap, and cutting there drops the whole cue (949's last verse), while
     * a word timed before its cue starts (964's "During") is lost to a smaller gap inside the
     * sentence. The cue's edge is found by its opening or closing run of words near the boundary,
     * so a repeated "the" nearer the boundary cannot stand in for it; when no run matches, null
     * hands the edge back to the largest pause.
     *
     * A run heard more than once within reach is ambiguous (F03). Choosing an occurrence, or the
     * largest pause, can cut after the cue's own words; so the cut goes outside the outermost
     * occurrence, keeping every one, and `candidate_anchors` marks the edge unresolved for review.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}|null
     */
    private function cueBoundaryPause(array $words, array $window, float $original, string $edge): ?array
    {
        $isStart = $edge === 'start';
        // A cue touching the edge from the other side shares the boundary: its words may belong
        // to this output too (949's "thank you Mark"), so only the largest pause can place it.
        if (count($window['cues']) !== 1) {
            return null;
        }
        $cue = $window['cues'][0];
        if (abs(($isStart ? $cue['start'] : $cue['end']) - $original) > self::CUE_BOUNDARY_TOLERANCE) {
            return null;
        }
        $cueTokens = explode(' ', $this->normalize($cue['text']));
        if ($cueTokens === ['']) {
            return null;
        }
        $run = $isStart ? array_slice($cueTokens, 0, self::ANCHOR_RUN) : array_slice($cueTokens, -self::ANCHOR_RUN);
        usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $tokens = [];
        foreach ($words as $index => $word) {
            foreach (explode(' ', $this->normalize($word['word'])) as $token) {
                if ($token !== '') {
                    $tokens[] = ['token' => $token, 'word' => $index];
                }
            }
        }
        $anchors = [];
        for ($at = 0; $at + count($run) <= count($tokens); $at++) {
            if (array_column(array_slice($tokens, $at, count($run)), 'token') !== $run) {
                continue;
            }
            $index = $tokens[$isStart ? $at : $at + count($run) - 1]['word'];
            if (abs(($isStart ? $words[$index]['start'] : $words[$index]['end']) - $original) <= self::ANCHOR_REACH) {
                $anchors[$index] = true;
            }
        }
        if ($anchors === []) {
            return null;
        }
        ksort($anchors);
        $candidates = [];
        foreach (array_keys($anchors) as $index) {
            $candidates[] = $words[$index];
        }
        // Words are in start order, so the first anchor opens earliest; the closing anchor is the
        // one that ends latest.
        $anchor = $candidates[0];
        foreach ($candidates as $candidate) {
            if (! $isStart && $candidate['end'] > $anchor['end']) {
                $anchor = $candidate;
            }
        }
        // The nearest gap no word is still sounding in: a word overlapping the anchor stays whole.
        $pauses = array_filter($this->pauses($words, $window), static fn (array $pause): bool => $isStart
            ? $pause['end'] <= $anchor['start'] : $pause['start'] >= $anchor['end']);
        if ($pauses === [] && count($candidates) === 1) {
            return null;
        }
        if ($pauses === []) {
            $decision = $this->pause($words, $window, $original);
        } else {
            $pause = $isStart ? $pauses[array_key_last($pauses)] : $pauses[array_key_first($pauses)];
            $decision = ['time' => max($pause['start'], min($original, $pause['end'])),
                'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
                'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
        }
        if (count($candidates) > 1) {
            $decision['candidate_anchors'] = $candidates;
        }

        return $decision;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function pause(array $words, array $window, float $original): array
    {
        $pauses = $this->pauses($words, $window);
        if ($pauses === []) {
            throw new \RuntimeException('edge_word_timings_invalid: no pause adjacent to edge cues');
        }
        usort($pauses, static function (array $a, array $b) use ($original): int {
            $size = $b['length'] <=> $a['length'];

            return $size ?: abs(max($a['start'], min($original, $a['end'])) - $original) <=> abs(max($b['start'], min($original, $b['end'])) - $original);
        });
        $pause = $pauses[0];

        return ['time' => max($pause['start'], min($original, $pause['end'])),
            'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    /**
     * Gaps in the window that no word is sounding in, in time order. The frontier is the latest
     * end of every word so far, so a gap after a short word inside a longer one is not a pause.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float}  $window
     * @return list<array{start: float, end: float, length: float, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}>
     */
    private function pauses(array $words, array $window): array
    {
        usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $pauses = [];
        $left = null;
        $frontier = $window['start'];
        foreach ([...$words, null] as $right) {
            $end = $right['start'] ?? $window['end'];
            if ($end >= $frontier) {
                $pauses[] = ['start' => $frontier, 'end' => $end, 'length' => $end - $frontier, 'word_before' => $left, 'word_after' => $right];
            }
            if ($right !== null && $right['end'] >= $frontier) {
                $frontier = $right['end'];
                $left = $right;
            }
        }

        return $pauses;
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function disagrees(array $words, array $cues): bool
    {
        $normalize = $this->normalize(...);
        $inCue = array_filter($words, static function (array $word) use ($cues): bool {
            foreach ($cues as $cue) {
                if ($word['start'] < $cue['end'] && $word['end'] > $cue['start']) {
                    return true;
                }
            }

            return false;
        });

        return $normalize(implode(' ', array_column($inCue, 'word'))) !== $normalize(implode(' ', array_column($cues, 'text')));
    }
}
