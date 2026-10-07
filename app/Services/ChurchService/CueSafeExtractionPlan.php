<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Enums\SoundClass;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Exceptions\SegmentationException;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\Media\Audio\UntranscribedSpeechRecovery;
use App\Services\Media\Audio\AudioTimeline;
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

    /**
     * The shortest and longest fade of a song's sound into the speech after it (operator,
     * 2026-10-07): the fade spans the gap from the last sung sound, within these bounds, and ends
     * at the cut. A longer outro plays on until its last seconds.
     */
    private const SONG_END_FADE_MIN = 1.5;

    private const SONG_END_FADE_MAX = 4.0;

    /**
     * How far before a song's end its singing may stop and speech start inside the section: the
     * window around the end reaches back over the cues touching it (1028 §1400's benediction
     * started 29 s before it, 1108 §1896's "Thank you, Aled" 15 s).
     */
    private const SONG_END_INSIDE_REACH = 30.0;

    /**
     * A decoded word longer than this is smeared over sound it was not said in (1108's "close"
     * over 7 s of outro, "Thank" over 10 s of silence); spoken words measured under 1.5 s.
     */
    private const LONGEST_HEARD_WORD = 1.5;

    /**
     * Words the classifier hears as speech come at speaking pace: every speech onset canary 12
     * misplaced began with two words under a second each ("May the", "That truly", "the
     * benediction"), while 1311 §4931's last sung line decoded as "was" (1.3 s) "a" "great" (1.8 s).
     */
    private const LONGEST_SPOKEN_WORD = 1.0;

    /** A decode window margin before a word at least this long shows nothing was said there. */
    private const OBSERVED_GAP = 0.1;

    /**
     * A gap this long after a long word is observed whatever that word was: 1250 §4911's held
     * "all" before 6.9 s of outro. Shorter gaps after a smeared word are not (1304's "Music" ran
     * to 0.02 s before "In", 1108's "you" to 0.44 s before "Aled").
     */
    private const OBSERVED_PAUSE = 0.5;

    /** The shortest quiet an unresolved song end may sit in: a pause between phrases. */
    private const SONG_END_QUIET_SECONDS = 0.25;

    /**
     * The audit reason for a song end with nothing to say where speech after it starts: no heard
     * word, cue or classifier window before the reach. Whether the cut is right is a question, so
     * a consumer must not execute the plan unasked.
     */
    public const SONG_END_UNRESOLVED = 'song_end_onset_unresolved';

    /**
     * The audit reason for a song end at speech whose onset the evidence cannot establish (canary
     * 12, B1): its opening words are not heard, or only the cue or the classifier says where the
     * speech starts. Canary 13 refused 23 of 92 song ends this way. Its sound fades out instead
     * (operator, 2026-10-07): any unidentified words at the cut fade with the song, which sounds
     * deliberate, so the cut is no longer a question.
     */
    public const SONG_END_FADE = 'song_end_fade';

    /**
     * The audit reason for a cut widened over speech recovery left without words (F11): it goes
     * into both outputs bordering it (operator, 2026-10-06), since no edge rule can place it.
     */
    public const UNRESOLVED_INTERVAL = 'unresolved_interval_in_both';

    /**
     * The audit reason for a sermon end beside a song whose last spoken line leaves its thought
     * unfinished, where nothing shows the next line is speech: whether to cut there is asked.
     */
    public const SPOKEN_END_UNRESOLVED = 'spoken_end_thought_unresolved';

    /** Where an operator's answers to refused edges are kept on the run. */
    public const EDGE_ANSWERS_KEY = 'unresolved_edge_answers';

    /** The audit reason for a refused edge an operator has answered; its old reason is kept. */
    public const OPERATOR_ANSWERED_EDGE = 'operator_answered_edge';

    /** Seconds within which a section's bound borders an unresolved interval. */
    private const INTERVAL_REACH = 0.5;

    /** @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>, audio_fade_out: float|null} */
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
        $timeline = $this->timeline($log);
        $edges = [];

        foreach ($spans as $span) {
            $end = (float) $span['end_time'];
            $cue = $this->speechAfterSong($cues, $end, $levels, $timeline, $this->singingStops($timeline, $end));

            if ($cue !== null) {
                $edges[] = $cue['start'];
            }
        }

        return $edges;
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  bool  $songEnds  Each span is a song: its end runs on to silence or the next speech
     * @param  bool  $sermonEnd  The last span ends a sermon output: beside a song it takes the spoken stretch before the singing (A)
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>, audio_fade_out: float|null}
     */
    public function forSpans(MediaProcessingLog $log, array $spans, bool $songEnds = false, bool $sermonEnd = false): array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        $levels = $songEnds ? $this->levels($log) : null;
        $timeline = $songEnds || $sermonEnd ? $this->timeline($log) : null;
        $songStarts = $sermonEnd ? $this->songStarts($log) : [];
        $last = array_key_last($spans);
        $sectionSpans = $spans;
        $audit = [];
        foreach ($spans as $index => &$span) {
            foreach (['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $original = $span[$key];
                $extendedFrom = null;
                $besideSong = false;
                $thoughtComplete = true;
                if ($edge === 'end' && $index === $last && ($song = $this->songAfter($songStarts, $original)) !== null) {
                    $besideSong = true;
                    ['through' => $through, 'complete' => $thoughtComplete] = $this->spokenThrough($cues, $original, $song, $timeline);
                    if ($through > $original) {
                        [$extendedFrom, $original] = [$original, $through];
                    }
                }
                $time = $original;
                $window = $evidence->window($cues, $original, $log->duration);
                if ($songEnds && $edge === 'end') {
                    $decision = $this->songEnd($log, $cues, $window, $original, $levels, $timeline);
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
                        $decision = $this->cueBoundaryPause($words, $window, $original, $edge)
                            ?? (isset($window['between']) ? $this->nearestPause($words, $window, $original) : $this->pause($words, $window, $original));
                        if ($besideSong) {
                            $decision = $this->withTail($decision);
                        }
                        $reason = isset($decision['candidate_anchors']) ? self::AMBIGUOUS_CUE_ANCHOR : 'word_pause';
                        $sentenceCheck = null;
                        if ($reason === 'word_pause') {
                            [$decision, $sentenceCheck] = $this->sentenceChecked($log, $cues, $words, $window, $original, $edge, $decision);
                            $reason = $sentenceCheck['moved'] ?? false ? 'sentence_check' : $reason;
                        }
                        $span[$key] = $decision['time'];
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $extendedFrom ?? $original,
                            'time' => $decision['time'], 'seconds_added' => abs($decision['time'] - ($extendedFrom ?? $original)),
                            'seconds_moved' => $decision['time'] - ($extendedFrom ?? $original), 'window' => $window,
                            'cue' => $window['cues'][0], 'cues' => $window['cues'],
                            ...$decision, 'reason' => $thoughtComplete ? $reason : self::SPOKEN_END_UNRESOLVED, 'sentence_check' => $sentenceCheck,
                            'text_disagreement' => $this->disagrees($words, $window['cues']),
                            'extended_beside_song_from' => $extendedFrom];

                        continue;
                    }
                }
                $included = [];
                do {
                    $before = $time;
                    foreach ($cues as $cue) {
                        // A cue of punctuation holds no words to keep whole (B2).
                        if (TranscriptCueEvidence::hasWords($cue) && $cue['start'] < $time - 0.001 && $cue['end'] > $time + 0.001) {
                            $time = $edge === 'start' ? min($time, $cue['start']) : max($time, $cue['end']);
                            if (! in_array($cue, $included, true)) {
                                $included[] = $cue;
                            }
                        }
                    }
                } while ($before !== $time);
                $span[$key] = $time;
                if ($time !== $original || $window !== null || $extendedFrom !== null) {
                    $included = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] < max($time, $original) && $cue['end'] > min($time, $original)));
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $extendedFrom ?? $original,
                        'time' => $time, 'seconds_added' => abs($time - ($extendedFrom ?? $original)), 'seconds_moved' => $time - ($extendedFrom ?? $original),
                        'cue' => $included[0] ?? ($window['cues'][0] ?? null), 'cues' => $included,
                        'window' => $window, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                        'reason' => $thoughtComplete ? 'no_words_whole_cue' : self::SPOKEN_END_UNRESOLVED, 'text_disagreement' => false, 'extended_beside_song_from' => $extendedFrom];
                }
            }
        }
        unset($span);
        $audit = $this->withAnswerIdentity($log, $sectionSpans, $songEnds, $sermonEnd, $cues, $levels, $timeline, $audit);
        $spans = $this->withAnsweredEdges($log, $spans, $audit);
        $spans = $this->withUnresolvedIntervals($log, $sectionSpans, $spans, $audit);
        $lastSpan = $last;
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span['start_time'] <= $merged[$last]['end_time']) {
                $merged[$last]['end_time'] = max($merged[$last]['end_time'], $span['end_time']);

                continue;
            }
            $merged[] = $span;
        }

        $fade = array_values(array_filter($audit, static fn (array $entry): bool => ($entry['span_index'] ?? null) === $lastSpan
            && $entry['edge'] === 'end' && isset($entry['fade_out'])))[0]['fade_out'] ?? null;

        return ['segments' => $merged, 'cue_edge_widening' => $audit, 'audio_fade_out' => $songEnds ? $fade : null];
    }

    /**
     * The extended end {@see self::forSpans()} reads for a sermon output followed by a song: the
     * edge whose word timings the edge step must decode, beyond the spans' own.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans  The sermon output's spans
     * @return list<float>
     */
    public function spokenEndsBesideSongs(MediaProcessingLog $log, array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $end = (float) $spans[array_key_last($spans)]['end_time'];
        $song = $this->songAfter($this->songStarts($log), $end);
        $through = $song !== null ? $this->spokenThrough(app(OutputEdgeWordTimings::class)->cues($log), $end, $song, $this->timeline($log))['through'] : $end;

        return $through > $end ? [$through] : [];
    }

    /**
     * Each section's start in time order, and whether it is a song's.
     *
     * @return list<array{start: float, song: bool}>
     */
    private function songStarts(MediaProcessingLog $log): array
    {
        return array_values($log->serviceSections()->orderBy('start_time')->orderBy('id')->get(['section_type', 'start_time'])
            ->map(static fn (ServiceSection $section): array => ['start' => (float) $section->start_time, 'song' => $section->section_type === ServiceSectionType::Song])
            ->all());
    }

    /**
     * The start of the song a spoken end runs into: the next section to start, within a song
     * end's reach, when that section is a song. A spoken item between them keeps its own edge
     * (1117's hymn introduction is a section, not the sermon's stretch).
     *
     * @param  list<array{start: float, song: bool}>  $starts
     */
    private function songAfter(array $starts, float $end): ?float
    {
        foreach ($starts as $start) {
            if ($start['start'] >= $end - self::CUE_BOUNDARY_TOLERANCE) {
                return $start['song'] && $start['start'] <= $end + self::SONG_END_REACH ? $start['start'] : null;
            }
        }

        return null;
    }

    /**
     * Where the spoken stretch before a song ends (canary 12, A): through the last line that
     * starts before the song does and runs past the edge (1304's "Onward, 'tis our Lord's
     * command. | Jesus saves. Let's stand and sing."), then on through each next line the
     * classifier hears as speech where it starts: a song's recorded start is no boundary, and
     * whisper's full stops are no proof a thought is over (canary 11: one handover punctuated as
     * two sentences; Codex review). The stretch is `complete` when the next line starts in music,
     * the singing having begun, or no line follows within reach. It is not when the next line's
     * kind is unknown, or a line ending mid-phrase runs straight into music: a missing full stop
     * shows an unfinished thought, though a full stop never shows a finished one.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{through: float, complete: bool}
     */
    private function spokenThrough(array $cues, float $end, float $songStart, ?AudioTimeline $timeline): array
    {
        $through = $end;
        $last = null;

        foreach ($cues as $cue) {
            if (TranscriptCueEvidence::isEvidence($cue, $timeline) && $cue['start'] < $songStart && $cue['end'] >= $through - 0.001) {
                $through = max($through, $cue['end']);
                $last = $cue;
            }
        }

        $endsMidPhrase = static fn (?array $line): bool => $line !== null && preg_match('/[.!?]["\x{2019}\x{201D}\')]*\s*$/u', $line['text']) !== 1;

        while (true) {
            $next = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] >= $through - 0.001
                && $cue['start'] <= $songStart + self::SONG_END_REACH && TranscriptCueEvidence::isEvidence($cue, $timeline)))[0] ?? null;

            if ($next === null) {
                return ['through' => $through, 'complete' => ! $endsMidPhrase($last)];
            }

            $window = $timeline?->windowAt($next['start']);
            $kind = $timeline === null || $window === null ? null : $timeline->classOf($window);

            if ($timeline !== null && $window !== null && $this->heardAsSpeech($timeline, $window)) {
                $through = $next['end'];
                $last = $next;

                continue;
            }

            return ['through' => $through, 'complete' => $kind === SoundClass::Music && ! $endsMidPhrase($last)];
        }
    }

    /**
     * A spoken end beside a song, moved off the decoded end of its last word into the pause after
     * it: decoded word ends run early (1028's "nine" clipped at "49"'s end).
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function withTail(array $decision): array
    {
        $pause = $decision['chosen_pause'] ?? null;

        if (! is_array($pause) || abs($decision['time'] - $pause['start']) > 0.001) {
            return $decision;
        }

        return [...$decision, 'time' => $pause['start'] + min(self::SONG_END_SILENCE_TAIL, ($pause['end'] - $pause['start']) / 2)];
    }

    /**
     * For an edge between two lines, the pause it sits in, or the one nearest it: the window's
     * margins are not where either line stops.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function nearestPause(array $words, array $window, float $original): array
    {
        $inside = array_values(array_filter($this->pauses($words, $window), static fn (array $pause): bool => $pause['word_before'] !== null && $pause['word_after'] !== null));

        if ($inside === []) {
            return $this->pause($words, $window, $original);
        }

        usort($inside, static fn (array $a, array $b): int => abs(max($a['start'], min($original, $a['end'])) - $original) <=> abs(max($b['start'], min($original, $b['end'])) - $original));
        $pause = $inside[0];

        return ['time' => max($pause['start'], min($original, $pause['end'])),
            'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    /**
     * The identity an answer to a refused edge is given on: the edge, why it was refused, where the
     * evidence put it, the output it is an edge of, and the evidence around it. An answer applies
     * only where all five match (Codex review, 2026-10-07): new evidence that lands on the same
     * times is not what the operator heard, and an answer about one output's edge says nothing
     * about the same edge in another.
     *
     * @param  array<string, mixed>  $entry  A `cue_edge_widening` entry
     */
    public static function edgeAnswerKey(array $entry): string
    {
        return sprintf('%s|%s|%.2f|%.2f|%s|%s', $entry['edge'] ?? '', $entry['unresolved_reason'] ?? $entry['reason'] ?? '',
            (float) ($entry['proposed_time'] ?? $entry['time'] ?? 0.0), (float) ($entry['original_time'] ?? 0.0),
            $entry['output'] ?? '', $entry['evidence'] ?? '');
    }

    /**
     * Each refused edge's output and evidence identity ({@see self::edgeAnswerKey()}). The output
     * is the spans it is planned from and how: a song's clip, a sermon's composed parts. The
     * evidence is everything an edge rule reads near the edge: the transcript's lines, the words
     * decoded at the edge and at the speech after a song, the classifier's windows and the sound
     * levels, within a song end's reach either side.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @param  list<array<string, mixed>>  $audit
     * @return list<array<string, mixed>>
     */
    private function withAnswerIdentity(MediaProcessingLog $log, array $sectionSpans, bool $songEnds, bool $sermonEnd, array $cues, ?array $levels, ?AudioTimeline $timeline, array $audit): array
    {
        $refused = static fn (array $entry): bool => in_array($entry['reason'] ?? null, [self::AMBIGUOUS_CUE_ANCHOR, self::SONG_END_UNRESOLVED, self::SPOKEN_END_UNRESOLVED], true);

        if (! array_any($audit, $refused)) {
            return $audit;
        }

        $output = self::fingerprint(['spans' => array_map(static fn (array $span): array => [round($span['start_time'], 3), round($span['end_time'], 3)], $sectionSpans),
            'song_ends' => $songEnds, 'sermon_end' => $sermonEnd]);
        $levels ??= $this->levels($log);
        $timeline ??= $this->timeline($log);
        $evidence = app(OutputEdgeWordTimings::class);

        foreach ($audit as &$entry) {
            if (! $refused($entry)) {
                continue;
            }

            $times = [(float) $entry['original_time'], (float) $entry['time'], ...array_map(floatval(...), array_filter([$entry['extended_beside_song_from'] ?? null, $entry['onset_bound'] ?? null], is_numeric(...)))];
            [$from, $to] = [min($times) - self::SONG_END_REACH, max($times) + self::SONG_END_REACH];
            $words = [];

            foreach (array_filter([$entry['window'] ?? null, isset($entry['speech_cue']['start']) ? $evidence->window($cues, (float) $entry['speech_cue']['start'], $log->duration) : null]) as $window) {
                try {
                    $words[] = array_map(static fn (array $word): array => [round($word['start'], 3), round($word['end'], 3), $word['word']], $evidence->read($log, $window)['words']);
                } catch (OutputEdgeTimingsMissing) {
                    $words[] = null;
                }
            }

            $entry['output'] = $output;
            $entry['evidence'] = self::fingerprint([
                'cues' => array_values(array_map(static fn (array $cue): array => [round($cue['start'], 3), round($cue['end'], 3), $cue['text']],
                    array_filter($cues, static fn (array $cue): bool => $cue['end'] > $from && $cue['start'] < $to))),
                'words' => $words,
                'timeline' => $timeline === null ? null : array_values(array_map(static fn (array $window): array => [round($window['start'], 3), round($window['music'], 3), round($window['speech'], 3)],
                    array_filter($timeline->windows, static fn (array $window): bool => $window['end'] > $from && $window['start'] < $to))),
                'levels' => $levels === null ? null : [round($levels['threshold'], 2), ...array_values(array_map(static fn (array $sample): array => [round($sample['time'], 2), round($sample['rms'], 2)],
                    array_filter($levels['samples'], static fn (array $sample): bool => $sample['time'] >= $from && $sample['time'] <= $to)))],
            ]);
        }
        unset($entry);

        return $audit;
    }

    /** @param array<string, mixed> $value */
    private static function fingerprint(array $value): string
    {
        return substr(hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), 0, 16);
    }

    /**
     * Refused edges an operator has answered, heard from the excerpts of the recording around
     * them: "right" keeps the cut, "cut_at" moves it to the time given. Only an answer given on
     * this evidence applies ({@see self::edgeAnswerKey()}); the audit keeps the refusal's reason.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start_time: float, end_time: float}>
     */
    private function withAnsweredEdges(MediaProcessingLog $log, array $spans, array &$audit): array
    {
        $answers = $log->processing_metadata?->raw[self::EDGE_ANSWERS_KEY] ?? [];

        if (! is_array($answers) || $answers === []) {
            return $spans;
        }

        foreach ($audit as &$entry) {
            if (! in_array($entry['reason'] ?? null, [self::AMBIGUOUS_CUE_ANCHOR, self::SONG_END_UNRESOLVED, self::SPOKEN_END_UNRESOLVED], true)) {
                continue;
            }

            $key = self::edgeAnswerKey($entry);
            $answer = array_values(array_filter($answers, static fn (mixed $answer): bool => is_array($answer) && ($answer['key'] ?? null) === $key))[0] ?? null;

            if ($answer === null) {
                continue;
            }

            $time = ($answer['decision'] ?? null) === 'cut_at' && is_numeric($answer['time'] ?? null) ? (float) $answer['time'] : (float) $entry['time'];
            $span = $spans[(int) $entry['span_index']];
            $spans[(int) $entry['span_index']] = $entry['edge'] === 'start'
                ? ['start_time' => $time, 'end_time' => $span['end_time']]
                : ['start_time' => $span['start_time'], 'end_time' => $time];
            $entry = [...$entry, 'unresolved_reason' => $entry['reason'], 'reason' => self::OPERATOR_ANSWERED_EDGE, 'proposed_time' => $entry['time'], 'time' => $time, 'answer' => $answer];
        }
        unset($entry);

        return $spans;
    }

    /**
     * Speech recovery tried and could not decode, recorded as an F11 interval on the section after
     * it and as a failed attempt on the run, goes whole into every output whose spans border it: the cut is widened after its edges are
     * placed, so no edge rule takes it back, and before the plan is validated, so held content
     * still blocks it. Within one output the widened spans merge, and the interval is cut once.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans  The spans as the sections bound them
     * @param  list<array{start_time: float, end_time: float}>  $spans  The spans with their edges placed
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start_time: float, end_time: float}>
     */
    private function withUnresolvedIntervals(MediaProcessingLog $log, array $sectionSpans, array $spans, array &$audit): array
    {
        $intervals = $this->unresolvedIntervals($log);

        if ($intervals === []) {
            return $spans;
        }

        $attempts = $log->processing_metadata?->raw[UntranscribedSpeechRecovery::METADATA_KEY] ?? [];

        foreach ($sectionSpans as $index => $bounds) {
            foreach ($intervals as $interval) {
                [$from, $to] = $interval['interval'];
                $borders = ($bounds['start_time'] < $to && $bounds['end_time'] > $from)
                    || abs($bounds['start_time'] - $interval['after_start']) <= self::INTERVAL_REACH
                    || ($interval['before_end'] !== null && abs($bounds['end_time'] - $interval['before_end']) <= self::INTERVAL_REACH);

                // Only speech recovery tried and failed on: a marker no failed attempt stands
                // behind is stale, and must not undo a repair (1025 §1373).
                $failed = array_any(is_array($attempts) ? $attempts : [], static fn (mixed $attempt): bool => is_array($attempt)
                    && (int) ($attempt['cues'] ?? 0) === 0 && (float) ($attempt['start'] ?? INF) < $to && (float) ($attempt['end'] ?? -INF) > $from);

                if (! $borders || ! $failed) {
                    continue;
                }

                $recovery = array_values(array_map(
                    static fn (array $attempt): array => ['start' => (float) $attempt['start'], 'end' => (float) $attempt['end'], 'words' => (int) ($attempt['words'] ?? 0)],
                    array_filter(is_array($attempts) ? $attempts : [], static fn (mixed $attempt): bool => is_array($attempt)
                        && (float) ($attempt['start'] ?? INF) < $to && (float) ($attempt['end'] ?? -INF) > $from),
                ));
                $widened = ['start_time' => min($spans[$index]['start_time'], $from), 'end_time' => max($spans[$index]['end_time'], $to)];

                foreach (['start' => [$spans[$index]['start_time'], $widened['start_time']], 'end' => [$spans[$index]['end_time'], $widened['end_time']]] as $edge => [$was, $time]) {
                    if (abs($time - $was) >= 0.001) {
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $was, 'time' => $time,
                            'seconds_added' => abs($time - $was), 'seconds_moved' => $time - $was,
                            'reason' => self::UNRESOLVED_INTERVAL, 'interval' => [$from, $to], 'section_id' => $interval['section_id'],
                            'recovery' => $recovery, 'text_disagreement' => false];
                    }
                }

                $spans[$index] = $widened;
            }
        }

        usort($spans, static fn (array $a, array $b): int => $a['start_time'] <=> $b['start_time']);

        return $spans;
    }

    /**
     * The F11 intervals the run's sections still record, with the bounds of the two sections
     * either side: the song before and the section that records it.
     *
     * @return list<array{interval: array{0: float, 1: float}, section_id: int, after_start: float, before_end: float|null}>
     */
    private function unresolvedIntervals(MediaProcessingLog $log): array
    {
        $intervals = [];
        $previous = null;

        foreach ($log->serviceSections()->orderBy('start_time')->orderBy('id')->get() as $section) {
            $before = $previous;
            $previous = $section;

            if (! in_array(ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION, $section->metadata->reviewFlags ?? [], true)) {
                continue;
            }

            $notes = $section->metadata->raw['ai_notes'] ?? [];

            foreach (is_array($notes) ? $notes : [] as $note) {
                $interval = is_string($note) ? UntranscribedSpeechBeforeSection::interval($note) : null;

                if ($interval !== null) {
                    $intervals[] = ['interval' => $interval, 'section_id' => $section->id, 'after_start' => (float) $section->start_time,
                        'before_end' => $before !== null ? (float) $before->end_time : null];
                }
            }
        }

        return $intervals;
    }

    /**
     * Where the word pause puts an edge, before any sentence check: the cut
     * {@see SpokenEdgeSentenceCheck::prepare()} asks about. Null when the edge has no decoded
     * words or its cue anchor is ambiguous, which the check leaves alone.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{time: float, window: array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}, words: list<array{start: float, end: float, word: string}>}|null
     */
    public function wordPauseEdge(MediaProcessingLog $log, array $cues, float $original, string $edge): ?array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($cues, $original, $log->duration);

        if ($window === null) {
            return null;
        }

        try {
            $words = $evidence->read($log, $window)['words'];
        } catch (OutputEdgeTimingsMissing) {
            return null;
        }

        if ($words === []) {
            return null;
        }

        $decision = $this->cueBoundaryPause($words, $window, $original, $edge) ?? $this->pause($words, $window, $original);

        return isset($decision['candidate_anchors']) ? null : ['time' => $decision['time'], 'window' => $window, 'words' => $words];
    }

    /**
     * The transcript between two times as timed tokens: the decoded words inside the window, and
     * outside it each cue's words spread evenly over the cue. A cue running into the window keeps
     * only the words the window's decode does not already hold.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float}  $window
     * @return list<array{start: float, end: float, word: string}>
     */
    public static function tokens(array $cues, array $words, array $window, float $from, float $to): array
    {
        $inWindow = array_values(array_filter($words, static fn (array $word): bool => ($word['start'] + $word['end']) / 2 >= $window['start']
            && ($word['start'] + $word['end']) / 2 <= $window['end']));
        $tokens = array_map(static fn (array $word): array => ['start' => $word['start'], 'end' => $word['end'], 'word' => trim($word['word'])], $inWindow);

        foreach ($cues as $cue) {
            if ($cue['end'] <= $from || $cue['start'] >= $to || ($cue['start'] >= $window['start'] && $cue['end'] <= $window['end'])) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($cue['text'])) ?: [];
            $count = count($parts);

            if ($count === 0) {
                continue;
            }

            $decoded = count(array_filter($inWindow, static fn (array $word): bool => $word['start'] < $cue['end'] && $word['end'] > $cue['start']));
            $step = ($cue['end'] - $cue['start']) / $count;
            // A cue running into the window from before keeps its opening words; one running out
            // of it keeps its closing words. A cue spanning the whole window keeps both.
            $keep = $cue['start'] < $window['start'] && $cue['end'] > $window['end']
                ? range(0, $count - 1)
                : ($cue['start'] < $window['start'] ? range(0, max(-1, $count - $decoded - 1)) : range(min($count, $decoded), $count - 1));

            foreach ($keep as $position) {
                if ($position < 0 || $position >= $count) {
                    continue;
                }

                $start = $cue['start'] + $position * $step;
                $end = $start + $step;

                if ($cue['start'] < $window['start'] && $cue['end'] > $window['end'] && $start < $window['end'] && $end > $window['start']) {
                    continue;
                }

                $tokens[] = ['start' => $start, 'end' => $end, 'word' => $parts[$position]];
            }
        }

        usort($tokens, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values(array_filter($tokens, static fn (array $token): bool => $token['end'] > $from && $token['start'] < $to));
    }

    /**
     * The word-pause cut, moved where both banked answers agree it splits a thought between two
     * spoken items ({@see SpokenEdgeSentenceCheck}). The move takes the named words across the cut
     * whole, to the gap beside the last of them; words that are not the ones beside the cut, or a
     * gap a word is still sounding in, move nothing.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @param  array<string, mixed>  $decision
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function sentenceChecked(MediaProcessingLog $log, array $cues, array $words, array $window, float $original, string $edge, array $decision): array
    {
        $check = app(SpokenEdgeSentenceCheck::class);

        if ($check->spokenSides($log, $original) === null) {
            return [$decision, null];
        }

        $answer = $check->read($log, $check->identityFor($log, $cues, $words, $window, $original, $edge, $decision['time']));

        if ($answer === null) {
            return [$decision, ['asked' => false]];
        }

        $audit = ['asked' => true, ...$answer, 'moved' => false];

        if (! $answer['agreed'] || ! in_array($answer['decision'], ['extend', 'shrink'], true)) {
            return [$decision, $audit];
        }

        $normalize = static fn (string $text): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
        $moving = $normalize((string) $answer['words_to_move']);
        $cut = $decision['time'];
        $tokens = self::tokens($cues, $words, $window, $cut - 40.0, $cut + 40.0);
        $before = array_values(array_filter($tokens, static fn (array $token): bool => ($token['start'] + $token['end']) / 2 < $cut));
        $after = array_values(array_filter($tokens, static fn (array $token): bool => ($token['start'] + $token['end']) / 2 >= $cut));
        // Which side the words cross from: the output's own side when it drops them, the other
        // when it takes them in.
        $fromBefore = ($edge === 'start') === ($answer['decision'] === 'extend');
        $side = $fromBefore ? $before : $after;
        $count = 0;

        // The run beside the cut whose words, read in order, are the words named: grown one word
        // at a time, since a transcript word and an answer word need not split alike ("let's").
        for ($length = 1; $moving !== '' && $length <= count($side); $length++) {
            $run = $fromBefore ? array_slice($side, -$length) : array_slice($side, 0, $length);
            $text = $normalize(implode(' ', array_column($run, 'word')));

            if ($text === $moving) {
                $count = $length;

                break;
            }

            if (strlen($text) > strlen($moving)) {
                break;
            }
        }

        if ($count === 0) {
            return [$decision, $audit];
        }

        $run = $fromBefore ? array_slice($side, -$count) : array_slice($side, 0, $count);

        $previous = $fromBefore ? ($before[count($before) - $count - 1] ?? null) : $run[$count - 1];
        $next = $fromBefore ? $run[0] : ($after[$count] ?? null);

        if ($previous === null || $next === null || $previous['end'] > $next['start'] + 0.001) {
            return [$decision, $audit];
        }

        $time = ($previous['end'] + $next['start']) / 2;

        return [['time' => $time, 'chosen_pause' => ['start' => $previous['end'], 'end' => $next['start']],
            'word_before' => $previous, 'word_after' => $next], [...$audit, 'moved' => true, 'word_pause_time' => $cut]];
    }

    /**
     * The edges a plan could not place: an ambiguous cue anchor, whose cut keeps every occurrence
     * the cue might start or end with, so nothing of the cue is dropped, and a song end whose
     * speech onset is not established ({@see self::SONG_END_UNRESOLVED}). Whether the cut is right
     * is a question; a consumer must not execute the plan unasked.
     *
     * @param  list<array<string, mixed>>  $audit  a plan's `cue_edge_widening`
     * @return list<array{span_index: int, edge: string, original_time: float}>
     */
    public static function unresolvedEdges(array $audit): array
    {
        return array_values(array_map(
            static fn (array $entry): array => ['span_index' => (int) $entry['span_index'], 'edge' => (string) $entry['edge'], 'original_time' => (float) $entry['original_time']],
            array_filter($audit, static fn (array $entry): bool => in_array($entry['reason'] ?? null, [self::AMBIGUOUS_CUE_ANCHOR, self::SONG_END_UNRESOLVED, self::SPOKEN_END_UNRESOLVED], true)),
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
     * all. Silence is the run's level below its threshold for a second after the song's last
     * sound; the speech is the first word the evidence says was spoken, and the cut goes in the
     * gap just before it.
     *
     * Canary 12's ten song ends that ran on (B1–B3): the speech can start inside the section
     * (benedictions, "Thank you, Aled", a prayer line), under a cue stretched over the singing,
     * and decoded words can be smeared over seconds of outro. So the classifier's timeline says
     * where the singing stops for good ({@see self::singingStops()}), smeared words are not sound,
     * and the onset is a heard word: the opening words of the speech's cue, or the first plausible
     * word the classifier hears as speech. An onset is established when the gap before it is
     * observed. Otherwise the edge is unresolved ({@see self::SONG_END_UNRESOLVED}), never moved
     * to a larger pause inside the speech: the largest pause near the old edge sat after "…to you"
     * ten seconds into 1311 §4875's speech, and before 1250 §3131's last sung line. Speech with no
     * words decoded at all keeps the whole-cue rule.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}|null  $window  The window around the song's end
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return array<string, mixed>|null
     */
    private function songEnd(MediaProcessingLog $log, array $cues, ?array $window, float $end, ?array $levels, ?AudioTimeline $timeline): ?array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $singing = $this->singingStops($timeline, $end);
        $from = $singing !== null && $singing['inside'] ? min($singing['from'], $end) : $end;
        $heard = $window !== null ? $this->withWindow($evidence->read($log, $window)['words'], $window) : [];
        $speech = $this->speechAfterSong($cues, $end, $levels, $timeline, $singing);
        $speechWords = [];

        if ($speech !== null) {
            $speechWindow = $evidence->window($cues, $speech['start'], $log->duration);
            $speechWords = $speechWindow !== null ? $this->withWindow($evidence->read($log, $speechWindow)['words'], $speechWindow) : [];
        }

        $words = $this->merged([...$heard, ...$speechWords]);
        $floor = $from < $end ? $from : min($end, $speech['start'] ?? $end) - self::SONG_END_PAUSE_REACH;
        $onsets = array_values(array_filter([
            // The cue's own decode says where its opening is: an overlapping window can hear the
            // same words half a second apart (964 §878), which is not a second occurrence.
            $speech !== null && $speechWords !== [] ? $this->openingHeard($speech, $this->merged($speechWords), $floor) : null,
            $singing !== null ? $this->firstSpokenWord($words, $timeline, $from) : null,
        ]));
        // A word smeared beyond the anchor's reach does not start where it was said (1311 §4931's
        // "Well," over 2.4 s of singing); a drawn-out first word can (1304 §4884's "and", 1.6 s).
        $onsets = array_values(array_filter($onsets, static fn (array $onset): bool => $onset['word']['end'] - $onset['word']['start'] <= self::ANCHOR_REACH));
        $bounds = [$end + self::SONG_END_REACH, ...array_map(static fn (array $onset): float => $onset['word']['start'], $onsets)];

        if ($speech !== null && TranscriptCueEvidence::isEvidence($speech, $timeline)) {
            $bounds[] = $speech['start'];
        }

        if ($singing !== null) {
            $bounds[] = $singing['speech_by'];
        }

        $until = min($bounds);
        $lastSound = $from;

        foreach ($words as $word) {
            if ($word['end'] <= $until && $this->isSung($word, $timeline)) {
                $lastSound = max($lastSound, $word['end']);
            }
        }

        $silence = $levels !== null ? $this->silenceOnset($levels, $lastSound, $until) : null;

        if ($silence !== null) {
            return ['time' => $silence, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                'reason' => 'song_end_silence', 'speech_cue' => $speech];
        }

        foreach ($onsets as $onset) {
            if ($onset['established'] && abs($onset['word']['start'] - $until) < 0.001) {
                $pause = $onset['pause'];

                $time = $pause['end'] - min(0.1, ($pause['end'] - $pause['start']) / 2);

                return ['time' => $time, 'chosen_pause' => $pause,
                    'word_before' => $onset['word_before'], 'word_after' => $this->plain($onset['word']),
                    'reason' => 'song_end_speech', 'speech_cue' => $speech, 'fade_out' => $this->fadeOut($time, $lastSound)];
            }
        }

        $classifierOnset = $singing !== null && $until < ($speech['start'] ?? INF) - 0.001;

        if (($speech === null || $speechWords === []) && ! $classifierOnset) {
            return null;
        }

        // Where only the sound says speech has begun, the song ends at the first quiet after its
        // singing; where a cue's words do, in the gap just before them, since a quiet earlier on
        // can be a breath between sung lines.
        $textBound = $speech !== null && (abs($until - $speech['start']) < 0.001 || array_any($onsets,
            static fn (array $onset): bool => abs($onset['word']['start'] - $until) < 0.001 && ($onset['source'] ?? null) === 'opening'));
        $quiet = $levels !== null && ! $textBound ? $this->firstQuiet($levels, $lastSound, $until) : null;
        $gap = $quiet ?? $this->lastGap($words, min($lastSound, $floor), $until);

        $time = $gap !== null ? ($quiet !== null ? $gap['start'] + min(self::SONG_END_SILENCE_TAIL, ($gap['end'] - $gap['start']) / 2) : $gap['end'] - min(0.1, ($gap['end'] - $gap['start']) / 2))
            : max(min($lastSound, $until), $until - 0.1);
        // Only the reach bounds the search: nothing says where the speech starts.
        $unidentified = abs($until - ($end + self::SONG_END_REACH)) < 0.001;

        return ['time' => $time, 'chosen_pause' => $gap, 'word_before' => null, 'word_after' => null,
            'reason' => $unidentified ? self::SONG_END_UNRESOLVED : self::SONG_END_FADE, 'speech_cue' => $speech, 'onset_bound' => $until,
            ...($unidentified ? [] : ['fade_out' => $this->fadeOut($time, $lastSound)])];
    }

    /** The fade of a song's sound ending at its cut: the gap from the last sung sound, bounded. */
    private function fadeOut(float $cut, float $lastSound): float
    {
        return round(min(self::SONG_END_FADE_MAX, max(self::SONG_END_FADE_MIN, $cut - $lastSound)), 3);
    }

    /**
     * Where the classifier hears a song's singing stop for good before speech: the start of the
     * last window holding music before the first window of speech alone, when no window of music
     * alone follows that speech up to the song's end. Null without a timeline, or when no speech
     * follows the singing within reach. `speech_by` is the end of the first window holding speech
     * after the singing: no onset can lie later. `inside` says that window starts before the
     * song's end: only then may silence inside the section end the song, since a second of quiet
     * before a final chord is not its end (1112 §3739, ruled right at 1984.26).
     *
     * @return array{from: float, speech_by: float, inside: bool}|null
     */
    private function singingStops(?AudioTimeline $timeline, float $end): ?array
    {
        if ($timeline === null) {
            return null;
        }

        $last = $timeline->windowCount() - 1;
        $low = $timeline->windowAt(max(0.0, $end - self::SONG_END_INSIDE_REACH));
        $high = $timeline->windowAt($end + self::SONG_END_REACH) ?? $last;
        $atEnd = $timeline->windowAt($end) ?? $last;

        if ($low === null || $last < 0) {
            return null;
        }

        for ($speech = $low; $speech <= $high; $speech++) {
            if ($timeline->classOf($speech) !== SoundClass::Speech) {
                continue;
            }

            $resumes = false;

            for ($later = $speech + 1; $later <= $atEnd; $later++) {
                $resumes = $resumes || $timeline->classOf($later) === SoundClass::Music;
            }

            if ($resumes) {
                continue;
            }

            $music = null;

            for ($earlier = $speech - 1; $earlier >= $low && $music === null; $earlier--) {
                $music = $timeline->windows[$earlier]['music'] >= AudioTimeline::MUSIC_CUTOFF ? $earlier : null;
            }

            if ($music === null) {
                return null;
            }

            $first = $music;

            while ($timeline->windows[$first]['speech'] < AudioTimeline::SPEECH_CUTOFF) {
                $first++;
            }

            return ['from' => $timeline->windows[$music]['start'], 'speech_by' => $timeline->windows[$first]['end'],
                'inside' => $timeline->windows[$first]['start'] < $end];
        }

        return null;
    }

    /**
     * The first cue after a song that can be its speech: one with words, not whisper hearing words
     * in silence, starting at or just before the song's end, or, where the classifier hears the
     * singing stop inside the section, after that, starting in a window that holds speech (B3). A cue too
     * long for its words ({@see TranscriptCueEvidence::isTimingSuspect()}) can be the speech, but
     * only its heard words say where it starts.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @param  array{from: float, speech_by: float, inside: bool}|null  $singing
     * @return array{start: float, end: float, text: string}|null
     */
    private function speechAfterSong(array $cues, float $end, ?array $levels, ?AudioTimeline $timeline = null, ?array $singing = null): ?array
    {
        $from = $singing !== null && $singing['inside'] ? min($singing['from'], $end - self::SONG_END_LEAD) : $end - self::SONG_END_LEAD;
        $after = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] >= $from
            && $cue['start'] <= $end + self::SONG_END_REACH && TranscriptCueEvidence::hasWords($cue)));
        usort($after, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($after as $cue) {
            // Inside the section a cue is speech only where it starts in speech: 1311 §4931's last
            // sung line ran on into a window the classifier half-hears as speech.
            if ($cue['start'] < $end - self::SONG_END_LEAD) {
                $window = $timeline?->windowAt($cue['start']);

                if ($window === null || $timeline->windows[$window]['speech'] < AudioTimeline::SPEECH_CUTOFF) {
                    continue;
                }
            }

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
     * Where the speech cue's opening words are heard: its first words in order, or, when the first
     * one or two are not heard ("Aled," lost before "can we have…"), the next, or else its first
     * word alone near where the cue starts. Established only when the cue's own first word is
     * heard, once, after an observed gap.
     *
     * @param  array{start: float, end: float, text: string}  $cue
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words  In time order
     * @return array{word: array{start: float, end: float, word: string, window_start: float}, established: bool, pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, source?: string}|null
     */
    private function openingHeard(array $cue, array $words, float $floor): ?array
    {
        $cueTokens = TranscriptCueEvidence::tokens($cue['text']);
        $tokens = [];

        foreach ($words as $index => $word) {
            foreach (TranscriptCueEvidence::tokens($word['word']) as $token) {
                $tokens[] = ['token' => $token, 'word' => $index];
            }
        }

        for ($skipped = 0; $skipped <= 2 && $skipped < count($cueTokens); $skipped++) {
            $run = array_slice($cueTokens, $skipped, self::ANCHOR_RUN);
            $found = [];

            for ($at = 0; $at < count($tokens); $at++) {
                $heard = array_column(array_slice($tokens, $at, count($run)), 'token');
                // A decode ending mid-run still anchors on the words it holds, two at least.
                $matches = $heard === array_slice($run, 0, count($heard)) && (count($heard) === count($run) || count($heard) >= 2);

                if ($matches && $words[$tokens[$at]['word']]['start'] >= $floor && ($at === 0 || $tokens[$at - 1]['word'] !== $tokens[$at]['word'])) {
                    $found[] = $tokens[$at]['word'];
                }
            }

            if ($found === []) {
                continue;
            }

            // Heard where the cue says it starts, the run is the cue's own; further off it can be
            // another line's (1346 §4375's "Let's pray once again" before "Let's pray."), and is
            // taken only when nothing nearer is heard (1250 §4911's prayer, timed 20 s early).
            $near = array_values(array_filter($found, static fn (int $index): bool => abs($words[$index]['start'] - $cue['start']) <= self::ANCHOR_REACH));
            $found = $near !== [] ? $near : $found;
            $onset = $this->onset($words, $found[0]);

            return [...$onset, 'source' => 'opening', 'established' => $onset['established'] && $skipped === 0 && count(array_unique($found)) === 1];
        }

        // A misheard opening ("We remain standing" heard as "We may have…", 949 §4859) still
        // anchors on the cue's first word, heard once where the cue says it starts.
        $first = array_values(array_filter(array_keys($words), static fn (int $index): bool => $cueTokens !== []
            && (TranscriptCueEvidence::tokens($words[$index]['word'])[0] ?? null) === $cueTokens[0]
            && abs($words[$index]['start'] - $cue['start']) <= self::ANCHOR_REACH && $words[$index]['start'] >= $floor));

        if ($first === []) {
            return null;
        }

        $onset = $this->onset($words, $first[0]);

        return [...$onset, 'source' => 'opening', 'established' => $onset['established'] && count($first) === 1];
    }

    /**
     * The first plausible word after the singing stops that the classifier hears as speech
     * ({@see self::heardAsSpeech()}), followed by another plausible word: a sung line it
     * half-hears as speech before the outro is the song's, and a lone word between smeared ones
     * is not speech the decode placed (1311 §4931's "a").
     *
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words  In time order
     * @return array{word: array{start: float, end: float, word: string, window_start: float}, established: bool, pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, source?: string}|null
     */
    private function firstSpokenWord(array $words, ?AudioTimeline $timeline, float $from): ?array
    {
        if ($timeline === null) {
            return null;
        }

        foreach ($words as $index => $word) {
            $window = $timeline->windowAt(($word['start'] + $word['end']) / 2);

            $next = $words[$index + 1] ?? null;

            if ($word['start'] < $from || $word['end'] - $word['start'] > self::LONGEST_HEARD_WORD || $window === null
                || $timeline->windows[$window]['speech'] < AudioTimeline::SPEECH_CUTOFF
                || ! $this->heardAsSpeech($timeline, $window)
                || $word['end'] - $word['start'] > self::LONGEST_SPOKEN_WORD
                || $next === null || $next['end'] - $next['start'] > self::LONGEST_SPOKEN_WORD) {
                continue;
            }

            return $this->onset($words, $index);
        }

        return null;
    }

    /**
     * A window the classifier hears as speech alone, or holding music too only where speech alone
     * follows it: quick singing at speaking pace is music and speech at once, and is followed by
     * the outro or silence, not by speech (Codex review; 1028 §1400, 964 §4965 and 1304 §3870 all
     * open in such a window followed by speech alone).
     */
    private function heardAsSpeech(AudioTimeline $timeline, int $window): bool
    {
        return match ($timeline->classOf($window)) {
            SoundClass::Speech => true,
            SoundClass::Mixed => $window < $timeline->windowCount() - 1 && $timeline->classOf($window + 1) === SoundClass::Speech,
            default => false,
        };
    }

    /**
     * A heard word as a speech onset: established when the gap before it is observed, after a
     * plausible word, after a pause too long to be inside a smear, or the decode window's own margin.
     *
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words
     * @return array{word: array{start: float, end: float, word: string, window_start: float}, established: bool, pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null}
     */
    private function onset(array $words, int $index): array
    {
        $word = $words[$index];
        $before = null;

        for ($previous = $index - 1; $previous >= 0 && $before === null; $previous--) {
            $before = $words[$previous]['start'] < $word['start'] ? $words[$previous] : null;
        }

        if ($before === null) {
            $gap = $word['start'] - $word['window_start'];

            return ['word' => $word, 'established' => $gap >= self::OBSERVED_GAP,
                'pause' => ['start' => $word['window_start'], 'end' => $word['start']], 'word_before' => null];
        }

        return ['word' => $word, 'established' => $before['end'] <= $word['start'] + 0.001
            && ($before['end'] - $before['start'] <= self::LONGEST_HEARD_WORD || $word['start'] - $before['end'] >= self::OBSERVED_PAUSE),
            'pause' => ['start' => min($before['end'], $word['start']), 'end' => $word['start']], 'word_before' => $this->plain($before)];
    }

    /**
     * A plausible word the song itself sang: in a window holding music, when the timeline says.
     *
     * @param  array{start: float, end: float, word: string}  $word
     */
    private function isSung(array $word, ?AudioTimeline $timeline): bool
    {
        if ($word['end'] - $word['start'] > self::LONGEST_HEARD_WORD) {
            return false;
        }

        $window = $timeline?->windowAt(($word['start'] + $word['end']) / 2);

        return $timeline === null || ($window !== null && $timeline->windows[$window]['music'] >= AudioTimeline::MUSIC_CUTOFF);
    }

    /**
     * The first quiet between phrases after the last sound, before the bound: where an unresolved
     * song end sits.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     * @return array{start: float, end: float}|null
     */
    private function firstQuiet(array $levels, float $from, float $until): ?array
    {
        $runStart = null;
        $previous = null;

        foreach ($levels['samples'] as $sample) {
            if ($sample['time'] < $from) {
                continue;
            }

            if ($sample['time'] > $until) {
                break;
            }

            if ($sample['rms'] < $levels['threshold']) {
                $runStart ??= $sample['time'];
            } elseif ($runStart !== null && $previous !== null) {
                if ($previous - $runStart >= self::SONG_END_QUIET_SECONDS - 0.0001) {
                    return ['start' => $runStart, 'end' => $previous];
                }

                $runStart = null;
            }

            $previous = $sample['time'];
        }

        return $runStart !== null && $previous !== null && $previous - $runStart >= self::SONG_END_QUIET_SECONDS - 0.0001
            ? ['start' => $runStart, 'end' => $previous] : null;
    }

    /**
     * The last gap no heard word sounds in, between the last sound and the bound.
     *
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words  In time order
     * @return array{start: float, end: float}|null
     */
    private function lastGap(array $words, float $from, float $until): ?array
    {
        $frontier = $from;
        $gap = null;

        foreach ($words as $word) {
            if ($word['start'] >= $until) {
                break;
            }

            if ($word['start'] > $frontier) {
                $gap = ['start' => $frontier, 'end' => $word['start']];
            }

            $frontier = max($frontier, $word['end']);
        }

        return $frontier < $until ? ['start' => $frontier, 'end' => $until] : $gap;
    }

    /**
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float}  $window
     * @return list<array{start: float, end: float, word: string, window_start: float}>
     */
    private function withWindow(array $words, array $window): array
    {
        return array_map(static fn (array $word): array => [...$word, 'window_start' => $window['start']], $words);
    }

    /**
     * Two decode windows can hear the same word: kept once, earliest window first.
     *
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words
     * @return list<array{start: float, end: float, word: string, window_start: float}>
     */
    private function merged(array $words): array
    {
        usort($words, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        $kept = [];

        foreach ($words as $word) {
            $last = $kept[count($kept) - 1] ?? null;

            if ($last !== null && abs($last['start'] - $word['start']) < 0.001 && abs($last['end'] - $word['end']) < 0.001) {
                continue;
            }

            $kept[] = $word;
        }

        return $kept;
    }

    /**
     * @param  array{start: float, end: float, word: string}  $word
     * @return array{start: float, end: float, word: string}
     */
    private function plain(array $word): array
    {
        return ['start' => $word['start'], 'end' => $word['end'], 'word' => $word['word']];
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

            if (self::SONG_END_SILENCE_SECONDS - 0.0001 <= $sample['time'] - $runStart) {
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

    /** The run's classifier timeline, or null when it has none or it cannot be read. */
    private function timeline(MediaProcessingLog $log): ?AudioTimeline
    {
        $path = $log->audio_timeline_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return AudioTimeline::fromJson((string) Storage::disk(ServiceArtifactDisk::for($path))->get($path));
        } catch (\Throwable) {
            return null;
        }
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
