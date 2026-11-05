<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\MediaProcessingVersion;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiFlexFallback;
use App\Support\OpenAiUsageLogger;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Where a sermon ends at a song or the recording's end (register K16, operator 2026-10-28):
 * at its own last line, before the song's introduction, whether or not it relates to the sermon.
 * Banked answers to the original introduction question supply the sermon's and video's last lines.
 * An untied answer keeps a later closing prayer through the preacher's Amen, within its video vote;
 * a standalone answering Amen immediately after an Amen line is not part of the sermon.
 *
 * That is a judgement of meaning, so it is asked of the structure model, as the sentence check is
 * ({@see SpokenEdgeSentenceCheck}). The read-only measurement of 2026-10-16 over 280 sermons
 * (`storage/scratch/weekly-edge-tool-20261016/`) found answers that agree on the judgement but
 * name different last lines (1160), and a garbled introduction one answer missed (1005). So it is
 * asked twice, and a third time when the two differ in judgement, sermon line or video line (the
 * video line still bounds the Amen extension); the majority judgement
 * stands, with a line its answers name at least twice (operator, 2026-10-16).
 *
 * The preacher must say how the song relates to the sermon: reading its words aloud is not a tie
 * on its own (operator, 2026-10-16, on 1040, 1311 and 932).
 *
 * The sermon's own conclusion or application stays in it even when it names, quotes or leads into
 * the song (K16 reworded 2026-10-29, adopted as measured 2026-10-30, version 3): 1131 and 1140 lost
 * their last point under version 2. Its measured losses (968, 1375, 1324) are accepted.
 *
 * The classifier scores 5 s windows, so the first music window says only that the music starts
 * somewhere in it: 1224's "…destruction, | and despair." was spoken at the start of a mixed window.
 * Lines starting in that window may complete a sentence; lines after it are never in the sermon.
 *
 * Answers are banked on the edge step ({@see self::prepare()}) before the edge words are decoded,
 * so the words are decoded where the sermon now ends; planning only reads them ({@see self::read()}).
 *
 * An earlier part of the output with a song between it and the next part is asked the same way
 * ({@see self::innerParts()}): a reading before the song that precedes the sermon (1154's "We're
 * going to stand and sing again", 1300) or a split sermon's first half before a mid-sermon song
 * (1073, 1268; register D21). Only the part's own words are in it (K1); a reading or prayer is
 * asked with {@see self::PART_SYSTEM_PROMPT}, since an introduction tying the song to a sermon
 * still to come is not part of the reading. The last part's question is unchanged.
 */
class SermonSongIntroductionCheck
{
    /** Bumped whenever the prompt changes, so answers to an older question are not reused. */
    public const PROMPT_VERSION = 3;

    private const ARTIFACT_PREFIX = 'sermon-song-introduction-';

    /** A song starting further than this after the sermon's end has no introduction to judge. */
    private const SONG_REACH = 150.0;

    /** Seconds of the sermon the model reads before its end. */
    private const SERMON_CONTEXT_SECONDS = 75.0;

    /** Music starting this long before the sermon's recorded end is under the sermon, not the song. */
    private const MUSIC_LEAD = 5.0;

    /** Seconds past the song's recorded start the model reads, for speech the song section took in. */
    private const PAST_SONG_START = 30.0;

    /** Lines shown after the music has started, so the model hears where the song begins. */
    private const LINES_AFTER_MUSIC = 3;

    /**
     * A line left open on a clause: a sentence split there (1001, 10-29, "And celebrate his mercy to
     * you,"). The end steps back to the line before, as the shorter whole-sentence end is preferred.
     * An unpunctuated line is not taken as open: whole transcripts arrive without punctuation. Nor is
     * a line from the song's recorded start on: a transcribed lyric ends most lines on a comma.
     */
    private const OPEN_CLAUSE = '/[,;:\x{2013}\x{2014}-][\s"\'\x{201D}\x{2019}]*$/u';

    /** A line ending a sentence. */
    private const FINISHED = '/[.!?][\s"\'\x{201D}\x{2019})]*$/u';

    /** A line whose last word is "Amen". */
    private const ENDS_ON_AMEN = '/(?:^|[^\p{L}\p{N}])amen[\s\p{P}]*$/iu';

    /** A cue whose first word is "Amen". */
    private const OPENS_ON_AMEN = '/^[\s\p{P}]*amen(?:[^\p{L}\p{N}]|$)/iu';

    /** A pause this long between cues ends a line, sentence or not. */
    private const LINE_PAUSE_SECONDS = 1.5;

    /** Cues joined into one line give up after this many words without a sentence end. */
    private const LINE_WORDS = 30;

    private const SYSTEM_PROMPT = <<<'TXT'
You read the end of a sermon in a church-service transcript and what is said between it and the next song. Rules from the person who publishes the sermon videos:
- The sermon video ends at the join to the song, and never after the music has started. Lines marked [music] are after the music started and are never in the video. Lines marked [music may start] may be in the video only to finish a sentence begun before them.
- The preacher's introduction to the song belongs in the sermon video when the preacher says how the song relates to the sermon (why it was chosen for it, what it says about the sermon's subject, that it was quoted in the sermon), so that it works as the sermon's conclusion. A verse read aloud is part of such an introduction.
- Reading the song's words aloud without saying how they relate to the sermon does not tie it, even when the words fit the sermon's theme.
- A bare announcement (the song's name or number, "let's stand and sing"), the introduction to a song that is not connected to the sermon, notices and housekeeping do not belong.
- A "let's stand and sing" call on its own is never included, even at the end of an introduction that is.
- It is not about length: a long introduction that ties the song to the sermon belongs; a short one that does not, does not.
- The sermon's own conclusion or application is part of the sermon even when it mentions, quotes or leads into the song: it stays when it is fundamentally the sermon that happens to mention the song. A brief remark that the song fits the theme is part of the introduction, not the sermon.
- The video is one continuous stretch. If notices or housekeeping (anything more than announcing the song) come between the sermon and the introduction, the video ends at the sermon's own last line.
Transcript punctuation and line breaks are automatic, and some words are misheard.
Answer in JSON: {"sermon_last_line": <number of the last line of the sermon itself, including its own conclusion or application even when that names the song; before any line whose purpose is to introduce or announce the song>, "ties_to_sermon": <true when the video takes in an introduction that ties the song to the sermon, otherwise false>, "video_last_line": <number of the last line in the sermon video>, "reason": "<one sentence>"}
TXT;

    /** A reading or prayer before the sermon, as an earlier part of the sermon video beside a song. */
    private const PART_SYSTEM_PROMPT = <<<'TXT'
You read the end of a Bible reading or prayer in a church-service transcript and what is said between it and the next song. The sermon video includes the reading or prayer as a part of its own, and the sermon comes later. Rules from the person who publishes the sermon videos:
- The part ends at its own last line: the end of the reading, with the reader's own closing words about it ("This is the word of the Lord", "We thank God for his word"), or the end of the prayer with its Amen.
- Nothing after that belongs: announcing or introducing the song, "let's stand and sing", notices and housekeeping, even when they relate the song to the reading or the sermon.
- Lines marked [music] are after the music started and are never in the part. Lines marked [music may start] may be in it only to finish a sentence begun before them.
Transcript punctuation and line breaks are automatic, and some words are misheard.
Answer in JSON: {"sermon_last_line": <number of the part's own last line>, "ties_to_sermon": false, "video_last_line": <the same number>, "reason": "<one sentence>"}
TXT;

    /** Section types asked with {@see self::PART_SYSTEM_PROMPT} when they are an earlier part. */
    private const PART_TYPES = [ServiceSectionType::BibleReading, ServiceSectionType::Prayer];

    /**
     * The earlier parts of the output with a song between each and the next part, within {@see self::SONG_REACH}.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans  The sermon output's parts, as detected
     * @return list<int> Their indexes in $sermonSpans
     */
    public function innerParts(MediaProcessingLog $log, array $sermonSpans): array
    {
        $parts = [];
        foreach (array_keys($sermonSpans) as $index) {
            if ($index !== array_key_last($sermonSpans) && $this->join($log, $sermonSpans, $index) !== null) {
                $parts[] = $index;
            }
        }

        return $parts;
    }

    /**
     * Ask about the sermon output's end, or an earlier part's ({@see self::innerParts()}), unless an
     * answer to the same question is banked.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans  The sermon output's parts, as detected
     * @param  int|null  $part  An earlier part's index; null for the output's end
     * @return array{asked: bool, end: float|null, ties_to_sermon: bool|null}
     */
    public function prepare(MediaProcessingLog $log, array $sermonSpans, ?int $part = null): array
    {
        $question = $this->question($log, $sermonSpans, $part);

        if ($question === null) {
            return ['asked' => false, 'end' => null, 'ties_to_sermon' => null];
        }

        if ($question['lines'] === []) {
            return ['asked' => false, 'end' => $question['end'], 'ties_to_sermon' => null];
        }

        return $this->answerQuestion($log, $question);
    }

    /**
     * Ask a question {@see question()} built, unless an answer to it is banked. A cut probe builds
     * it from a proposed structure's sections and banks the answer on the run itself.
     *
     * @param  array{identity: array<string, mixed>, prompt: string, lines: list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>, end?: float|null}  $question
     * @return array{asked: bool, end: float|null, ties_to_sermon: bool|null}
     */
    public function answerQuestion(MediaProcessingLog $log, array $question): array
    {
        $banked = $this->answer($log, $question['identity']);

        if ($banked !== null) {
            return ['asked' => false, 'end' => $banked['end'], 'ties_to_sermon' => $banked['ties_to_sermon']];
        }

        $system = $this->systemPrompt($question['identity']);
        $calls = [$this->ask($log, $question['prompt'], $system), $this->ask($log, $question['prompt'], $system)];

        if ($this->needsThirdAnswer($calls)) {
            $calls[] = $this->ask($log, $question['prompt'], $system);
        }

        return $this->bank($log, $question, $calls);
    }

    /**
     * A banked pair of answers that disagree, banked before the third answer was asked on the
     * sermon line, gets the third answer {@see prepare()} now asks (operator 2026-10-29: 14 of 420
     * banked pairs differed only there and took the earlier line). Answers that agree, or already
     * have a third, are left as they are.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans  The sermon output's parts, as detected
     * @return array{asked: bool, end: float|null, ties_to_sermon: bool|null}|null Null when nothing is banked for the current question
     */
    public function settleBankedSplit(MediaProcessingLog $log, array $sermonSpans): ?array
    {
        $question = $this->question($log, $sermonSpans);

        if ($question === null || $question['lines'] === []) {
            return null;
        }

        $payload = $this->bankedPayload($log, $question['identity']);

        if ($payload === null) {
            return null;
        }

        $calls = $this->bankedCalls($payload);

        if (count($calls) !== 2 || ! $this->needsThirdAnswer($calls)) {
            $banked = $this->answer($log, $question['identity']);

            return ['asked' => false, 'end' => $banked['end'] ?? null, 'ties_to_sermon' => $banked['ties_to_sermon'] ?? null];
        }

        $calls[] = $this->ask($log, $question['prompt']);

        return $this->bank($log, $question, $calls);
    }

    /** @param  list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>  $calls */
    private function needsThirdAnswer(array $calls): bool
    {
        return $calls[0]['ties_to_sermon'] !== $calls[1]['ties_to_sermon']
            || $calls[0]['sermon_last_line'] !== $calls[1]['sermon_last_line']
            || $calls[0]['video_last_line'] !== $calls[1]['video_last_line'];
    }

    /**
     * @param  array{identity: array<string, mixed>, prompt: string, lines: list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>}  $question
     * @param  list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>  $calls
     * @return array{asked: bool, end: float|null, ties_to_sermon: bool|null}
     */
    private function bank(MediaProcessingLog $log, array $question, array $calls): array
    {
        $decision = $this->decision($calls, $question['lines'], 'sermon_last_line', $this->identitySongStart($question['identity']), $this->identityPartEnd($question['identity']));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $this->kind($question['identity']), [
            'calls_completed' => true, 'identity' => $question['identity'], 'prompt' => $question['prompt'], 'lines' => $question['lines'], 'calls' => $calls, ...$decision,
        ]);
        $log->refresh();

        return ['asked' => true, 'end' => $decision['end'], 'ties_to_sermon' => $decision['ties_to_sermon']];
    }

    /**
     * Whether a song follows the sermon output closely enough for this rule, and its start.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     */
    public function songStart(MediaProcessingLog $log, array $sermonSpans): ?float
    {
        $join = $this->join($log, $sermonSpans);

        return ($join['song'] ?? null) !== null ? (float) $join['song']->start_time : null;
    }

    /**
     * The banked answer for the sermon output's end (or an earlier part's), or null when there is no
     * song beside it or the question was never asked of this transcript. With nothing credibly spoken
     * before the song there is nothing to judge, and the detected end stands.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @param  int|null  $part  An earlier part's index; null for the output's end
     * @return array{end: float|null, ties_to_sermon: bool|null, line: string|null}|null
     */
    public function read(MediaProcessingLog $log, array $sermonSpans, ?int $part = null): ?array
    {
        $question = $this->question($log, $sermonSpans, $part);

        return match (true) {
            $question === null => null,
            $question['lines'] === [] => ['end' => $question['end'], 'ties_to_sermon' => null, 'line' => null],
            default => $this->answer($log, $question['identity']),
        };
    }

    /**
     * The next song after the sermon output, reached across items that are no clip of their own
     * (an introduction, notices, a prayer), or the recording ending before it, within {@see self::SONG_REACH}.
     * For an earlier part, only a song that starts before the next part does.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @param  int|null  $part  An earlier part's index; null for the output's end
     * @return array{song: ServiceSection|null, end: float}|null
     */
    public function join(MediaProcessingLog $log, array $sermonSpans, ?int $part = null): ?array
    {
        if ($sermonSpans === []) {
            return null;
        }

        $index = $part ?? array_key_last($sermonSpans);
        $start = (float) $sermonSpans[$index]['start_time'];
        $end = (float) $sermonSpans[$index]['end_time'];
        $nextPart = isset($sermonSpans[$index + 1]) ? (float) $sermonSpans[$index + 1]['start_time'] : null;
        $handlers = app(SectionPublicationHandlerFactory::class);

        foreach ($log->serviceSections()->orderBy('start_time')->orderBy('id')->get() as $section) {
            // What comes after the sermon, including a section that overlaps its end: 1251's song
            // starts 0.11 s before the detected end (as e32b13e0d found for the prayer walk).
            if ((float) $section->end_time <= $end + 0.05 || (float) $section->start_time <= $start) {
                continue;
            }

            if ((float) $section->start_time > $end + self::SONG_REACH
                || ($nextPart !== null && (float) $section->start_time >= $nextPart - 0.05)) {
                return null;
            }

            if ($section->section_type === ServiceSectionType::Song) {
                return ['song' => $section, 'end' => (float) $section->start_time];
            }

            if ($handlers->forSection($section) !== null || $section->section_type === ServiceSectionType::Sermon) {
                return null;
            }
        }

        if ($nextPart !== null) {
            return null;
        }

        $cues = app(OutputEdgeWordTimings::class)->cues($log);
        $recordingEnd = $cues === [] ? null : (float) $cues[array_key_last($cues)]['end'];

        if ($recordingEnd === null || $recordingEnd <= $start || $recordingEnd < $end - self::SONG_REACH || $recordingEnd > $end + self::SONG_REACH) {
            return null;
        }

        return ['song' => null, 'end' => $recordingEnd];
    }

    /**
     * The question for the sermon output's end (or an earlier part's): the numbered lines the model
     * reads, the prompt and the identity an answer is banked under. Null when neither join is in reach;
     * no lines when nothing credible is said near the join.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @param  int|null  $part  An earlier part's index; null for the output's end
     * @return array{end: float, lines: list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>, prompt: string, identity: array<string, mixed>}|null
     */
    public function question(MediaProcessingLog $log, array $sermonSpans, ?int $part = null): ?array
    {
        $last = $part ?? array_key_last($sermonSpans);
        $join = $this->join($log, $sermonSpans, $part);

        if ($last === null || $join === null) {
            return null;
        }

        $end = (float) $sermonSpans[$last]['end_time'];
        $partType = $part === null || $part === array_key_last($sermonSpans) ? null : $this->partType($log, (float) $sermonSpans[$last]['start_time'], $end);
        // An earlier part never runs into the next detected item outside the output (1233's prayer before the song).
        $nextItem = $partType === null ? null : $this->nextItemStart($log, $end, $join['end']);
        $song = $join['song'];
        if ($song === null && $log->duration !== null && $end > $log->duration && $end <= $log->duration + 1.0) {
            $end = (float) $log->duration;
        }
        $songStart = $join['end'];
        $timeline = app(CueSafeExtractionPlan::class)->audioTimeline($log);
        $music = $this->firstMusicWindow($timeline, $end - self::MUSIC_LEAD);
        $lines = [];
        $afterMusic = 0;

        foreach (app(OutputEdgeWordTimings::class)->cues($log) as $cue) {
            if (! TranscriptCueEvidence::isEvidence($cue, $timeline) || $cue['end'] <= $end - self::SERMON_CONTEXT_SECONDS) {
                continue;
            }

            $state = match (true) {
                $music !== null && $cue['start'] >= $music['end'] => 'yes',
                $music !== null && $cue['start'] >= $music['start'] => 'may',
                default => 'no',
            };

            if ($cue['start'] > $songStart + self::PAST_SONG_START || ($state === 'yes' && ++$afterMusic > self::LINES_AFTER_MUSIC)
                || ($nextItem !== null && $cue['start'] >= $nextItem - 0.05)) {
                break;
            }

            $lines[] = ['n' => count($lines) + 1, 'start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => trim($cue['text']), 'music' => $state];
        }

        if ($lines === []) {
            return ['end' => $end, 'lines' => [], 'prompt' => '', 'identity' => []];
        }

        $lines = $this->sentenceLines($lines, $end);

        $prompt = $this->prompt($log, $song, $songStart, $end, $lines, $partType);

        return ['end' => $end, 'lines' => $lines, 'prompt' => $prompt, 'identity' => [
            ...($partType === null ? [] : ['part' => $partType->value]),
            'processing_id' => $log->processing_id,
            'sermon_end' => round($end, 3),
            'song_start' => $song === null ? null : round($songStart, 3),
            ...($song === null ? ['recording_end' => round($songStart, 3)] : []),
            'model' => (string) config('media-processing.service_structure.model'),
            'prompt_version' => self::PROMPT_VERSION,
            'transcript' => hash('sha256', $prompt),
            'media_processing' => MediaProcessingVersion::signature(),
        ]];
    }

    /**
     * The question's lines as sentences (item 6, operator 11-04): on fragment-cue runs (956 1.7 words
     * a cue, 962 2.7) the model chose "Preaching" and "pray this", both mid-sentence.
     *
     * Consecutive cues join into one line only when sentence evidence closes it: a cue ending in
     * {@see self::FINISHED}, a cue ending on an Amen, or the next cue opening on one, so an Amen is
     * never joined to the words around it (1254's "Amen Let's sing together", 1204's answering
     * Amen). A run closed instead by a pause, a change of music state, the detected end or
     * {@see self::LINE_WORDS} words stays as its raw cues: joining without a sentence end split
     * 961's and 983's unpunctuated sentences mid-clause.
     *
     * The evidence is the cue text alone. The decoded edge words are banked after this question is
     * asked, and only where its answer moves the end, so lines built from them would differ between
     * the asking and the reading, and the banked answer would not be found again.
     *
     * Measured 11-04 over the 94 fragment-cue runs (`storage/scratch/k16-sentence-lines-20261104/`,
     * v3): see the plan, §4.1a item 6.
     *
     * @param  list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $cues
     * @return list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>
     */
    private function sentenceLines(array $cues, float $end): array
    {
        $lines = [];
        $group = [];
        $words = 0;

        foreach ($cues as $index => $cue) {
            $group[] = $cue;
            $words += str_word_count($cue['text']);
            $next = $cues[$index + 1] ?? null;
            $evidenced = preg_match(self::FINISHED, $cue['text']) === 1
                || preg_match(self::ENDS_ON_AMEN, $cue['text']) === 1
                || ($next !== null && preg_match(self::OPENS_ON_AMEN, $next['text']) === 1);
            $closes = $next === null
                || $evidenced
                || $next['start'] - $cue['end'] >= self::LINE_PAUSE_SECONDS
                || $next['music'] !== $cue['music']
                || ($cue['start'] < $end - 0.05 && $next['start'] >= $end - 0.05)
                || $words >= self::LINE_WORDS;

            if (! $closes) {
                continue;
            }

            $joined = $evidenced && count($group) > 1
                ? [[...$group[0], 'end' => $cue['end'], 'text' => implode(' ', array_column($group, 'text'))]]
                : $group;

            foreach ($joined as $line) {
                $lines[] = [...$line, 'n' => count($lines) + 1];
            }

            $group = [];
            $words = 0;
        }

        return $lines;
    }

    /**
     * The type of the section an earlier part is: the one spanning it (1271's reading, not the
     * introduction section overlapping its last second).
     */
    private function partType(MediaProcessingLog $log, float $start, float $end): ServiceSectionType
    {
        $section = $log->serviceSections()->where('start_time', '<=', $start + 0.05)->where('end_time', '>=', $end - 0.05)
            ->orderByDesc('start_time')->first();

        return $section->section_type ?? ServiceSectionType::Sermon;
    }

    /** The start of the first detected item after an earlier part, before its song, other than an unnamed stretch. */
    private function nextItemStart(MediaProcessingLog $log, float $end, float $songStart): ?float
    {
        $section = $log->serviceSections()->where('start_time', '>=', $end - 0.05)->where('start_time', '<', $songStart)
            ->whereNotIn('section_type', [ServiceSectionType::Other, ServiceSectionType::Song])->orderBy('start_time')->first();

        return $section === null ? null : (float) $section->start_time;
    }

    /** @param  array<string, mixed>  $identity */
    private function systemPrompt(array $identity): string
    {
        $type = isset($identity['part']) ? ServiceSectionType::tryFrom((string) $identity['part']) : null;

        return in_array($type, self::PART_TYPES, true) ? self::PART_SYSTEM_PROMPT : self::SYSTEM_PROMPT;
    }

    /** @return array{start: float, end: float}|null */
    private function firstMusicWindow(?AudioTimeline $timeline, float $from): ?array
    {
        foreach ($timeline->windows ?? [] as $window) {
            if ($window['end'] > $from && $window['music'] >= AudioTimeline::MUSIC_CUTOFF) {
                return ['start' => max($window['start'], $from), 'end' => $window['end']];
            }
        }

        return null;
    }

    /**
     * @param  list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $lines
     */
    private function prompt(MediaProcessingLog $log, ?ServiceSection $song, float $joinEnd, float $end, array $lines, ?ServiceSectionType $partType = null): string
    {
        $between = $log->serviceSections()->where('start_time', '>=', $end - 0.05)->where('start_time', '<', $joinEnd)->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): string => sprintf('%s "%s"', str_replace('_', ' ', $section->section_type->value), $section->title))->all();
        $asPart = in_array($partType, self::PART_TYPES, true);
        $sermon = $log->serviceSections()->where('section_type', $asPart ? $partType : ServiceSectionType::Sermon)->where('start_time', '<', $end)->orderByDesc('start_time')->first();
        $text = '';
        $marked = false;

        foreach ($lines as $line) {
            if (! $marked && $line['start'] >= $end - 0.05) {
                $text .= $asPart ? sprintf("--- (the detected %s section ends here) ---\n", str_replace('_', ' ', $partType->value)) : "--- (the detected sermon section ends here) ---\n";
                $marked = true;
            }

            $text .= sprintf("%d. %s%s\n", $line['n'], ['no' => '', 'may' => '[music may start] ', 'yes' => '[music] '][$line['music']], $line['text']);
        }

        return sprintf("%s: \"%s\".\nNext song: %s.\n%s\nTranscript:\n%s",
            $asPart ? ucfirst(str_replace('_', ' ', $partType->value)) : 'Sermon',
            $sermon->title ?? ($asPart ? ucfirst(str_replace('_', ' ', $partType->value)) : 'Sermon'),
            $song === null ? 'not in the recording (it ends after the last line)' : sprintf('"%s"', $song->title),
            $between === [] ? '' : 'Detected between them: '.implode('; ', $between).".\n",
            $text,
        );
    }

    /** @return array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null} */
    private function ask(MediaProcessingLog $log, string $prompt, string $system = self::SYSTEM_PROMPT): array
    {
        $model = (string) config('media-processing.service_structure.model');

        $result = OpenAiFlexFallback::send(
            OpenAiChatPayload::forModel([
                'model' => $model,
                'service_tier' => config('openai.service_tier'),
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'max_completion_tokens' => 4000,
            ], reasoningEffort: 'medium'),
            static fn (array $payload) => OpenAI::chat()->create($payload),
            'sermon_song_introduction',
        );
        $response = $result->response;
        OpenAiUsageLogger::log($response, 'sermon_song_introduction', $model, $log->processing_id, 'medium', $result->serviceTier);
        $answer = json_decode($response->choices[0]->message->content ?? 'null', true);

        $line = static fn (string $key): ?int => is_array($answer) && is_int($answer[$key] ?? null) ? $answer[$key] : null;

        return [
            'sermon_last_line' => $line('sermon_last_line'),
            'ties_to_sermon' => is_array($answer) && is_bool($answer['ties_to_sermon'] ?? null) ? $answer['ties_to_sermon'] : null,
            'video_last_line' => $line('video_last_line'),
            'reason' => is_array($answer) && is_string($answer['reason'] ?? null) ? $answer['reason'] : null,
        ];
    }

    /**
     * The majority judgement and its answers' line: one named at least twice, else the middle of
     * three, else the earlier of two (the longer answer ran on into housekeeping: 1160's "Maybe
     * we can have the words on the screen"), stepped back off any line after the music started.
     * Both song and recording-end joins vote on the sermon itself, keeping the preacher’s
     * closing prayer and excluding the congregation’s answering Amen and the line straight after it.
     * No end when the answers fail or name no line.
     *
     * @param  list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>  $calls
     * @param  list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $lines
     * @param  'sermon_last_line'|'video_last_line'  $lineKey
     * @return array{ties_to_sermon: bool|null, end: float|null, line: string|null}
     */
    private function decision(array $calls, array $lines, string $lineKey = 'video_last_line', ?float $songStart = null, ?float $partEnd = null): array
    {
        $votes = array_count_values(array_map(static fn (array $call): string => var_export($call['ties_to_sermon'], true), $calls));
        arsort($votes);
        $winner = array_key_first($votes);

        if ($winner === 'NULL' || count($calls) >= $votes[$winner] * 2) {
            return ['ties_to_sermon' => null, 'end' => null, 'line' => null];
        }

        $ties = $winner === 'true';
        $byNumber = array_column($lines, null, 'n');
        $chosen = $this->votedLine($calls, $ties, $lineKey);

        if ($lineKey === 'sermon_last_line' && ! $ties && $chosen !== null && isset($byNumber[$chosen])) {
            $videoLast = $this->votedLine($calls, $ties, 'video_last_line');
            foreach ($lines as $line) {
                if ($line['n'] > $chosen && $line['n'] <= $videoLast && $line['music'] !== 'yes'
                    && preg_match(self::ENDS_ON_AMEN, $line['text']) === 1 && ! $this->answersAmen($byNumber, $line['n'])) {
                    $chosen = $line['n'];
                }
            }
        }

        while ($chosen !== null && ($byNumber[$chosen]['music'] ?? 'yes') === 'yes') {
            $chosen = $chosen > 1 ? $chosen - 1 : null;
        }

        while ($chosen !== null && $this->answersAmen($byNumber, $chosen)) {
            $chosen--;
        }

        // The video is one stretch: a line straight after the answering Amen is outside it too (1101's "Thank you.").
        if ($chosen !== null && $this->answersAmen($byNumber, $chosen - 1)) {
            $chosen--;
            while ($this->answersAmen($byNumber, $chosen)) {
                $chosen--;
            }
        }

        while ($chosen !== null && isset($byNumber[$chosen]) && preg_match(self::OPEN_CLAUSE, $byNumber[$chosen]['text']) === 1
            && ($songStart === null || $byNumber[$chosen]['start'] < $songStart)) {
            $chosen = isset($byNumber[$chosen - 1]) ? $chosen - 1 : null;
        }

        if ($partEnd !== null) {
            $chosen = $this->partLine($chosen, $byNumber, $partEnd, $songStart);
        }

        return $chosen === null
            ? ['ties_to_sermon' => $ties, 'end' => null, 'line' => null]
            : ['ties_to_sermon' => $ties, 'end' => (float) $byNumber[$chosen]['end'], 'line' => $byNumber[$chosen]['text']];
    }

    /**
     * An earlier part's line, measured 2026-11-01 over 150 parts (`storage/scratch/part-joins-20261101/`).
     * A line with no sentence end in it, whose sentence the next line finishes ("…his word to us" |
     * "this day.", 1245), steps back, as an open clause does. A line that already finishes a sentence
     * stays: 1140's "how can you not glorify God? Let's do that, shall we? Let's" would lose the
     * sermon's last point. Measured on the output's own end 11-01, the step-back moved only 968 and
     * 1140, both for the worse, so it stays on earlier parts. An end later than the detected one must finish a sentence or an Amen
     * ("We thank God for his word." is kept: 1111, 1288); otherwise the detected end's line stands
     * (1307's word-per-line "thank" before "you Dave").
     *
     * @param  array<int, array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $byNumber
     */
    private function partLine(?int $chosen, array $byNumber, float $partEnd, ?float $songStart): ?int
    {
        while ($chosen !== null && isset($byNumber[$chosen], $byNumber[$chosen + 1])
            && preg_match('/[.!?]/u', $byNumber[$chosen]['text']) !== 1
            && preg_match('/^\s*["\'\x{201C}\x{2018}]?\p{Ll}/u', $byNumber[$chosen + 1]['text']) === 1
            && preg_match(self::FINISHED, $byNumber[$chosen + 1]['text']) === 1
            && ($songStart === null || $byNumber[$chosen + 1]['start'] < $songStart)) {
            $chosen = isset($byNumber[$chosen - 1]) ? $chosen - 1 : null;
        }

        if ($chosen !== null && $byNumber[$chosen]['end'] > $partEnd + 0.3
            && preg_match(self::FINISHED, $byNumber[$chosen]['text']) !== 1 && preg_match(self::ENDS_ON_AMEN, $byNumber[$chosen]['text']) !== 1) {
            $chosen = null;
            foreach ($byNumber as $n => $line) {
                if ($line['end'] <= $partEnd + 0.05) {
                    $chosen = $n;
                }
            }
        }

        return $chosen;
    }

    /**
     * A standalone "Amen" straight after a line ending on one: the congregation answering the
     * preacher's Amen, which is not part of the sermon (operator 2026-10-28).
     *
     * @param  array<int, array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $byNumber
     */
    private function answersAmen(array $byNumber, int $n): bool
    {
        return isset($byNumber[$n], $byNumber[$n - 1])
            && TranscriptCueEvidence::tokens($byNumber[$n]['text']) === ['amen']
            && preg_match(self::ENDS_ON_AMEN, $byNumber[$n - 1]['text']) === 1;
    }

    /**
     * @param  list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>  $calls
     * @param  'sermon_last_line'|'video_last_line'  $lineKey
     */
    private function votedLine(array $calls, bool $ties, string $lineKey): ?int
    {
        $numbers = array_filter(array_map(static fn (array $call): ?int => $call['ties_to_sermon'] === $ties ? $call[$lineKey] : null, $calls), is_int(...));
        $named = array_count_values($numbers);
        arsort($named);
        sort($numbers);

        return match (true) {
            $numbers === [] => null,
            reset($named) >= 2 => array_key_first($named),
            count($numbers) === 3 => $numbers[1],
            default => $numbers[0],
        };
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{end: float|null, ties_to_sermon: bool|null, line: string|null}|null
     */
    private function answer(MediaProcessingLog $log, array $identity): ?array
    {
        $payload = $this->bankedPayload($log, $identity);

        if ($payload === null) {
            return null;
        }

        if (is_array($payload['calls'] ?? null) && $payload['calls'] !== [] && is_array($payload['lines'] ?? null)) {
            $lines = [];
            foreach ($payload['lines'] as $line) {
                if (is_int($line['n'] ?? null) && is_numeric($line['start'] ?? null) && is_numeric($line['end'] ?? null)
                    && is_string($line['text'] ?? null) && in_array($line['music'] ?? null, ['no', 'may', 'yes'], true)) {
                    $lines[] = ['n' => $line['n'], 'start' => (float) $line['start'], 'end' => (float) $line['end'], 'text' => $line['text'], 'music' => $line['music']];
                }
            }

            $decision = $this->decision($this->bankedCalls($payload), $lines, 'sermon_last_line', $this->identitySongStart($identity), $this->identityPartEnd($identity));

            return ['end' => $decision['end'], 'ties_to_sermon' => $decision['ties_to_sermon'], 'line' => $decision['line']];
        }

        return [
            'end' => is_numeric($payload['end']) ? (float) $payload['end'] : null,
            'ties_to_sermon' => is_bool($payload['ties_to_sermon'] ?? null) ? $payload['ties_to_sermon'] : null,
            'line' => is_string($payload['line'] ?? null) ? $payload['line'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    private function bankedPayload(MediaProcessingLog $log, array $identity): ?array
    {
        $kind = $this->kind($identity);

        foreach (ServiceArtifactStorage::recordedFor($log) as $entry) {
            if ($entry['kind'] !== $kind) {
                continue;
            }

            try {
                $payload = json_decode((string) Storage::disk($entry['disk'])->get($entry['path']), true);
            } catch (\Throwable) {
                continue;
            }

            if (is_array($payload) && ($payload['identity'] ?? null) == $identity && array_key_exists('end', $payload)) {
                // Older null answers may have swallowed a transport failure; ask again once.
                if ($payload['end'] === null && ($payload['calls_completed'] ?? false) !== true) {
                    continue;
                }

                return $payload;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>
     */
    private function bankedCalls(array $payload): array
    {
        $calls = [];
        foreach (is_array($payload['calls'] ?? null) ? $payload['calls'] : [] as $call) {
            $calls[] = [
                'sermon_last_line' => is_int($call['sermon_last_line'] ?? null) ? $call['sermon_last_line'] : null,
                'video_last_line' => is_int($call['video_last_line'] ?? null) ? $call['video_last_line'] : null,
                'ties_to_sermon' => is_bool($call['ties_to_sermon'] ?? null) ? $call['ties_to_sermon'] : null,
                'reason' => is_string($call['reason'] ?? null) ? $call['reason'] : null,
            ];
        }

        return $calls;
    }

    /** @param array<string, mixed> $identity */
    private function identitySongStart(array $identity): ?float
    {
        return is_numeric($identity['song_start'] ?? null) ? (float) $identity['song_start'] : null;
    }

    /**
     * An earlier part's detected end; null for the output's end.
     *
     * @param  array<string, mixed>  $identity
     */
    private function identityPartEnd(array $identity): ?float
    {
        return isset($identity['part']) && is_numeric($identity['sermon_end'] ?? null) ? (float) $identity['sermon_end'] : null;
    }

    /** @param array<string, mixed> $identity */
    private function kind(array $identity): string
    {
        return self::ARTIFACT_PREFIX.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
