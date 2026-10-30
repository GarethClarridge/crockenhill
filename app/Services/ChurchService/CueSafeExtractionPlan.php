<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Enums\SoundClass;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongMusicalIntroduction;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\UntranscribedSpeechRecovery;
use App\Support\ServiceArtifactDisk;
use App\Support\ServiceTimestamp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Put output edges in measured word pauses; no-word windows retain whole-cue widening. */
class CueSafeExtractionPlan
{
    /** Seconds within which an output edge counts as sitting on a cue's boundary. */
    private const CUE_BOUNDARY_TOLERANCE = 0.05;

    /**
     * How far after the next cue's start a K16 kept line's last word may be decoded and still be that line's:
     * whole-second cues ran 0.01–0.30 s ahead of the words at 1193, 1031 and 1375 (defect-fix plan F6, 10-30).
     */
    private const KEPT_WORD_CUE_DRIFT = 0.5;

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

    /**
     * A cue whose words come again this close by is a repeated line, not the onset of a
     * spoken phrase a held word may not run into (1023): the whole of a song end's reach.
     */
    private const HELD_WORD_REPEATED_CUE = self::SONG_END_REACH;

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
     * Seconds a spoken end keeps after its last word's decoded end. Decoded word ends run early:
     * 21 of the full pass's 22 endings ruled "word clipped" cut within 0.1 s of it, while no
     * ending with 0.2–0.5 s of tail was (operator listening, 2026-10-12).
     */
    private const SPOKEN_END_TAIL = 0.3;

    /**
     * Seconds after the last kept word's decoded end within which another decode's word with the same
     * text can start and be that word heard again, not the next one. 1070's Amen sounds 1628.7–1629.35;
     * two decodes heard it, 1628.78–1629.04 and 1629.07–1629.24 (operator 10-30: "B clips the
     * preachers amen").
     */
    private const SAME_WORD_GAP = 0.1;

    /**
     * Seconds below the levels threshold that make the same text two words: the congregation's Amen
     * answers the preacher's after a quiet (1202: 0.38 s, 1133: 0.72 s; both ruled right ending
     * before it), while one Amen heard by two decodes has none (1070).
     */
    private const SAME_WORD_QUIET = 0.1;

    /** Seconds without decoded words that can end an unpunctuated song announcement. */
    private const SONG_START_SENTENCE_PAUSE = 1.0;

    /**
     * Seconds past its decoded end a dropped sentence's last word may still sound (1023's "again",
     * 0.7 s). Sound running on longer is something else: the band playing in.
     */
    private const DROPPED_WORD_REACH = 1.0;

    /** Seconds before a spoken part's start within which a song's end runs straight into it. */
    private const SONG_BEFORE_REACH = 2.0;

    /** Seconds a spoken end may move outward before the classifier is asked whether it moved into music. */
    private const MUSIC_MOVE_SECONDS = 1.0;

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

    /**
     * The time a smeared opening word is taken to have been said in, each, before the first word
     * heard at speaking pace: 1250 §3131's "Marlene" before "is" (heard ≈1739.0, 10-29), 1326
     * §4134's "Well, I'm just" before "having" (ruled 610.13).
     */
    private const SMEARED_WORD_SECONDS = 0.33;

    /** A decode window margin before a word at least this long shows nothing was said there. */
    private const OBSERVED_GAP = 0.1;

    /**
     * An end on the next word's decoded onset moves into a quiet this many decibels under the sound
     * beside the cut: a decode dates a word from its first clear sound, so its onset is already
     * heard there (1288, 928, 1340, 1303; operator 10-29). Ends whose words run together dip
     * 12.6 dB or less and have no quiet to end in; the four heard ones dip 21.7 dB or more.
     */
    private const ONSET_DIP_DECIBELS = 20.0;

    /** How far before such an end its quiet is looked for. */
    private const ONSET_DIP_REACH = 0.25;

    /** Decibels over the speech threshold at which the next word's sound counts as up at the cut. */
    private const ONSET_HEARD_ABOVE = 10.0;

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

    /** The audit reason for an output edge the operator set by ear ({@see OutputEdgeAnswers}); the planner's own is kept. */
    public const OPERATOR_SET_EDGE = 'operator_set_edge';

    /** The audit reason for an operator's edge the plan cannot apply: the planner's edge stands, and the plan is invalid. */
    public const OPERATOR_EDGE_REFUSED = 'operator_edge_refused';

    /** Seconds within which a section's bound borders an unresolved interval. */
    private const INTERVAL_REACH = 0.5;

    /**
     * Seconds of dead feed a spoken edge may sit in before it moves to the sound: a pause between
     * items is shorter (operator, 2026-10-15). 1023's sermon opened on 16 s of a muted feed.
     */
    private const DEAD_FEED_SECONDS = 2.0;

    /**
     * How long live audio that reaches the run's threshold must last to count as sound. A quiet word
     * and a noise in a dead feed cannot be told apart by level: 1025's clipped last word (0.51 s,
     * peak −46 dB, 4 frames above a −51 dB threshold) matches the noises in 1023's dead stretch
     * (0.4 s, peak −45 dB, 1 frame above −49 dB), so both are kept and no word is lost (2026-10-15).
     */
    private const HEARD_SECONDS = 0.1;

    /** The shortest dead run an end moves back to: a single dropped frame is not where the feed died. */
    private const DEAD_FEED_RUN_SECONDS = 0.5;

    /** What a start moved out of a dead feed keeps before the first sound. */
    private const DEAD_FEED_LEAD = 0.3;

    /** Known RMS logs sample at least every 0.1 s; missing frames cannot prove digital zero. */
    private const SONG_SILENCE_MAXIMUM_SAMPLE_GAP = 0.1;

    /**
     * A change of song hidden by a broken transcript ({@see self::atSongChangeDip()}): the
     * transcript is read this far either side of the change, and one cue this long, or the same
     * line three times running, is not what was sung (1267's 30 s "Cumbered with the Lord of
     * care" cues; 1307's nine "Yes.").
     */
    private const SONG_CHANGE_TRANSCRIPT_REACH = 30.0;

    private const SONG_CHANGE_STRETCHED_CUE = 20.0;

    /** Where the quietest second is sought: before and after the detected change, and never this near either song's other edge. */
    private const SONG_CHANGE_SEARCH_BEFORE = 45.0;

    private const SONG_CHANGE_SEARCH_AFTER = 15.0;

    private const SONG_CHANGE_SONG_MINIMUM = 30.0;

    /**
     * Heard blind 10-20: of 24 such changes, the quietest second was right in all 8 where it lay
     * more than this before the cut, and a matter of taste or wrong where it lay after.
     */
    private const SONG_CHANGE_MOVE = 5.0;

    /** How far the quietest second falls below the median of the 30 s before it: 19.7 dB at least in those 8. */
    private const SONG_CHANGE_DEPTH = 15.0;

    /**
     * A section's own output: the section, or a talk with the reading it is answered to go with
     * ({@see TalkReadingPairing}), one span per section. The reading takes the pause before the
     * talk, so the two meet and play straight through, as a sermon's silent joins do. A talk's or song's
     * output takes the operator's start and end ({@see OutputEdgeAnswers}).
     *
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>, audio_fade_out: float|null, dead_feed_excisions?: list<array<string, mixed>>, dead_feed_input_identity?: string}
     */
    public function forSection(ServiceSection $section, ?ChurchServiceTranscript $transcript = null): array
    {
        $sections = app(TalkReadingPairing::class)->sectionsCut($section);
        $spans = [];

        foreach ($sections as $index => $part) {
            $next = $sections[$index + 1] ?? null;
            $spans[] = ['start_time' => (float) $part->start_time, 'end_time' => $next === null ? (float) $part->end_time : (float) $next->start_time];
        }

        $plan = $this->forSpans(
            $section->processingLog,
            $spans,
            songEnds: $section->section_type === ServiceSectionType::Song,
            edgeAnswers: in_array($section->section_type, [ServiceSectionType::ShortTalk, ServiceSectionType::Song], true) ? OutputEdgeAnswers::of($section, $sections) : null,
            transcript: $transcript,
        );
        if (! in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::ShortTalk], true)) {
            return $plan;
        }
        $excision = app(DeadFeedExcision::class)->apply($section->processingLog, $sections, $plan['segments']);

        return [...$plan, ...$excision, 'dead_feed_input_identity' => app(DeadFeedExcision::class)->inputIdentity($section->processingLog)];
    }

    /**
     * Where the speech that ends each span would start, were the span a song: the edges whose word
     * timings {@see self::forSection()} reads for a song's end, beyond the span's own.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return list<float>
     */
    public function songEndSpeechEdges(MediaProcessingLog $log, array $spans, ?ChurchServiceTranscript $transcript = null): array
    {
        $cues = $transcript->cues ?? app(OutputEdgeWordTimings::class)->cues($log);
        $levels = $this->levels($log);
        $timeline = $this->timeline($log);
        $starts = $this->songStarts($log);
        $edges = [];

        foreach ($spans as $span) {
            $end = (float) $span['end_time'];
            $cue = $this->speechAfterSong($cues, $end, $levels, $timeline, $this->singingStops($timeline, $end), $this->songAfter($starts, $end) !== null);

            if ($cue !== null) {
                $edges[] = $cue['start'];
            }
        }

        return $edges;
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  bool  $songEnds  Each span is a song: its end runs on to silence or the next speech
     * @param  bool  $sermonEnd  The last span ends a sermon output: beside a song it ends where the banked introduction answer says (K16, {@see SermonSongIntroductionCheck})
     * @param  OutputEdgeAnswers|null  $edgeAnswers  The operator's start and end for this output, applied after every edge rule
     * @param  ChurchServiceTranscript|null  $transcript  Read-only candidate evidence; never installs a transcript on the run
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>, audio_fade_out: float|null}
     */
    public function forSpans(MediaProcessingLog $log, array $spans, bool $songEnds = false, bool $sermonEnd = false, ?OutputEdgeAnswers $edgeAnswers = null, ?ChurchServiceTranscript $transcript = null): array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $transcript->cues ?? $evidence->cues($log);
        $levels = $songEnds ? $this->levels($log) : null;
        $timeline = $songEnds || $sermonEnd ? $this->timeline($log) : null;
        // K16: a sermon followed by a song ends where the banked introduction answer says.
        $introduction = $sermonEnd ? app(SermonSongIntroductionCheck::class) : null;
        $introductionJoin = $introduction?->join($log, $spans);
        $last = array_key_last($spans);
        $sectionSpans = $spans;
        $audit = [];
        /** @var list<array{start: float, end: float, word: string}>|null $heardWords */
        $heardWords = null;
        foreach ($spans as $index => &$span) {
            foreach (['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $original = $span[$key];
                // Two parts that meet are one stretch of the output: no cut is made between them
                // (1050's reading ends where its sermon starts; placing both edges dropped the
                // sermon's first sentence).
                $neighbour = $edge === 'end' ? ($sectionSpans[$index + 1]['start_time'] ?? null) : ($sectionSpans[$index - 1]['end_time'] ?? null);
                if ($neighbour !== null && abs($neighbour - $original) < 0.001) {
                    continue;
                }
                $movedFrom = null;
                $besideSong = false;
                $thoughtComplete = true;
                $songIntroduction = null;
                if ($edge === 'end' && $index === $last && $introductionJoin !== null) {
                    $besideSong = true;
                    // Never asked is evidence not yet gathered, as undecoded words are: the edge
                    // step asks before it decodes. Asked with no line named leaves it to the operator.
                    $songIntroduction = $this->whereKeptLineWasHeard($log, $cues, $introduction->read($log, $sectionSpans)
                        ?? throw new OutputEdgeTimingsMissing(sprintf('edge_word_timings_missing: run %s has no answer on where its sermon ends beside the song (K16)', $log->processing_id)), $introductionJoin['end']);
                    $thoughtComplete = ($songIntroduction['end'] ?? null) !== null;
                    if ($thoughtComplete && abs($songIntroduction['end'] - $original) > 0.001) {
                        [$movedFrom, $original] = [$original, $songIntroduction['end']];
                    }
                }
                $time = $original;
                $window = $evidence->timingWindow($cues, $original, $log->duration);
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
                        $decision = $this->placedEdge($log, $words, $window, $cues, $original, $edge, $songEnds,
                            closingLine: $besideSong ? ($songIntroduction['line'] ?? null) : null);
                        $sentenceStartMoved = false;
                        if ($songEnds && $edge === 'start') {
                            $afterSentence = $this->afterSongOpeningSentence($words, $window, $cues, $decision);
                            if ($afterSentence['time'] >= $span['end_time']) {
                                $afterSentence = $decision;
                            }
                            $sentenceWindow = $evidence->window($cues, $original, $log->duration, $log);
                            if ($sentenceWindow !== null && isset($sentenceWindow['window_rule'])) {
                                $sentenceWords = $this->sentenceEvidenceAtTiming($words, $evidence->read($log, $sentenceWindow)['words'], $sentenceWindow, $cues);
                                $wideSentence = $this->afterSongOpeningSentence($sentenceWords, $sentenceWindow, $cues, $afterSentence, textEvidence: true);
                                // Unknown wide text never retimes an existing cut. A confirmed stop can.
                                if ($wideSentence['time'] > $afterSentence['time']) {
                                    $afterSentence = $wideSentence;
                                }
                            }
                            if ($afterSentence['time'] > $decision['time'] && $afterSentence['time'] < $span['end_time']) {
                                $decision = $this->afterDroppedWordSounds($afterSentence, $this->levels($log));
                                $sentenceStartMoved = true;
                            }
                        }

                        if ($besideSong) {
                            $decision = $this->withTail($decision);
                        }
                        $reason = isset($decision['candidate_anchors']) ? self::AMBIGUOUS_CUE_ANCHOR
                            : ($sentenceStartMoved ? 'song_start_sentence' : 'word_pause');
                        $sentenceCheck = null;
                        if ($reason === 'word_pause') {
                            [$decision, $sentenceCheck] = $this->sentenceChecked($log, $cues, $words, $window, $original, $edge, $decision);
                            $reason = $sentenceCheck['moved'] ?? false ? 'sentence_check' : $reason;
                        }
                        // After the sentence check, whose banked answers are bound to the cut it was asked about.
                        if ($edge === 'start' && ! $songEnds) {
                            $decision = $this->afterClosingAmen($decision, $words);
                        }
                        if ($edge === 'end') {
                            $heardWords ??= $evidence->heardWords($log);
                            $endLevels = $levels ?? $this->levels($log);
                            $decision = $this->beforeWordHeardElsewhere($this->withSpokenEndTail($decision), $heardWords, $endLevels);
                            if ($besideSong && $thoughtComplete) {
                                $decision = $this->beforeNextLine($decision, $cues, $songIntroduction, $this->withWindow($words, $window));
                            }
                            $decision = $this->beforeNextWordSound($decision, $endLevels);
                        }
                        $span[$key] = $decision['time'];
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $movedFrom ?? $original,
                            'time' => $decision['time'], 'seconds_added' => abs($decision['time'] - ($movedFrom ?? $original)),
                            'seconds_moved' => $decision['time'] - ($movedFrom ?? $original), 'window' => $window,
                            'cue' => $window['cues'][0], 'cues' => $window['cues'],
                            ...$decision, 'reason' => $thoughtComplete ? $reason : self::SPOKEN_END_UNRESOLVED, 'sentence_check' => $sentenceCheck,
                            'text_disagreement' => $this->disagrees($words, $window['cues']),
                            'moved_beside_song_from' => $movedFrom, 'song_introduction' => $songIntroduction];

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
                $lineDecision = $besideSong && $thoughtComplete ? $this->beforeNextLine(['time' => $time], $cues, $songIntroduction) : ['time' => $time];
                $time = $lineDecision['time'];
                $span[$key] = $time;
                if ($time !== $original || $window !== null || $movedFrom !== null || ! $thoughtComplete) {
                    $included = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] < max($time, $original) && $cue['end'] > min($time, $original)));
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $movedFrom ?? $original,
                        'time' => $time, 'seconds_added' => abs($time - ($movedFrom ?? $original)), 'seconds_moved' => $time - ($movedFrom ?? $original),
                        'cue' => $included[0] ?? ($window['cues'][0] ?? null), 'cues' => $included,
                        'window' => $window, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null, ...$lineDecision,
                        'reason' => $thoughtComplete ? 'no_words_whole_cue' : self::SPOKEN_END_UNRESOLVED, 'text_disagreement' => false, 'moved_beside_song_from' => $movedFrom, 'song_introduction' => $songIntroduction];
                }
            }
        }
        unset($span);
        if (! $songEnds) {
            $spans = $this->afterSongSound($log, $cues, $sectionSpans, $spans, $audit);
        }
        $spans = $this->outOfDeadFeed($log, $sectionSpans, $spans, $audit, songStartsOnly: $songEnds, levels: $levels);
        if ($songEnds) {
            $spans = $this->atSongChangeDip($log, $cues, $sectionSpans, $spans, $audit, $levels);
            $spans = $this->atSongChangeDip($log, $cues, $sectionSpans, $spans, $audit, $levels, start: true);
            foreach ($spans as $index => &$span) {
                $cut = (float) $span['start_time'];
                $dependent = app(DependentSongOpening::class)->review($log, $cues, $cut, (float) $span['end_time'], (float) $sectionSpans[$index]['start_time']);
                if ($dependent['window'] === null) {
                    continue;
                }
                $entry = ['span_index' => $index, 'edge' => 'start', 'original_time' => $cut, 'time' => $cut,
                    'seconds_added' => 0.0, 'seconds_moved' => 0.0, 'reason' => 'song_start_dependent',
                    'text_disagreement' => false, 'dependent_opening' => $dependent];
                if ($dependent['time'] > $cut) {
                    $decision = $this->afterDroppedWordSounds(['time' => $dependent['time'],
                        'chosen_pause' => ['start' => $dependent['time'], 'end' => min((float) $span['end_time'], $dependent['word_after']['start'] ?? $dependent['window']['end'])],
                        'word_before' => $dependent['word_before'], 'word_after' => $dependent['word_after']], $levels);
                    if ($decision['time'] < $span['end_time']) {
                        $span['start_time'] = $decision['time'];
                        $entry = [...$entry, ...$decision, 'seconds_added' => $decision['time'] - $cut, 'seconds_moved' => $decision['time'] - $cut];
                    }
                }
                $audit[] = $entry;
            }
            unset($span);
        } else {
            $spans = $this->withDeadFeedOpeningCheck($log, $cues, $spans, $audit);
        }
        $audit = $this->withAnswerIdentity($log, $sectionSpans, $songEnds, $sermonEnd, $cues, $levels, $timeline, $audit);
        $spans = $this->withAnsweredEdges($log, $spans, $audit);
        $spans = $this->withUnresolvedIntervals($log, $sectionSpans, $spans, $audit);
        $spans = $this->withOperatorEdges($log, $sectionSpans, $spans, $audit, $edgeAnswers);
        $lastSpan = $last;
        $merged = [];
        foreach ($spans as $span) {
            $last = array_key_last($merged);
            // Parts that only meet stay apart: the video plays through the join, while each part's
            // sound is treated as its own (Codex review, 2026-10-13). Parts widened into each other merge.
            if ($last !== null && $span['start_time'] < $merged[$last]['end_time'] - 0.001) {
                $merged[$last] = ['start_time' => $merged[$last]['start_time'], 'end_time' => max($merged[$last]['end_time'], $span['end_time'])];

                continue;
            }
            $merged[] = $span;
        }

        $fade = array_values(array_filter($audit, static fn (array $entry): bool => ($entry['span_index'] ?? null) === $lastSpan
            && $entry['edge'] === 'end' && isset($entry['fade_out'])))[0]['fade_out'] ?? null;

        return ['segments' => $merged, 'cue_edge_widening' => $audit, 'audio_fade_out' => $songEnds ? $fade : null];
    }

    /**
     * The sermon output's end {@see self::forSpans()} reads beside a song (K16): the banked
     * introduction answer's end, whose word timings the edge step must decode, beyond the spans' own.
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
        $introduction = app(SermonSongIntroductionCheck::class);
        $answer = $introduction->read($log, $spans);
        $edges = ($answer['end'] ?? null) !== null && abs($answer['end'] - $end) > 0.001 ? [$answer['end']] : [];
        $join = $answer === null ? null : $introduction->join($log, $spans);
        if ($join !== null) {
            foreach ($this->unheardKeptLineCopies($log, app(OutputEdgeWordTimings::class)->cues($log), $answer, $join['end']) as $copy) {
                $edges[] = $copy['end'];
            }
        }

        return $edges;
    }

    /**
     * The line K16 keeps, where its words were said. A merged transcript can carry a passage twice:
     * 1219's whole-second cues run ~11 s early, so "and in no one else." is cued at 3834.9–3836.9,
     * where the decode heard "Jesus said, I am the way", and again on true times at 3852.52–3854.94.
     * When the line's last word is not heard at its cue, the first later copy before the song whose
     * decode heard it is the line; copies none of whose decodes heard it leave the end to the operator.
     * A line heard at its cue, or with no later copy, stands (most unheard lines are decoder spellings).
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{end: float|null, ties_to_sermon: bool|null, line: string|null}  $introduction
     * @return array{end: float|null, ties_to_sermon: bool|null, line: string|null, unheard_at?: float|null}
     */
    private function whereKeptLineWasHeard(MediaProcessingLog $log, array $cues, array $introduction, float $until): array
    {
        $copies = $this->unheardKeptLineCopies($log, $cues, $introduction, $until);
        if ($copies === []) {
            return $introduction;
        }

        $tokens = TranscriptCueEvidence::tokens((string) $introduction['line']);
        foreach ($copies as $copy) {
            if ($this->lastWordHeardAt($log, $cues, $copy['end'], (string) end($tokens))) {
                return [...$introduction, 'end' => $copy['end'], 'unheard_at' => $introduction['end']];
            }
        }

        return [...$introduction, 'end' => null, 'unheard_at' => $introduction['end']];
    }

    /**
     * Later cues with the kept line's words before `$until`, when its own cue's decode did not hear its
     * last word; none otherwise, or while that decode is still to be made.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{end: float|null, ties_to_sermon: bool|null, line: string|null}  $introduction
     * @return list<array{start: float, end: float, text: string}>
     */
    private function unheardKeptLineCopies(MediaProcessingLog $log, array $cues, array $introduction, float $until): array
    {
        $tokens = $introduction['line'] === null ? [] : TranscriptCueEvidence::tokens($introduction['line']);
        if ($introduction['end'] === null || $tokens === []) {
            return [];
        }
        $end = $introduction['end'];
        $copies = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] >= $end - 0.001
            && $cue['start'] < $until && abs($cue['end'] - $end) > 0.001 && TranscriptCueEvidence::tokens($cue['text']) === $tokens));
        if ($copies === []) {
            return [];
        }
        try {
            return $this->lastWordHeardAt($log, $cues, $end, end($tokens)) ? [] : $copies;
        } catch (OutputEdgeTimingsMissing) {
            return [];
        }
    }

    /** @param  list<array{start: float, end: float, text: string}>  $cues */
    private function lastWordHeardAt(MediaProcessingLog $log, array $cues, float $end, string $token): bool
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->timingWindow($cues, $end, $log->duration);
        if ($window === null) {
            return true;
        }

        return in_array($token, TranscriptCueEvidence::tokens(implode(' ', array_column($evidence->read($log, $window)['words'], 'word'))), true);
    }

    /** The run's classifier timeline, or null when it has none or it cannot be read. */
    public function audioTimeline(MediaProcessingLog $log): ?AudioTimeline
    {
        return $this->timeline($log);
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
     * A song start after a dropped sentence, moved off the decoded end of its last word while that word
     * still sounds: decoded word ends run early, and 1023 §1361 opened on the tail of "again" (operator,
     * 10-27). The start goes to the first quiet that begins within {@see self::DROPPED_WORD_REACH},
     * never past the next decoded word; a quiet word end, or sound that runs on, leaves it where it is.
     *
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}  $decision
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function afterDroppedWordSounds(array $decision, ?array $levels): array
    {
        $end = $decision['time'];
        $nearest = null;
        foreach ($levels['samples'] ?? [] as $sample) {
            if ($nearest === null || abs($sample['time'] - $end) < abs($nearest['time'] - $end)) {
                $nearest = $sample;
            }
        }

        if ($levels === null || $nearest === null || $nearest['rms'] < $levels['threshold']) {
            return $decision;
        }

        $quiet = $this->firstQuiet($levels, $end, min($decision['chosen_pause']['end'], $end + self::DROPPED_WORD_REACH));

        return $quiet === null ? $decision : [...$decision, 'time' => $quiet['start']];
    }

    /**
     * A spoken end at least {@see self::SPOKEN_END_TAIL} past its last word's decoded end, never
     * past the middle of the pause after it: the word sounds on after its decoded end.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function withSpokenEndTail(array $decision): array
    {
        $pause = $decision['chosen_pause'] ?? null;
        $word = $decision['word_before'] ?? null;

        if (! is_array($pause) || ! is_array($word)) {
            return $decision;
        }

        $from = max((float) $word['end'], (float) $pause['start']);
        $tail = min(self::SPOKEN_END_TAIL, max(0.0, ((float) $pause['end'] - $from) / 2));

        return [...$decision, 'time' => max((float) $decision['time'], $from + $tail)];
    }

    /**
     * A K16 end keeps none of the following line. Its decoded opening bounds the cut; a cue-only
     * start cannot shorten the kept word's tail (932's Amen and 1371's "fear" run past that start).
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{end: float|null, ties_to_sermon: bool|null, line: string|null}  $introduction
     * @param  list<array{start: float, end: float, word: string, window_start: float}>  $words
     * @return array<string, mixed>
     */
    private function beforeNextLine(array $decision, array $cues, array $introduction, array $words = []): array
    {
        foreach ($cues as $index => $cue) {
            if (abs($cue['end'] - $introduction['end']) > 0.001 || trim($cue['text']) !== $introduction['line']) {
                continue;
            }
            foreach (array_slice($cues, $index + 1) as $next) {
                if (! TranscriptCueEvidence::hasWords($next)) {
                    continue;
                }

                $bound = $next['start'];
                if ($words !== []) {
                    $lastWord = $decision['word_before'] ?? null;
                    $heard = $this->merged($words);
                    $keptWords = array_values(array_filter($heard, static fn (array $word): bool => $lastWord !== null
                        && $word['start'] <= $lastWord['start'] + 0.001 && TranscriptCueEvidence::tokens($word['word']) !== []));
                    $keptWord = $keptWords[array_key_last($keptWords)] ?? null;
                    $keptTokens = $keptWord === null ? [] : TranscriptCueEvidence::tokens($keptWord['word']);
                    $closingTokens = TranscriptCueEvidence::tokens($cue['text']);
                    // A pause already beyond the closing line must not protect an announcement or answering Amen. A
                    // kept word the next line does not say, decoded just after the next cue's start, is the closing
                    // line's: 1193's whole-second cues start the next line at 889.00, its "do that" decoded to 889.35.
                    // Later than that it is a word said again: 1273's answering "Amen." 1.34 s on.
                    $drifted = $keptWord !== null && $keptWord['start'] < $next['start'] + self::KEPT_WORD_CUE_DRIFT
                        && ! in_array(end($keptTokens), TranscriptCueEvidence::tokens($next['text']), true);
                    if ($keptWord !== null && ($keptWord['start'] < $next['start'] || $drifted) && end($keptTokens) === end($closingTokens)) {
                        $opening = $this->openingHeard($next, $heard, min($next['start'], $cue['end']));
                        $firstToken = TranscriptCueEvidence::tokens($next['text'])[0];
                        if ($opening !== null && (TranscriptCueEvidence::tokens($opening['word']['word'])[0] ?? null) === $firstToken
                            && $opening['word']['end'] - $opening['word']['start'] <= self::ANCHOR_REACH) {
                            $bound = $opening['word']['start'];
                        } else {
                            $tail = $this->withSpokenEndTail([...$decision, 'time' => $lastWord['end'] ?? 0.0]);
                            $bound = max($bound, $tail['time']);
                        }
                    }
                }

                return $decision['time'] >= $bound
                    ? [...$decision, 'time' => $bound, 'next_line_starts_at' => $next['start'], 'next_line_bound' => $bound]
                    : $decision;
            }

            break;
        }

        return $decision;
    }

    /**
     * A spoken end's tail stops where another decode of the recording heard the next word begin: the
     * pause one window's decode shows can be a dropout that lost the words. 1108's sermon ended
     * inside "Let" of "Let's stand to sing." (4119.76): its window's decode heard nothing after
     * "true." (4119.46), while the decodes either side heard "Let" at 4119.58 (operator listening,
     * 2026-10-18). Never before the last kept word's decoded end; smeared words time nothing. Another
     * decode's copy of the last kept word, with no quiet between them, is that word: its end carries the
     * tail on, and it is never the next word (1070, {@see self::SAME_WORD_GAP}, {@see self::SAME_WORD_QUIET}).
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array{start: float, end: float, word: string}>  $heardWords
     * @param  array{threshold: float, samples: list<array{time: float, rms: float}>}|null  $levels
     * @return array<string, mixed>
     */
    private function beforeWordHeardElsewhere(array $decision, array $heardWords, ?array $levels): array
    {
        $word = $decision['word_before'] ?? null;
        if (! is_array($word)) {
            return $decision;
        }

        $text = static fn (array $heard): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $heard['word']));
        $copies = $levels === null ? [] : array_filter($heardWords, fn (array $heard): bool => $heard['end'] - $heard['start'] <= self::ANCHOR_REACH
            && $heard['start'] > (float) $word['start'] + 0.001
            && $heard['start'] <= (float) $word['end'] + self::SAME_WORD_GAP && $text($heard) !== '' && $text($heard) === $text($word)
            && ! $this->quietBetween($levels, (float) $word['start'], (float) $heard['end']));
        if ($copies !== []) {
            $word = [...$word, 'end' => max((float) $word['end'], ...array_column($copies, 'end'))];
            $decision = [...$decision, 'word_before' => $word, 'last_word_heard_again_until' => $word['end']];
            if (is_array($decision['chosen_pause'] ?? null)) {
                $decision['chosen_pause']['start'] = max((float) $decision['chosen_pause']['start'], (float) $word['end']);
            }
            $decision = $this->withSpokenEndTail($decision);
        }
        if ($decision['time'] <= (float) $word['end'] + 0.001) {
            return $decision;
        }

        $starts = array_column(array_filter($heardWords, static fn (array $heard): bool => $heard['end'] - $heard['start'] <= self::ANCHOR_REACH
            && $heard['start'] > (float) $word['end'] + 0.001 && $heard['start'] < $decision['time'] - 0.001), 'start');

        return $starts === [] ? $decision : [...$decision, 'time' => min($starts), 'next_word_heard_at' => min($starts)];
    }

    /**
     * An end placed on the next word's decoded onset (another decode's next word, the next line's
     * heard first word, or the far side of its pause) ends in the quiet before that word's sound,
     * when the levels show one: the sound already up at the cut, and a point within
     * {@see self::ONSET_DIP_REACH} before it at least {@see self::ONSET_DIP_DECIBELS} under the
     * loudest there. 1288's sermon ended on the decoded start of "So let's sing…" (3755.31) with
     * "So" sounding from 3755.26; it ends in the quiet at 3755.19, after the congregation's Amen
     * (operator 10-29: "It's not the second Amen that's the problem"; heard with 928, 1340 and 1303,
     * "significantly better"). Never before the last kept word's decoded end.
     *
     * @param  array<string, mixed>  $decision
     * @param  array{threshold: float, samples: list<array{time: float, rms: float}>}|null  $levels
     * @return array<string, mixed>
     */
    private function beforeNextWordSound(array $decision, ?array $levels): array
    {
        $time = (float) $decision['time'];
        $onOnset = static fn (string $key): bool => isset($decision[$key]) && abs((float) $decision[$key] - $time) <= 0.001;
        $nextWord = $decision['word_after'] ?? null;
        $placedOnOnset = $onOnset('next_word_heard_at')
            || ($onOnset('next_line_bound') && ! $onOnset('next_line_starts_at'))
            || (is_array($nextWord) && abs((float) $nextWord['start'] - $time) <= 0.001);
        if ($levels === null || ! $placedOnOnset) {
            return $decision;
        }

        $lastWord = $decision['word_before'] ?? null;
        $from = max($time - self::ONSET_DIP_REACH, is_array($lastWord) ? (float) $lastWord['end'] : -INF);
        $beside = array_values(array_filter($levels['samples'], static fn (array $sample): bool => $sample['time'] >= $from - 0.001 && $sample['time'] <= $time + 0.001));
        $rms = static fn (array $sample): float => is_finite($sample['rms']) ? $sample['rms'] : -999.0;
        if ($beside === [] || $rms(end($beside)) < $levels['threshold'] + self::ONSET_HEARD_ABOVE) {
            return $decision;
        }

        $quietest = array_reduce($beside, static fn (array $carry, array $sample): array => $rms($sample) < $rms($carry) ? $sample : $carry, $beside[0]);
        if (max(array_map($rms, $beside)) - $rms($quietest) < self::ONSET_DIP_DECIBELS) {
            return $decision;
        }

        return [...$decision, 'time' => (float) $quietest['time'], 'before_next_word_sound' => ['placed_time' => $time, 'quiet_rms' => $rms($quietest)]];
    }

    /**
     * A spoken part starting as a song ends starts no earlier than the song's sound does, where the
     * song's own end rule found it stopping in silence, at speech, or fading out before speech it
     * knows follows: what plays before that is the song's outro. 1335's sermon opened on 12 s of
     * "Rock of Ages" and 1229's on "Creator God"'s outro, both joined straight from the reading
     * (operator listening, 2026-10-11). 1291's reading opened on its song's held last note, which the
     * decode heard as a pause between words; the song faded out at 1124.62, and the reading belongs
     * there (operator listening, 2026-10-29: "about 18:45"). Where a song fades out and the speech's
     * first words are smeared over its music, the speech starts where they were said
     * ({@see self::smearedOpening()}).
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans  The spans as detection gave them
     * @param  list<array{start_time: float, end_time: float}>  $spans  The spans as placed
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start_time: float, end_time: float}>
     */
    private function afterSongSound(MediaProcessingLog $log, array $cues, array $sectionSpans, array $spans, array &$audit): array
    {
        foreach ($sectionSpans as $index => $sectionSpan) {
            $original = (float) $sectionSpan['start_time'];
            $song = $log->serviceSections()->where('section_type', ServiceSectionType::Song->value)
                ->whereBetween('end_time', [$original - self::SONG_BEFORE_REACH, $original + self::CUE_BOUNDARY_TOLERANCE])
                ->where('start_time', '<', $original)->orderByDesc('end_time')->first();

            if ($song === null) {
                continue;
            }

            $songEnd = (float) $song->end_time;
            $window = app(OutputEdgeWordTimings::class)->timingWindow($cues, $songEnd, $log->duration);
            $decision = $this->songEnd($log, $cues, $window, $songEnd, $this->levels($log), $this->timeline($log));

            if (! in_array($decision['reason'] ?? null, ['song_end_silence', 'song_end_speech', self::SONG_END_FADE], true)) {
                continue;
            }

            // Only a fade leaves the speech's onset unestablished: silence or a heard onset already places it.
            $smeared = $decision['reason'] === self::SONG_END_FADE ? $this->smearedOpening($log, $decision['speech_cue'] ?? null) : null;
            $time = max((float) $decision['time'], $smeared['time'] ?? 0.0);

            if ($time <= $spans[$index]['start_time'] + self::CUE_BOUNDARY_TOLERANCE || $time >= $spans[$index]['end_time']) {
                continue;
            }

            $placed = $spans[$index]['start_time'];
            $spans[$index]['start_time'] = $time;
            $entry = array_key_first(array_filter($audit, static fn (array $entry): bool => $entry['span_index'] === $index && $entry['edge'] === 'start'));
            $moved = ['time' => $time, 'seconds_moved' => $time - $original, 'seconds_added' => abs($time - $original),
                'after_song_sound' => ['section_id' => $song->id, 'reason' => $decision['reason'], 'placed_time' => $placed,
                    ...($smeared !== null && $smeared['time'] > (float) $decision['time'] ? ['song_cut' => (float) $decision['time'], 'smeared_opening' => $smeared] : [])]];
            if ($entry === null) {
                $audit[] = ['span_index' => $index, 'edge' => 'start', 'original_time' => $original, 'window' => $window, 'cue' => null, 'cues' => [],
                    'chosen_pause' => null, 'word_before' => null, 'word_after' => null, 'reason' => 'after_song_sound', 'text_disagreement' => false, ...$moved];
            } else {
                $audit[$entry] = [...$audit[$entry], ...$moved];
            }
        }

        return $spans;
    }

    /**
     * Where a speech cue's first words were said when the decode smeared them over a song's fading
     * music: the first of its words heard at speaking pace, less {@see self::SMEARED_WORD_SECONDS}
     * for each smeared word before it. 1250 §3131's reading opened on 3.5 s of music because
     * "Marlene" ran 4.4 s up to "is" (operator listening, 2026-10-29); 1216, 1235 and 1342 heard
     * either way. Null when the cue's first word is heard at speaking pace, is not heard reaching
     * the cue, or no later word of the cue is.
     *
     * @param  array{start: float, end: float, text: string}|null  $speech
     * @return array{time: float, heard: string, smeared: int}|null
     */
    private function smearedOpening(MediaProcessingLog $log, ?array $speech): ?array
    {
        if ($speech === null) {
            return null;
        }

        $tokens = TranscriptCueEvidence::tokens($speech['text']);
        $words = $this->merged(array_map(static fn (array $word): array => [...$word, 'window_start' => $word['start']], array_values(array_filter(
            app(OutputEdgeWordTimings::class)->heardWords($log),
            static fn (array $word): bool => $word['end'] >= $speech['start'] - self::SONG_END_LEAD && $word['start'] <= $speech['end'],
        ))));
        $at = 0;
        $smeared = 0;

        foreach ($words as $word) {
            $heard = TranscriptCueEvidence::tokens($word['word']);

            if ($heard === [] || array_slice($tokens, $at, count($heard)) !== $heard) {
                continue;
            }

            $atSpeakingPace = $word['end'] - $word['start'] <= self::LONGEST_SPOKEN_WORD;

            if ($at === 0 && $atSpeakingPace) {
                return null;
            }

            $at += count($heard);

            if (! $atSpeakingPace) {
                $smeared++;

                continue;
            }

            return ['time' => round($word['start'] - self::SMEARED_WORD_SECONDS * $smeared, 3), 'heard' => $heard[0], 'smeared' => $smeared];
        }

        return null;
    }

    /**
     * A spoken edge that sits in a dead feed moves to the sound: a start to just before the first
     * thing heard, an end back to where the feed goes dead after the last. The words cannot place
     * these edges: a decode stretches the first or last cue over the silence (1023's "Well, I'm not
     * following…" ran 20 s, 16 s of it over a feed muted while the children went out; operator
     * listening, 2026-10-15). Only a dead feed counts, digital zero or a dropout at or below
     * {@see DeadFeedInsideSection::DROPOUT_LEVEL_DB}: a speaker's pause and a word's decay sit tens
     * of decibels above it, so no word's tail is cut (the 2026-10-12 clipped endings). Song starts
     * only remove verified digital zero and keep 0.3 s before the first finite sample (operator,
     * 2026-10-16). Song ends keep their existing rule; edges where two parts meet are not cuts.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans  The spans as detection gave them
     * @param  list<array{start_time: float, end_time: float}>  $spans  The spans as placed
     * @param  list<array<string, mixed>>  $audit
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return list<array{start_time: float, end_time: float}>
     */
    private function outOfDeadFeed(MediaProcessingLog $log, array $sectionSpans, array $spans, array &$audit, bool $songStartsOnly = false, ?array $levels = null): array
    {
        $levels ??= $this->levels($log);

        if ($levels === null) {
            return $spans;
        }

        foreach ($spans as $index => $span) {
            foreach ($songStartsOnly ? ['start' => 'start_time'] : ['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $neighbour = $edge === 'end' ? ($sectionSpans[$index + 1]['start_time'] ?? null) : ($sectionSpans[$index - 1]['end_time'] ?? null);
                if ($neighbour !== null && abs($neighbour - $sectionSpans[$index][$key]) < 0.001) {
                    continue;
                }

                $placed = (float) $span[$key];
                $time = match (true) {
                    $songStartsOnly => $this->songStartAfterDigitalSilence($levels, $placed, (float) $span['end_time']),
                    $edge === 'start' => $this->firstSoundAfterDeadFeed($levels, $placed, (float) $span['end_time']),
                    default => $this->deadFeedAfterLastSound($levels, (float) $span['start_time'], $placed),
                };

                if ($time === null) {
                    continue;
                }

                $spans[$index][$key] = $time;
                $entry = array_key_first(array_filter($audit, static fn (array $entry): bool => $entry['span_index'] === $index && $entry['edge'] === $edge));
                $original = $entry === null ? (float) $sectionSpans[$index][$key] : (float) $audit[$entry]['original_time'];
                $moved = ['time' => $time, 'seconds_moved' => $time - $original, 'seconds_added' => abs($time - $original),
                    'dead_feed' => ['placed_time' => $placed, 'time' => $time, ...($songStartsOnly ? ['digital_zero' => true] : [])]];
                if ($entry === null) {
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original, 'window' => null, 'cue' => null, 'cues' => [],
                        'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                        'reason' => $songStartsOnly ? 'song_start_digital_silence' : 'dead_feed', 'text_disagreement' => false, ...$moved];
                } else {
                    $audit[$entry] = [...$audit[$entry], ...$moved];
                }
            }
        }

        return $spans;
    }

    /**
     * A song that runs straight into another, where the transcript breaks down across the change,
     * ends at the quietest second near it when that lies well before its cut. The transcript placed
     * the change, so it cannot be trusted to have placed it right (1267 §3349 ran 35 s into the next
     * song). Heard for ends only: the next song's start keeps its rules until its starts are heard.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  list<array<string, mixed>>  $audit
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null  $levels
     * @return list<array{start_time: float, end_time: float}>
     */
    private function atSongChangeDip(MediaProcessingLog $log, array $cues, array $sectionSpans, array $spans, array &$audit, ?array $levels, bool $start = false): array
    {
        if ($levels === null || $spans === []) {
            return $spans;
        }
        $index = $start ? array_key_first($spans) : array_key_last($spans);
        $songStart = (float) $sectionSpans[array_key_first($spans)]['start_time'];
        $end = (float) $sectionSpans[$index]['end_time'];
        if ($start) {
            $before = $log->serviceSections()->where('start_time', '<', $songStart)
                ->where('end_time', '<=', $songStart + self::CUE_BOUNDARY_TOLERANCE)->orderByDesc('end_time')->orderByDesc('id')->first();
            if ($before?->section_type !== ServiceSectionType::Song || $songStart - (float) $before->end_time > self::SONG_END_REACH) {
                return $spans;
            }
            $afterEnd = $end;
            $end = (float) $before->end_time;
            $songStart = (float) $before->start_time;
        } else {
            $after = $log->serviceSections()->where('start_time', '>=', $end - self::CUE_BOUNDARY_TOLERANCE)->orderBy('start_time')->orderBy('id')->first();
            if ($after?->section_type !== ServiceSectionType::Song || (float) $after->start_time - $end > self::SONG_END_REACH) {
                return $spans;
            }
            $afterEnd = (float) $after->end_time;
        }
        if (! $this->transcriptBreaksDown($cues, $end)) {
            return $spans;
        }
        $span = $spans[$index];
        $edge = $start ? 'start' : 'end';
        $placed = (float) $span[$edge.'_time'];
        $dip = $this->quietestSecond($levels, max($end - self::SONG_CHANGE_SEARCH_BEFORE, $songStart + self::SONG_CHANGE_SONG_MINIMUM),
            min($end + self::SONG_CHANGE_SEARCH_AFTER, $afterEnd - self::SONG_CHANGE_SONG_MINIMUM));
        if ($dip === null || $dip['time'] >= $placed - self::SONG_CHANGE_MOVE || $dip['depth'] < self::SONG_CHANGE_DEPTH
            || $dip['time'] <= ($start ? $songStart : (float) $span['start_time']) || ($start && $dip['time'] >= (float) $span['end_time'])) {
            return $spans;
        }

        if ($start) {
            try {
                $previousPlan = $this->forSection($before);
            } catch (OutputEdgeTimingsMissing) {
                return $spans;
            }
            $previousEnd = $previousPlan['segments'] === [] ? null : end($previousPlan['segments'])['end_time'];
            if ($previousEnd === null || abs($previousEnd - $dip['time']) > self::CUE_BOUNDARY_TOLERANCE
                || ! array_any($previousPlan['cue_edge_widening'], static fn (array $entry): bool => $entry['edge'] === 'end'
                    && $entry['reason'] === 'song_change_dip' && abs($entry['time'] - $dip['time']) <= self::CUE_BOUNDARY_TOLERANCE)) {
                return $spans;
            }
        }

        $spans[$index] = $start
            ? ['start_time' => $dip['time'], 'end_time' => $span['end_time']]
            : ['start_time' => $span['start_time'], 'end_time' => $dip['time']];
        $entry = array_key_first(array_filter($audit, static fn (array $entry): bool => $entry['span_index'] === $index && $entry['edge'] === $edge));
        $original = $entry === null ? (float) $sectionSpans[$index][$edge.'_time'] : (float) $audit[$entry]['original_time'];
        $moved = ['time' => $dip['time'], 'seconds_moved' => $dip['time'] - $original, 'seconds_added' => abs($dip['time'] - $original),
            'reason' => 'song_change_dip', 'fade_out' => null, 'song_change_dip' => ['placed_time' => $placed, ...$dip]];
        if ($entry === null) {
            $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original, 'window' => null, 'cue' => null, 'cues' => [],
                'chosen_pause' => null, 'word_before' => null, 'word_after' => null, 'text_disagreement' => false, ...$moved];
        } else {
            $audit[$entry] = [...$audit[$entry], ...$moved];
        }

        return $spans;
    }

    /**
     * Whether the transcript fails to say what was sung across a change of song: a cue of
     * {@see self::SONG_CHANGE_STRETCHED_CUE} seconds or more, or the same words three cues running.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function transcriptBreaksDown(array $cues, float $change): bool
    {
        $near = array_values(array_filter($cues, static fn (array $cue): bool => $cue['end'] > $change - self::SONG_CHANGE_TRANSCRIPT_REACH
            && $cue['start'] < $change + self::SONG_CHANGE_TRANSCRIPT_REACH));
        usort($near, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $words = static fn (array $cue): string => trim((string) preg_replace('/[^a-z ]+/', '', strtolower($cue['text'])));

        foreach ($near as $index => $cue) {
            if ($cue['end'] - $cue['start'] >= self::SONG_CHANGE_STRETCHED_CUE) {
                return true;
            }
            if ($index >= 2 && $words($cue) !== '' && $words($cue) === $words($near[$index - 1]) && $words($cue) === $words($near[$index - 2])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quietest whole second between two times, by the loudest frame in it, and how far it falls
     * below the median of the 30 s before it. Seconds with no finite frame are not candidates.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     * @return array{time: float, level: float, depth: float}|null
     */
    private function quietestSecond(array $levels, float $from, float $until): ?array
    {
        $loudest = [];
        foreach ($levels['samples'] as $sample) {
            if (is_finite($sample['rms']) && $sample['time'] >= floor($from) - 30 && $sample['time'] < $until + 1) {
                $second = (int) floor($sample['time']);
                $loudest[$second] = max($loudest[$second] ?? -INF, $sample['rms']);
            }
        }
        $quietest = null;
        for ($second = (int) floor($from); $second <= (int) floor($until); $second++) {
            if (isset($loudest[$second]) && ($quietest === null || $loudest[$second] < $loudest[$quietest])) {
                $quietest = $second;
            }
        }
        if ($quietest === null) {
            return null;
        }
        $before = array_values(array_filter(array_map(static fn (int $second): ?float => $loudest[$second] ?? null, range($quietest - 30, $quietest - 1)), is_float(...)));
        if ($before === []) {
            return null;
        }
        sort($before);

        return ['time' => $quietest + 0.5, 'level' => $loudest[$quietest], 'depth' => $before[intdiv(count($before), 2)] - $loudest[$quietest]];
    }

    /**
     * A muted recording can open on a microphone check (957). Check the opening the dead-feed
     * rule actually exposes, after that rule, using the same banked identity as the edge step.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start_time: float, end_time: float}>
     */
    private function withDeadFeedOpeningCheck(MediaProcessingLog $log, array $cues, array $spans, array &$audit): array
    {
        foreach ($audit as &$entry) {
            if ($entry['edge'] !== 'start' || ! isset($entry['dead_feed']['time'])) {
                continue;
            }
            $index = (int) $entry['span_index'];
            $original = (float) $spans[$index]['start_time'];
            $placed = $this->wordPauseEdge($log, $cues, $original, 'start');
            if ($placed === null) {
                continue;
            }
            $decision = $this->placedEdge($log, $placed['words'], $placed['window'], $cues, $original, 'start', songEnds: false);
            [$decision, $check] = $this->sentenceChecked($log, $cues, $placed['words'], $placed['window'], $original, 'start', $decision, deadFeedOpening: true);
            // Only a move inward: the dead feed before this opening holds nothing to take back in.
            if (! ($check['moved'] ?? false) || $decision['time'] <= $original || $decision['time'] >= $spans[$index]['end_time']) {
                continue;
            }
            $spans[$index]['start_time'] = $decision['time'];
            $entry = [...$entry, ...$decision, 'reason' => 'sentence_check', 'sentence_check' => $check,
                'seconds_moved' => $decision['time'] - $entry['original_time'], 'seconds_added' => abs($decision['time'] - $entry['original_time'])];
        }
        unset($entry);

        return $spans;
    }

    /**
     * Never use a loudness threshold to discard a quiet note or a brief live sample. Only a
     * prefix of digital zero lasting at least two seconds may move a song's opening. An entirely
     * silent song has no opening to find; its existing content review remains responsible for it.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     */
    private function songStartAfterDigitalSilence(array $levels, float $start, float $until): ?float
    {
        $samples = $this->samplesBetween($levels, $start, $until);
        $first = $samples[0]['time'] ?? null;
        $previous = $start;
        $rms = app(RmsAnalysisService::class);

        foreach ($samples as $sample) {
            if (self::SONG_SILENCE_MAXIMUM_SAMPLE_GAP + 0.0001 < $sample['time'] - $previous) {
                return null;
            }
            $previous = $sample['time'];

            if ($rms->isDigitalSilence($sample['rms'])) {
                continue;
            }

            return $first !== null && $sample['time'] - $first >= self::DEAD_FEED_SECONDS
                ? max($start, $sample['time'] - self::DEAD_FEED_LEAD)
                : null;
        }

        return null;
    }

    /**
     * Just before the first sound heard after a start, when at least {@see self::DEAD_FEED_SECONDS}
     * of the stretch before it is dead feed; otherwise null.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     */
    private function firstSoundAfterDeadFeed(array $levels, float $start, float $until): ?float
    {
        $samples = $this->samplesBetween($levels, $start, $until);
        $heard = $this->heardRuns($samples, $levels['threshold']);

        if ($heard === []) {
            return null;
        }

        $sound = $heard[0]['start'];

        if ($this->deadSeconds($samples, $start, $sound) < self::DEAD_FEED_SECONDS) {
            return null;
        }

        $time = max($start, $sound - self::DEAD_FEED_LEAD);

        return $time > $start + 0.001 ? $time : null;
    }

    /**
     * Where the feed goes dead after the last sound heard before an end, when at least
     * {@see self::DEAD_FEED_SECONDS} of the stretch from there is dead feed; otherwise null.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     */
    private function deadFeedAfterLastSound(array $levels, float $from, float $end): ?float
    {
        $samples = $this->samplesBetween($levels, $from, $end);
        $heard = $this->heardRuns($samples, $levels['threshold']);

        if ($heard === []) {
            return null;
        }

        $sound = $heard[count($heard) - 1]['end'];
        $dead = null;
        $count = count($samples);

        for ($i = 0; $i < $count; $i++) {
            if ($samples[$i]['time'] <= $sound || ! $this->isDeadFeed($samples[$i]['rms'])) {
                continue;
            }

            $j = $i;
            while ($j + 1 < $count && $this->isDeadFeed($samples[$j + 1]['rms'])) {
                $j++;
            }

            $runEnd = $samples[$j + 1]['time'] ?? $end;
            if ($runEnd - $samples[$i]['time'] >= self::DEAD_FEED_RUN_SECONDS - 0.0001) {
                $dead = $samples[$i]['time'];

                break;
            }

            $i = $j;
        }

        if ($dead === null || $this->deadSeconds($samples, $dead, $end) < self::DEAD_FEED_SECONDS) {
            return null;
        }

        return $dead < $end - 0.001 ? $dead : null;
    }

    /**
     * Whether the levels fall below their threshold for {@see self::SAME_WORD_QUIET} or longer between two times.
     *
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     */
    private function quietBetween(array $levels, float $from, float $to): bool
    {
        $quietFrom = null;
        foreach ($this->samplesBetween($levels, $from, $to) as $sample) {
            if (is_finite($sample['rms']) && $sample['rms'] >= $levels['threshold']) {
                $quietFrom = null;

                continue;
            }
            $quietFrom ??= $sample['time'];
            if ($sample['time'] - $quietFrom >= self::SAME_WORD_QUIET) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     * @return list<array{time: float, rms: float}>
     */
    private function samplesBetween(array $levels, float $from, float $to): array
    {
        return array_values(array_filter($levels['samples'], static fn (array $sample): bool => $sample['time'] >= $from && $sample['time'] <= $to));
    }

    /**
     * The runs of consecutive live samples (above the dead feed) that last {@see self::HEARD_SECONDS}
     * and reach the threshold at least once.
     *
     * @param  list<array{time: float, rms: float}>  $samples
     * @return list<array{start: float, end: float}>
     */
    private function heardRuns(array $samples, float $threshold): array
    {
        $runs = [];
        $run = null;
        $heard = static fn (array $run): bool => $run['peak'] >= $threshold && self::HEARD_SECONDS - 0.0001 <= $run['end'] - $run['start'];

        foreach ($samples as $sample) {
            if (! $this->isDeadFeed($sample['rms'])) {
                $run = $run === null
                    ? ['start' => $sample['time'], 'end' => $sample['time'], 'peak' => $sample['rms']]
                    : [...$run, 'end' => $sample['time'], 'peak' => max($run['peak'], $sample['rms'])];

                continue;
            }

            if ($run !== null && $heard($run)) {
                $runs[] = ['start' => $run['start'], 'end' => $run['end']];
            }
            $run = null;
        }

        if ($run !== null && $heard($run)) {
            $runs[] = ['start' => $run['start'], 'end' => $run['end']];
        }

        return $runs;
    }

    /**
     * Seconds of dead feed between two times: each dead sample counts until the next sample.
     *
     * @param  list<array{time: float, rms: float}>  $samples
     */
    private function deadSeconds(array $samples, float $from, float $to): float
    {
        $seconds = 0.0;

        foreach ($samples as $index => $sample) {
            if ($sample['time'] < $from || $sample['time'] >= $to || ! $this->isDeadFeed($sample['rms'])) {
                continue;
            }

            $seconds += min($samples[$index + 1]['time'] ?? $to, $to) - $sample['time'];
        }

        return $seconds;
    }

    private function isDeadFeed(float $rms): bool
    {
        return $rms <= DeadFeedInsideSection::DROPOUT_LEVEL_DB;
    }

    /**
     * Where the words place an edge, with the guards on what they cannot show: one placement for
     * the plan and for the cut the sentence check is asked about, or a guarded edge's answer is
     * never found (Codex review, 2026-10-13).
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true}  $window
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function placedEdge(MediaProcessingLog $log, array $words, array $window, array $cues, float $original, string $edge, bool $songEnds, ?string $closingLine = null): array
    {
        $decision = $this->wordPause($words, $this->withoutClippedOpening($words, $window, $cues), $original, $edge, $closingLine);

        if ($edge === 'start') {
            $decision = $this->keptAtHeardStart($log, $words, $original, $decision);

            return $songEnds ? $this->keptMusicalOpening($log, $original, $decision) : $decision;
        }

        return $songEnds ? $decision : $this->keptOutOfMusic($log, $original, $decision);
    }

    /**
     * A window opens a second before its cues. Where another line is still being said there, the
     * margin before the decode's first word is clipped speech, not a pause: 1216's margin inside
     * "just been" started a song on the sermon's "talking about" (operator listening, 2026-10-26).
     * Heard again on 10-26, the cuts it moves in 961, 1221 and 1055 are as good or better. The
     * closing margin stays: the decode hears to the window's end, and a gap there after the last
     * word is heard (1200's song start after "to you.", ruled right).
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true}  $window
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true}
     */
    private function withoutClippedOpening(array $words, array $window, array $cues): array
    {
        $clipped = $words !== [] && array_any($cues, static fn (array $cue): bool => ! in_array($cue, $window['cues'], true)
            && $cue['start'] < $window['start'] - self::CUE_BOUNDARY_TOLERANCE && $cue['end'] > $window['start'] + self::CUE_BOUNDARY_TOLERANCE);

        return $clipped ? [...$window, 'start' => max($window['start'], min(array_column($words, 'start')))] : $window;
    }

    /**
     * Wide decoding supplies sentence stops, while matching words retain their original times.
     * A unique three-word match binds text across contexts; uncertain or changed words authorise
     * no stop. Only a sentence ending beyond the old decode may introduce a new timing anchor.
     *
     * @param  list<array{start: float, end: float, word: string}>  $timing
     * @param  list<array{start: float, end: float, word: string}>  $wide
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, right_boundary?: string}  $window
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return list<array{start: float, end: float, word: string}>
     */
    private function sentenceEvidenceAtTiming(array $timing, array $wide, array $window, array $cues): array
    {
        $result = [];
        $lastMatch = null;
        $match = null;
        foreach ($timing as $index => $word) {
            $from = max(0, min($index - 1, count($timing) - 3));
            $phrase = array_map(fn (array $token): string => $this->normalize($token['word']), array_slice($timing, $from, 3));
            $matches = [];
            if (count($phrase) === 3 && ! in_array('', $phrase, true)) {
                for ($i = 0; $i <= count($wide) - 3; $i++) {
                    if (array_map(fn (array $token): string => $this->normalize($token['word']), array_slice($wide, $i, 3)) === $phrase) {
                        $matches[] = $i + $index - $from;
                    }
                }
            }
            $match = count($matches) === 1 ? $matches[0] : null;
            $text = (string) preg_replace('/[.!?]+[\'"’”)]*$/u', '', trim($word['word']));
            if ($match !== null) {
                $lastMatch = $match;
                if ($this->wideSentenceStop($wide, $match, $window, $cues)) {
                    $text .= '.';
                }
            }
            $result[] = [...$word, 'word' => $text];
        }
        $last = $timing[array_key_last($timing)] ?? null;
        if ($last !== null && $lastMatch !== null && $match !== null) {
            foreach (array_slice($wide, $lastMatch + 1, null, true) as $index => $word) {
                if ($word['start'] < $last['end']) {
                    continue;
                }
                $text = (string) preg_replace('/[.!?]+[\'"’”)]*$/u', '', trim($word['word']));
                $result[] = [...$word, 'word' => $text.($this->wideSentenceStop($wide, $index, $window, $cues) ? '.' : '')];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, right_boundary?: string}  $window
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function wideSentenceStop(array $words, int $index, array $window, array $cues): bool
    {
        $word = $words[$index];
        $next = $words[$index + 1] ?? null;
        if (($window['right_boundary'] ?? null) === 'cap' && $word['end'] > $window['end'] - 1.0) {
            return false;
        }
        if ($next === null && array_any($cues, static fn (array $cue): bool => $cue['start'] < $word['end'] - self::CUE_BOUNDARY_TOLERANCE
            && $cue['start'] < $window['end'] - self::CUE_BOUNDARY_TOLERANCE && $cue['end'] > $window['end'] + self::CUE_BOUNDARY_TOLERANCE)) {
            return false;
        }

        return $this->endsSentence($word['word']) || ($next !== null && $next['start'] - $word['end'] >= self::SONG_START_SENTENCE_PAUSE);
    }

    /**
     * A song opening in a sentence drops the remainder of that sentence (operator, 2026-10-26).
     * Use only this edge's decode: overlapping windows can put the same word on both sides.
     * The classifier cannot distinguish an announcement over the band from singing (4735).
     * A full stop or a second without words ends the sentence; a window margin alone does not.
     * Smeared words cannot establish a sentence's timing, so they never authorise a trim.
     * A capped window cannot establish a stop in its last second. The 10-26 continuation
     * guard also remains: a full stop at the decode's end can be its guess at a cut-off word
     * ("the song that we play." for "played through before the service began", 948 §711).
     * A transcript cue continuing past it still makes that stop uncertain.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, right_boundary?: string}  $window
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}  $decision
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function afterSongOpeningSentence(array $words, array $window, array $cues, array $decision, bool $textEvidence = false): array
    {
        if (isset($decision['candidate_anchors'])) {
            return $decision;
        }

        $words = array_values(array_filter($words, static fn (array $word): bool => $word['end'] > $word['start']));
        $cut = $decision['time'];
        $before = null;
        $after = null;
        $inside = null;

        foreach ($words as $word) {
            if ($word['start'] < $cut && $word['end'] > $cut) {
                $inside = $word;
            }
            if ($word['end'] <= $cut) {
                $before = $word;
            }
            if ($after === null && $word['start'] >= $cut) {
                $after = $word;
            }
        }

        if ($inside === null && ($before === null || $after === null || $this->endsSentence($before['word'])
            || $after['start'] - $before['end'] >= self::SONG_START_SENTENCE_PAUSE)) {
            return $decision;
        }

        foreach ($this->pauses($words, $window) as $pause) {
            if ($pause['start'] < $cut || $pause['word_before'] === null) {
                continue;
            }
            if (array_any($words, static fn (array $word): bool => $word['end'] > $cut && $word['start'] <= $pause['start']
                && $word['end'] - $word['start'] > self::ANCHOR_REACH)) {
                return $decision;
            }
            if (! $this->endsSentence($pause['word_before']['word'])
                && ($textEvidence || $pause['word_after'] === null || $pause['length'] < self::SONG_START_SENTENCE_PAUSE)) {
                continue;
            }
            if (($window['right_boundary'] ?? null) === 'cap' && $pause['word_before']['end'] > $window['end'] - 1.0) {
                continue;
            }
            if ($pause['word_after'] === null && array_any($cues, static fn (array $cue): bool => $cue['start'] < $pause['start'] - self::CUE_BOUNDARY_TOLERANCE
                && $cue['start'] < $window['end'] - self::CUE_BOUNDARY_TOLERANCE && $cue['end'] > $window['end'] + self::CUE_BOUNDARY_TOLERANCE)) {
                return $decision;
            }

            return ['time' => $pause['start'], 'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
                'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
        }

        return $decision;
    }

    /**
     * The Amen that closes the item before is never where the next one opens, whoever says it: the pause rule opened
     * 982's reading on a 5 s smear of the prayer's "amen" and 1321's reading in the pause before the prayer's "Amen."
     * (defect-fix plan F4, 10-30). A spoken start whose first kept word is a lone Amen opens where that Amen stops. A song
     * start is left alone: its introduction can play in under the congregation's Amen (1282 §3533, heard right at 1902.16
     * with "Amen." from 1902.55).
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @return array<string, mixed>
     */
    private function afterClosingAmen(array $decision, array $words): array
    {
        $cut = (float) $decision['time'];
        $kept = array_values(array_filter($words, static fn (array $word): bool => $word['end'] > $cut + self::CUE_BOUNDARY_TOLERANCE));
        usort($kept, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // Where the next word starts before the Amen stops, there is no gap after it to open in.
        if ($kept === [] || $this->normalize($kept[0]['word']) !== 'amen'
            || (isset($kept[1]) && $kept[1]['start'] < $kept[0]['end'] - self::CUE_BOUNDARY_TOLERANCE)) {
            return $decision;
        }

        return ['time' => $kept[0]['end'], 'chosen_pause' => ['start' => $kept[0]['end'], 'end' => $kept[1]['start'] ?? $kept[0]['end']],
            'word_before' => $kept[0], 'word_after' => $kept[1] ?? null];
    }

    /**
     * A spoken end the pause rule carries outward into music stays where detection put it: a
     * 30 s "Thank you." whisper heard over 1275's next song widened the edge's window, and the
     * largest pause after a sung "Amen." took the sermon 19 s into the song (operator listening,
     * 2026-10-12). Moves within {@see self::MUSIC_MOVE_SECONDS}, such as the tail after the last
     * word, stand: the classifier's windows are coarser than that.
     *
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}  $decision
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function keptOutOfMusic(MediaProcessingLog $log, float $original, array $decision): array
    {
        if ($decision['time'] <= $original + self::MUSIC_MOVE_SECONDS) {
            return $decision;
        }

        $timeline = $this->timeline($log);

        if ($timeline === null || $timeline->musicShare($original, $decision['time']) < 0.5) {
            return $decision;
        }

        return ['time' => $original, 'chosen_pause' => ['start' => $original, 'end' => $original], 'word_before' => null, 'word_after' => null];
    }

    /**
     * A start moved inward off a decode smeared across it stays where detection put it when the
     * classifier hears speech there: the smear gives no word timing, so the pause after it may
     * drop the output's first sentence (932's 20 s "We", 1237's 6 s "busy"; operator listening,
     * 2026-10-11). Where the classifier hears music, the move may be dropping a song's outro, and
     * it stands.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}  $decision
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function keptAtHeardStart(MediaProcessingLog $log, array $words, float $original, array $decision): array
    {
        if ($decision['time'] <= $original + self::CUE_BOUNDARY_TOLERANCE
            || ! array_any($words, static fn (array $word): bool => $word['end'] - $word['start'] > self::ANCHOR_REACH && $word['start'] < $original - 0.001 && $word['end'] > $original + 0.001)) {
            return $decision;
        }

        $timeline = $this->timeline($log);
        $window = $timeline?->windowAt($original);

        // Speech above music, not confidently speech alone: 932's sermon opened in a window the
        // classifier heard as mixed.
        if ($timeline === null || $window === null || $timeline->windows[$window]['speech'] <= $timeline->windows[$window]['music']) {
            return $decision;
        }

        return ['time' => $original, 'chosen_pause' => ['start' => $original, 'end' => $original], 'word_before' => null, 'word_after' => null];
    }

    /**
     * A song's start detection put in music, which the pause rule moves later across music, stays
     * where detection put it: a detected musical opening is always in (D6). The pause after a decode stretched over the
     * opening dropped it: 1267's "Your" ran 10.7 s and took 10 s of introduction, 1275's `music*`
     * marker took 4 s, and 1267's "you." 1 s of a start the operator had heard as right
     * (corpus measurement, 2026-10-25). A move across speech, a spoken introduction, stands, as
     * does one across a window heard as both: at 63 song starts a band playing in under "Let's stand
     * and sing." made the window mixed, and keeping the start would cut into the spoken "sing.". 1200's start, detected in the
     * spoken "I will give thanks to you." and heard right after it, moves too.
     *
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}  $decision
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function keptMusicalOpening(MediaProcessingLog $log, float $original, array $decision): array
    {
        if ($decision['time'] <= $original + self::CUE_BOUNDARY_TOLERANCE) {
            return $decision;
        }

        $timeline = $this->timeline($log);
        $window = $timeline?->windowAt($original);

        // Where detection's start is still in speech, the opening is later and the move goes toward it.
        if ($timeline === null || $window === null || $timeline->classOf($window) !== SoundClass::Music
            || $timeline->classShare(SoundClass::Music, $original, $decision['time']) < 0.5) {
            return $decision;
        }

        return ['time' => $original, 'chosen_pause' => ['start' => $original, 'end' => $original], 'word_before' => null, 'word_after' => null];
    }

    /**
     * For an edge between two lines, the pause it sits in, or the one nearest it: the window's
     * margins are not where either line stops.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function nearestPause(array $words, array $window, float $original, string $edge): array
    {
        $inside = array_values(array_filter($this->pauses($words, $window), static fn (array $pause): bool => $pause['word_before'] !== null && $pause['word_after'] !== null));

        if ($inside === []) {
            return $this->pause($words, $window, $original, $edge);
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

            $times = [(float) $entry['original_time'], (float) $entry['time'], ...array_map(floatval(...), array_filter([$entry['moved_beside_song_from'] ?? null, $entry['onset_bound'] ?? null], is_numeric(...)))];
            [$from, $to] = [min($times) - self::SONG_END_REACH, max($times) + self::SONG_END_REACH];
            $words = [];

            foreach (array_filter([$entry['window'] ?? null, isset($entry['speech_cue']['start']) ? $evidence->timingWindow($cues, (float) $entry['speech_cue']['start'], $log->duration) : null]) as $window) {
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
     * The operator's start and end for this output ({@see OutputEdgeAnswers}), set by ear: applied
     * last, after the edge rules, the dead-feed rule (E18's order) and the unresolved intervals, so
     * no word-timing move takes them back. The planner's own edge is kept beside the answer.
     *
     * An answer is refused, and the planner's edge stands, where it would leave a part out, fall
     * outside the recording, or, moved outward past the planner's edge, cut into a section this
     * output does not include. Content the planner already reaches is not new: 1224's end, carried
     * into the hymn, may be set anywhere inside that stretch. A song's start may reach back into a
     * spoken section before it: "An intro to the song can also be the end of the sermon" (10-24,
     * 1149 §2219), as the musical-introduction rule crosses into a talk's tail
     * ({@see SongMusicalIntroduction::STOPS_AT}); another song or music before the service is not. The validator refuses a plan with a
     * refused answer ({@see self::refusedOperatorEdges()}), so it is changed, never silently dropped.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sectionSpans
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  list<array<string, mixed>>  $audit
     * @return list<array{start_time: float, end_time: float}>
     */
    private function withOperatorEdges(MediaProcessingLog $log, array $sectionSpans, array $spans, array &$audit, ?OutputEdgeAnswers $answers): array
    {
        if ($answers === null || $answers->isEmpty() || $spans === []) {
            return $spans;
        }

        $others = $log->serviceSections()->whereNotIn('id', $answers->outputSectionIds)->orderBy('start_time')->orderBy('id')->get();
        $ownerIsSong = $log->serviceSections()->whereKey($answers->ownerSectionId)->value('section_type') === ServiceSectionType::Song;

        foreach ($answers->answers as $edge => $answer) {
            $index = $edge === 'start' ? array_key_first($spans) : array_key_last($spans);
            $key = $edge === 'start' ? 'start_time' : 'end_time';
            $planned = $edge === 'start' ? $spans[$index]['start_time'] : $spans[$index]['end_time'];
            $time = (float) $answer['time'];
            $record = ['section_id' => $answers->ownerSectionId, ...$answer];
            $refusal = $this->operatorEdgeRefusal($log, $spans[$index], $edge, $planned, $time, $ownerIsSong && $edge === 'start'
                ? $others->filter(static fn (ServiceSection $section): bool => in_array($section->section_type, SongMusicalIntroduction::STOPS_AT, true))
                : $others);
            $entryKey = array_key_first(array_filter($audit, static fn (array $entry): bool => $entry['span_index'] === $index
                && $entry['edge'] === $edge && ($entry['reason'] ?? null) !== self::UNRESOLVED_INTERVAL));
            $original = $entryKey === null ? (float) ($sectionSpans[$index][$key] ?? $planned) : (float) $audit[$entryKey]['original_time'];

            if ($refusal !== null) {
                $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original, 'time' => $planned,
                    'seconds_added' => 0.0, 'seconds_moved' => 0.0, 'window' => null, 'cue' => null, 'cues' => [],
                    'chosen_pause' => null, 'word_before' => null, 'word_after' => null, 'reason' => self::OPERATOR_EDGE_REFUSED,
                    'refusal' => $refusal, 'operator_edge' => $record, 'text_disagreement' => false];

                continue;
            }

            $spans[$index] = $edge === 'start' ? ['start_time' => $time, 'end_time' => $spans[$index]['end_time']]
                : ['start_time' => $spans[$index]['start_time'], 'end_time' => $time];
            $moved = ['time' => $time, 'seconds_moved' => $time - $original, 'seconds_added' => abs($time - $original),
                'planned_time' => $planned, 'reason' => self::OPERATOR_SET_EDGE, 'operator_edge' => $record];

            if ($entryKey === null) {
                $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original, 'window' => null, 'cue' => null, 'cues' => [],
                    'chosen_pause' => null, 'word_before' => null, 'word_after' => null, 'planned_reason' => null, 'text_disagreement' => false, ...$moved];
            } else {
                $audit[$entryKey] = [...$audit[$entryKey], 'planned_reason' => $audit[$entryKey]['reason'] ?? null, ...$moved];
            }
        }

        return $spans;
    }

    /**
     * Why an operator's edge cannot be applied to its part, or null when it can.
     *
     * @param  array{start_time: float, end_time: float}  $span  The output's first part for a start, its last for an end
     * @param  Collection<int, ServiceSection>  $others  The run's sections this output does not cut
     */
    private function operatorEdgeRefusal(MediaProcessingLog $log, array $span, string $edge, float $planned, float $time, Collection $others): ?string
    {
        $at = ServiceTimestamp::format($time);

        if ($time < 0.0 || ($log->duration !== null && $time > (float) $log->duration + 0.001)) {
            return sprintf('The %s at %s is outside the recording.', $edge, $at);
        }

        if ($edge === 'start' ? $time >= $span['end_time'] - 0.001 : $time <= $span['start_time'] + 0.001) {
            return sprintf('The %s at %s would leave out the whole %s part (%s–%s).', $edge, $at, $edge === 'start' ? 'first' : 'last',
                ServiceTimestamp::format($span['start_time']), ServiceTimestamp::format($span['end_time']));
        }

        $outward = $edge === 'start' ? $time < $planned - 0.001 : $time > $planned + 0.001;

        if (! $outward) {
            return null;
        }

        [$from, $to] = [min($planned, $time), max($planned, $time)];

        foreach ($others as $section) {
            $overlap = min($to, (float) $section->end_time) - max($from, (float) $section->start_time);

            if ($overlap > self::CUE_BOUNDARY_TOLERANCE) {
                return sprintf('The %s at %s would cut %.1f s into “%s” (%s, §%d), which this output does not include. Move that section\'s bound first, or choose a time outside it.',
                    $edge, $at, $overlap, (string) $section->title, strtolower($section->section_type->label()), $section->id);
            }
        }

        return null;
    }

    /**
     * The operator's edges a plan could not apply, with the reason the planner gave
     * ({@see self::withOperatorEdges()}): the plan is not cut until each is changed or cleared.
     *
     * @param  list<array<string, mixed>>  $audit  a plan's `cue_edge_widening`
     * @return list<array{span_index: int, edge: string, refusal: string, section_id: int|null}>
     */
    public static function refusedOperatorEdges(array $audit): array
    {
        return array_values(array_map(
            static fn (array $entry): array => ['span_index' => (int) $entry['span_index'], 'edge' => (string) $entry['edge'],
                'refusal' => (string) ($entry['refusal'] ?? ''), 'section_id' => is_int($entry['operator_edge']['section_id'] ?? null) ? $entry['operator_edge']['section_id'] : null],
            array_filter($audit, static fn (array $entry): bool => ($entry['reason'] ?? null) === self::OPERATOR_EDGE_REFUSED),
        ));
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
        $window = $evidence->timingWindow($cues, $original, $log->duration);

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

        // A spoken edge: the sentence check is asked about the cut the plan makes.
        $decision = $this->placedEdge($log, $words, $window, $cues, $original, $edge, songEnds: false);

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
    private function sentenceChecked(MediaProcessingLog $log, array $cues, array $words, array $window, float $original, string $edge, array $decision, bool $deadFeedOpening = false): array
    {
        $check = app(SpokenEdgeSentenceCheck::class);

        if (! $check->canCheck($log, $original, $edge, $deadFeedOpening)) {
            return [$decision, null];
        }

        $evidence = app(OutputEdgeWordTimings::class);
        $sentenceWindow = $evidence->window($cues, $original, $log->duration, $log);
        $answer = null;
        $source = 'timing';
        if ($sentenceWindow !== null && isset($sentenceWindow['window_rule'])) {
            try {
                $sentenceWords = $evidence->read($log, $sentenceWindow)['words'];
                $answer = $check->read($log, $check->identityFor($log, $cues, $sentenceWords, $sentenceWindow, $original, $edge, $decision['time'], $deadFeedOpening));
                $source = 'wide';
            } catch (OutputEdgeTimingsMissing) {
                // An exact legacy question can still be answered from its original evidence.
            }
        }
        if ($answer === null) {
            // This is the original question and cut, not an answer rebound to the wider text.
            $answer = $check->read($log, $check->identityFor($log, $cues, $words, $window, $original, $edge, $decision['time'], $deadFeedOpening));
            $source = 'timing';
        }

        if ($answer === null) {
            return [$decision, ['asked' => false]];
        }

        $audit = ['asked' => true, ...$answer, 'moved' => false, 'evidence_source' => $source];

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
        $speech = $this->speechAfterSong($cues, $end, $levels, $timeline, $singing, $this->songAfter($this->songStarts($log), $end) !== null);
        $speechWords = [];

        if ($speech !== null) {
            $speechWindow = $evidence->timingWindow($cues, $speech['start'], $log->duration);
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

        // A cue opening where the classifier hears no speech is not where speech starts: 1117
        // §1959's announcement cue opens in music alone at 713.62, twelve seconds before it is
        // heard, and the cut there faded out the last sung word (canary 13).
        $speechHeardBy = $speech !== null && TranscriptCueEvidence::isEvidence($speech, $timeline) ? $this->speechHeardBy($timeline, $speech['start']) : null;
        if ($speechHeardBy !== null) {
            $bounds[] = $speech['start'];
        }

        if ($singing !== null) {
            $bounds[] = $singing['speech_by'];
        }

        // A song followed by another song with no speech between them ends by the next one's
        // start: with the next song's singing no longer taken for speech, 1250 §3127 ran on into
        // "O come, O come, Emmanuel" (canary 13).
        $nextSong = $this->songAfter($this->songStarts($log), $end);

        if ($nextSong !== null) {
            $bounds[] = max($end, $nextSong);
        }

        $until = min($bounds);
        $lastSound = $from;

        foreach ($words as $word) {
            if ($word['end'] <= $until && $this->isSung($word, $timeline)) {
                $lastSound = max($lastSound, $word['end']);
            }
        }

        // A silence the music comes back after is inside the outro, not its end: 1117 §1959 dipped
        // for 1.8 s after its last sung word and played on to 720 (canary 13). A cue start the
        // classifier hears speech after, not at, has not shown speech there: a second of silence
        // before that speech window ends is still the song's end. 954 §901's "Our gracious…" opened
        // in the music 6.5 s before it was said, after 3.9 s of silence (heard 10-20); 1216 §2655's
        // "Well, let's pray" opened 6 s before, and its cut there took the outro (heard 10-26). The
        // second of silence must pass before the cue has finished: 1366 §4567's "Prayer and commit
        // our service to the Lord. Let's pray." is spliced straight onto the recorded song, and its
        // only silence began as "pray" ended (heard 10-28).
        $silenceUntil = $speechHeardBy !== null && abs($until - $speech['start']) < 0.001
            ? max($until, min($speechHeardBy, $speech['end'] - self::SONG_END_SILENCE_SECONDS)) : $until;
        $silence = $levels !== null ? $this->beforeMusicResumes(fn (float $from): ?float => $this->silenceOnset($levels, $from, $silenceUntil), $levels, $timeline, $lastSound, $silenceUntil) : null;

        if ($silence !== null) {
            return ['time' => $silence, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                'reason' => 'song_end_silence', 'speech_cue' => $speech];
        }

        foreach ($onsets as $onset) {
            if ($onset['established'] && abs($onset['word']['start'] - $until) < 0.001) {
                $pause = $onset['pause'];

                $time = $this->cutInGap($pause, $lastSound, $until);

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
        // Where only the classifier bounds the speech, and its first window of speech holds speech
        // alone after the section, the speaking was under way at that window's start: 1221 §2730's
        // benediction was kept to 4205, the end of a window of speech alone from 4200 (canary 13).
        // Silence and a heard onset above still use the window's end: speech alone can open on a
        // pause. A window starting inside the section is the song's own run-on (1311 §4971).
        if (! $textBound && $singing !== null && ! $singing['inside'] && $singing['speech_alone_from'] !== null && abs($until - $singing['speech_by']) < 0.001) {
            $until = max($lastSound, $singing['speech_alone_from']);
        }
        $quiet = $levels !== null && ! $textBound ? $this->beforeMusicResumes(fn (float $from): ?array => $this->firstQuiet($levels, $from, $until), $levels, $timeline, $lastSound, $until) : null;
        $gap = $quiet ?? $this->lastGap($words, min($lastSound, $floor), $until);
        // A long decoded word can stand over the final sung phrase (1212's “Lord”). Its preceding
        // word gap cannot end the song when sound resumes in classified music after that gap.
        if ($nextSong === null && $quiet === null && $gap !== null && $levels !== null && $timeline !== null
            && array_any($words, static fn (array $word): bool => $word['end'] - $word['start'] > self::ANCHOR_REACH
                && $word['start'] >= $gap['end'] - 0.001 && $word['start'] < $until
                && $timeline->musicShare($word['start'], min($word['end'], $until)) > 0.0)) {
            $afterMusic = $this->beforeMusicResumes(fn (float $from): ?array => $this->lastGap($words, $from, $until), $levels, $timeline, min($lastSound, $floor), $until);
            $extendedCut = $this->cutInGap($afterMusic, $lastSound, $until);
            // Do not carry a song into a distinct spoken phrase (1211, 1250). Ending just
            // before it is still safe. Repeated cues are unreliable (1023), and short
            // smeared cues such as 1212's “Oh Lord” do not establish a phrase's onset.
            $insidePhrase = array_any($cues, static fn (array $cue): bool => $cue['start'] < $extendedCut && $cue['end'] > $extendedCut
                && count(TranscriptCueEvidence::tokens($cue['text'])) >= 3
                && array_any($words, static fn (array $word): bool => $word['end'] - $word['start'] > self::ANCHOR_REACH
                    && $word['start'] >= $gap['end'] - 0.001 && $word['start'] < $until
                    && array_intersect(TranscriptCueEvidence::tokens($word['word']), TranscriptCueEvidence::tokens($cue['text'])) !== [])
                && ! array_any($cues, static fn (array $other): bool => $other['start'] !== $cue['start']
                    && abs($other['start'] - $cue['start']) <= self::HELD_WORD_REPEATED_CUE
                    && TranscriptCueEvidence::tokens($other['text']) === TranscriptCueEvidence::tokens($cue['text'])));
            if (! $insidePhrase && ($afterMusic === null || abs($afterMusic['end'] - $gap['end']) > self::CUE_BOUNDARY_TOLERANCE)) {
                $gap = $afterMusic;
            }
        }

        $time = $gap !== null && $quiet !== null ? $gap['start'] + min(self::SONG_END_SILENCE_TAIL, ($gap['end'] - $gap['start']) / 2)
            : $this->cutInGap($gap, $lastSound, $until);
        // Only the reach bounds the search: nothing says where the speech starts.
        $unidentified = abs($until - ($end + self::SONG_END_REACH)) < 0.001;

        return ['time' => $time, 'chosen_pause' => $gap, 'word_before' => null, 'word_after' => null,
            'reason' => $unidentified ? self::SONG_END_UNRESOLVED : self::SONG_END_FADE, 'speech_cue' => $speech, 'onset_bound' => $until,
            ...($unidentified ? [] : ['fade_out' => $this->fadeOut($time, $lastSound)])];
    }

    /**
     * The cut in a word gap: just before the next word, or at most half the gap back from it;
     * with no gap, just before the bound, and never before the last sung sound.
     *
     * @param  array{start: float, end: float}|null  $gap
     */
    private function cutInGap(?array $gap, float $lastSound, float $until): float
    {
        return $gap !== null ? $gap['end'] - min(0.1, ($gap['end'] - $gap['start']) / 2) : max(min($lastSound, $until), $until - 0.1);
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
     * after the singing: no onset can lie later. `speech_alone_from` is that window's start when it
     * holds speech alone. `inside` says that window starts before the
     * song's end: only then may silence inside the section end the song, since a second of quiet
     * before a final chord is not its end (1112 §3739, ruled right at 1984.26).
     *
     * @return array{from: float, speech_by: float, speech_alone_from: float|null, inside: bool}|null
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
                'speech_alone_from' => $timeline->classOf($first) === SoundClass::Speech ? $timeline->windows[$first]['start'] : null,
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
     * @param  array{from: float, speech_by: float, speech_alone_from: float|null, inside: bool}|null  $singing
     * @param  bool  $songFollows  The next section is a song, whose opening lyric bounds this one
     * @return array{start: float, end: float, text: string}|null
     */
    private function speechAfterSong(array $cues, float $end, ?array $levels, ?AudioTimeline $timeline = null, ?array $singing = null, bool $songFollows = false): ?array
    {
        $from = $singing !== null && $singing['inside'] ? min($singing['from'], $end - self::SONG_END_LEAD) : $end - self::SONG_END_LEAD;
        $after = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] >= $from
            && $cue['start'] <= $end + self::SONG_END_REACH && TranscriptCueEvidence::hasWords($cue)));
        usort($after, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($after as $cue) {
            if (! $songFollows && $cue['start'] >= $end - self::SONG_END_LEAD && $this->cueIsOnlyMusic($cue, $timeline)) {
                continue;
            }
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
     * A lyric beyond a song's recorded edge is still singing (1291's "Your goodness…").
     * Require complete classifier coverage, music, and no speech anywhere in the cue; an early
     * speech cue spanning the transition must still be decoded at its own onset. Not asked when
     * a song follows: there the next song's first lyric bounds this one, and skipping it moved
     * 13 song ends, heard 10-20 as better twice and worse three times.
     *
     * @param  array{start: float, end: float, text: string}  $cue
     */
    private function cueIsOnlyMusic(array $cue, ?AudioTimeline $timeline): bool
    {
        if (count(TranscriptCueEvidence::tokens($cue['text'])) < 4) {
            return false;
        }
        $windows = array_values(array_filter($timeline->windows ?? [], static fn (array $window): bool => $window['end'] > $cue['start'] && $window['start'] < $cue['end']));

        return $windows !== [] && $windows[0]['start'] <= $cue['start'] && $windows[array_key_last($windows)]['end'] >= $cue['end']
            && array_any($windows, static fn (array $window): bool => $window['music'] >= AudioTimeline::MUSIC_CUTOFF)
            && ! array_any($windows, static fn (array $window): bool => $window['speech'] >= AudioTimeline::SPEECH_CUTOFF);
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
     * The first silence or quiet a finder returns from a time on that the music does not come back
     * after before the bound: sound returning in a window the classifier hears as music alone
     * means the song played on, so the search starts again there.
     *
     * @template T of float|array{start: float, end: float}
     *
     * @param  \Closure(float): (T|null)  $find
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}  $levels
     * @return T|null
     */
    private function beforeMusicResumes(\Closure $find, array $levels, ?AudioTimeline $timeline, float $from, float $until): float|array|null
    {
        while (($found = $find($from)) !== null) {
            $at = is_array($found) ? $found['start'] : $found;
            $back = null;

            foreach ($levels['samples'] as $sample) {
                if ($sample['time'] > $at && $sample['time'] < $until && $sample['rms'] >= $levels['threshold']) {
                    $back = $sample['time'];

                    break;
                }
            }

            $window = $back !== null ? $timeline?->windowAt($back) : null;

            if ($back === null || $timeline === null || $window === null || $timeline->classOf($window) !== SoundClass::Music) {
                return $found;
            }

            $from = $back;
        }

        return null;
    }

    /**
     * By when the classifier hears speech from a time: the time itself when its window holds speech,
     * the end of the next window when only that one does, or null.
     */
    private function speechHeardBy(?AudioTimeline $timeline, float $time): ?float
    {
        $window = $timeline?->windowAt($time);

        if ($timeline === null || $window === null || $timeline->windows[$window]['speech'] >= AudioTimeline::SPEECH_CUTOFF) {
            return $time;
        }

        return $window + 1 < $timeline->windowCount() && $timeline->windows[$window + 1]['speech'] >= AudioTimeline::SPEECH_CUTOFF
            ? (float) $timeline->windows[$window + 1]['end'] : null;
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
        return app(OutputEdgeDecodeWindow::class)->levels($log);
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
    private function cueBoundaryPause(array $words, array $window, float $original, string $edge, ?string $closingLine = null): ?array
    {
        $isStart = $edge === 'start';
        // A cue touching the edge from the other side shares the boundary: its words may belong
        // to this output too (949's "thank you Mark"), so only the largest pause can place it.
        // An end on a line that closes a sentence keeps that sentence and none of the next line:
        // the largest pause there sat mid-sentence (1286's "and | preach", canary 13 listening).
        // Before a bare hymn number, K16 can name an unpunctuated final phrase to keep whole.
        $closing = array_values(array_filter($window['cues'], fn (array $cue): bool => ! $isStart
            && abs($cue['end'] - $original) <= self::CUE_BOUNDARY_TOLERANCE
            && ($this->endsSentence($cue['text']) || ($closingLine !== null
                && $this->normalize($cue['text']) === $this->normalize($closingLine)
                && count(array_filter($window['cues'], static fn (array $next): bool => abs($next['start'] - $original) <= self::CUE_BOUNDARY_TOLERANCE
                    && preg_match('/^\d+$/', trim($next['text'])) === 1)) === 1))));
        if (count($window['cues']) !== 1 && count($closing) !== 1) {
            return null;
        }
        $shared = count($window['cues']) !== 1;
        $cue = $closing[0] ?? $window['cues'][0];
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
            ? $pause['end'] <= $anchor['start'] + 0.001 : $pause['start'] >= $anchor['end'] - 0.001);
        // A closing sentence heard twice is no better placed than before; the largest pause keeps
        // it (1105's "Amen. Amen.", ruled right).
        if (($pauses === [] && count($candidates) === 1) || ($shared && count($candidates) > 1)) {
            return null;
        }
        if ($pauses === []) {
            $decision = $this->pause($words, $window, $original, $edge);
        } else {
            $pause = $isStart ? $pauses[array_key_last($pauses)] : $pauses[array_key_first($pauses)];
            $decision = ['time' => max($pause['start'], min($original, $pause['end'])),
                'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
                'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
        }
        // The cut after the latest occurrence at an end, or before the earliest at a start, keeps
        // every one; a sentence heard twice on its own line is then placed, as 1105's is (941's
        // "Amen. Amen." beside its song, K16; 1300's opening "Politicians."). A phrase that is not
        // a whole sentence may be a neighbour's words, so it stays unresolved (F03).
        if (count($candidates) > 1 && ! $this->endsSentence($cue['text'])) {
            $decision['candidate_anchors'] = $candidates;
        }

        return $decision;
    }

    /**
     * Where the edge's words are placed: the cue's own anchor, else the pause nearest an edge
     * between two lines, else the largest pause. One placement for the plan and for the cut the
     * sentence check is asked about (Codex review, 2026-10-08: the two differed between lines).
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function wordPause(array $words, array $window, float $original, string $edge, ?string $closingLine = null): array
    {
        $decision = $this->cueBoundaryPause($words, $window, $original, $edge)
            ?? (isset($window['between']) ? $this->nearestPause($words, $window, $original, $edge) : $this->pause($words, $window, $original, $edge));

        if ($closingLine !== null && $edge === 'end') {
            $closing = $this->cueBoundaryPause($words, $window, $original, $edge, $closingLine);
            if ($closing !== null && $closing['time'] > $decision['time']) {
                $decision = $closing;
            }
        }

        return $edge === 'end' && ! isset($decision['candidate_anchors']) ? $this->atSentenceEnd($words, $window, $original, $decision) : $decision;
    }

    /**
     * An end the pause rule moved into the middle of a sentence stops instead at the sentence end
     * it passed, the one nearest where detection put it. The largest pause moved full-pass endings
     * inward past a closing "did?" ("Adoniram Judson | did?") or "shall we?" ("as | God's people"),
     * ruled "sentence cut off" (operator listening, 2026-10-12). A cut before every word of the
     * window drops them on purpose ("Thank you."), and an end is never carried past where either
     * detection or the pause put it: a later sentence end may be the next speaker's.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @param  array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}  $decision
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null, candidate_anchors?: list<array{start: float, end: float, word: string}>}
     */
    private function atSentenceEnd(array $words, array $window, float $original, array $decision): array
    {
        $before = $decision['word_before'];

        if ($before === null || $this->endsSentence($before['word'])) {
            return $decision;
        }

        [$from, $to] = [min($original, $decision['time']) - self::CUE_BOUNDARY_TOLERANCE, max($original, $decision['time']) + self::CUE_BOUNDARY_TOLERANCE];
        // The word detection's end falls inside is the output's to finish (1335's "did?" decoded past it).
        foreach ($words as $word) {
            if ($word['start'] < $original && $word['end'] > $original) {
                $to = max($to, $word['end'] + self::CUE_BOUNDARY_TOLERANCE);
            }
        }
        $distance = static fn (array $pause): float => abs(max($pause['start'], min($original, $pause['end'])) - $original);
        $sentenceEnds = array_values(array_filter($this->pauses($words, $window), fn (array $pause): bool => $pause['word_before'] !== null
            && $this->endsSentence($pause['word_before']['word']) && $pause['start'] >= $from && $pause['start'] <= $to));
        usort($sentenceEnds, static fn (array $a, array $b): int => $distance($a) <=> $distance($b));
        $pause = $sentenceEnds[0] ?? null;

        if ($pause === null) {
            return $decision;
        }

        // At the sentence's last word; the spoken-end tail then gives it room.
        return ['time' => $pause['start'], 'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    private function endsSentence(string $text): bool
    {
        return preg_match('/[.!?][\'"’”)]*$/u', trim($text)) === 1;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function pause(array $words, array $window, float $original, string $edge): array
    {
        $pauses = $this->pauses($words, $window);
        if ($pauses === []) {
            throw new \RuntimeException('edge_word_timings_invalid: no pause adjacent to edge cues');
        }
        // A word smeared beyond the anchor's reach was not said where the decode puts it, so a
        // cut against it is not where the output starts or ends: the window margin before a 5.7 s
        // "Let's" moved 1273's song start 17 s into the song before (canary 13 listening).
        // Nor does a cut move inward past one, dropping what may be the output's own words: its
        // timing is unknown, not silence (1311 §4876's start jumped 29 s past a smeared "The" and
        // "End"; canary 14). With no pause left, the edge stays where detection put it.
        $smeared = array_values(array_filter($words, static fn (array $word): bool => $word['end'] - $word['start'] > self::ANCHOR_REACH));
        $placed = array_values(array_filter($pauses, static function (array $pause) use ($edge, $original, $smeared): bool {
            $time = max($pause['start'], min($original, $pause['end']));
            [$word, $against] = $edge === 'start' ? [$pause['word_after'], $pause['end']] : [$pause['word_before'], $pause['start']];

            if ($word !== null && abs($time - $against) <= 0.001 && $word['end'] - $word['start'] > self::ANCHOR_REACH) {
                return false;
            }

            $inward = $edge === 'start' ? $time > $original + 0.001 : $time < $original - 0.001;
            [$from, $to] = [min($time, $original), max($time, $original)];

            return ! $inward || ! array_any($smeared, static fn (array $word): bool => $word['start'] >= $from - 0.001 && $word['end'] <= $to + 0.001);
        }));

        if ($placed === []) {
            return ['time' => $original, 'chosen_pause' => ['start' => $original, 'end' => $original], 'word_before' => null, 'word_after' => null];
        }

        $pauses = $placed;
        $distance = static fn (array $pause): float => abs(max($pause['start'], min($original, $pause['end'])) - $original);
        usort($pauses, static fn (array $a, array $b): int => ($b['length'] <=> $a['length']) ?: $distance($a) <=> $distance($b));
        // With no gap long enough to be observed, the largest is noise: the edge stays nearest
        // where it was (1273's contiguous words after its smeared opening).
        if ($pauses[0]['length'] < self::OBSERVED_GAP - 0.001) {
            usort($pauses, static fn (array $a, array $b): int => $distance($a) <=> $distance($b));
        }
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
            // A decode can end its last word a float's width past the window (1292's "old." at
            // 1506.0000000000002 in a window to 1506): that is no word sounding beyond it.
            if ($end >= $frontier - 0.001) {
                $pauses[] = ['start' => min($frontier, $end), 'end' => $end, 'length' => max(0.0, $end - $frontier), 'word_before' => $left, 'word_after' => $right];
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
