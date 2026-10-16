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
use App\Support\OpenAiUsageLogger;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Where a sermon followed by a song ends (register K16, operator 2026-10-16): at the join to the
 * song, never after the music starts. The preacher's introduction to the song is part of the
 * sermon when it ties the song to the sermon, so that it works as the sermon's conclusion; a bare
 * announcement, an unrelated song's introduction and notices are not, and neither is a "let's
 * stand and sing" call on its own. "It's not really about the length, it's about the relevance of
 * the song to the sermon." Housekeeping between the sermon and a tied introduction ends the sermon
 * at its own last sentence, since a video cannot skip it (951's 35 s about a song missing from the
 * internet).
 *
 * That is a judgement of meaning, so it is asked of the structure model, as the sentence check is
 * ({@see SpokenEdgeSentenceCheck}). The read-only measurement of 2026-10-16 over 280 sermons
 * (`storage/scratch/weekly-edge-tool-20261016/`) found answers that agree on the judgement but
 * name different last lines (1160), and a garbled introduction one answer missed (1005). So it is
 * asked twice, and a third time when the two differ in judgement or line; the majority judgement
 * stands, with a line its answers name at least twice (operator, 2026-10-16).
 *
 * The preacher must say how the song relates to the sermon: reading its words aloud is not a tie
 * on its own (operator, 2026-10-16, on 1040, 1311 and 932).
 *
 * The classifier scores 5 s windows, so the first music window says only that the music starts
 * somewhere in it: 1224's "…destruction, | and despair." was spoken at the start of a mixed window.
 * Lines starting in that window may complete a sentence; lines after it are never in the sermon.
 *
 * Answers are banked on the edge step ({@see self::prepare()}) before the edge words are decoded,
 * so the words are decoded where the sermon now ends; planning only reads them ({@see self::read()}).
 */
class SermonSongIntroductionCheck
{
    /** Bumped whenever the prompt changes, so answers to an older question are not reused. */
    public const PROMPT_VERSION = 2;

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

    private const SYSTEM_PROMPT = <<<'TXT'
You read the end of a sermon in a church-service transcript and what is said between it and the next song. Rules from the person who publishes the sermon videos:
- The sermon video ends at the join to the song, and never after the music has started. Lines marked [music] are after the music started and are never in the video. Lines marked [music may start] may be in the video only to finish a sentence begun before them.
- The preacher's introduction to the song belongs in the sermon video when the preacher says how the song relates to the sermon (why it was chosen for it, what it says about the sermon's subject, that it was quoted in the sermon), so that it works as the sermon's conclusion. A verse read aloud is part of such an introduction.
- Reading the song's words aloud without saying how they relate to the sermon does not tie it, even when the words fit the sermon's theme.
- A bare announcement (the song's name or number, "let's stand and sing"), the introduction to a song that is not connected to the sermon, notices and housekeeping do not belong.
- A "let's stand and sing" call on its own is never included, even at the end of an introduction that is.
- It is not about length: a long introduction that ties the song to the sermon belongs; a short one that does not, does not.
- The video is one continuous stretch. If notices or housekeeping (anything more than announcing the song) come between the sermon and the introduction, the video ends at the sermon's own last line.
Transcript punctuation and line breaks are automatic, and some words are misheard.
Answer in JSON: {"sermon_last_line": <number of the last line of the sermon itself, before any introduction or announcement of the song>, "ties_to_sermon": <true when the video takes in an introduction that ties the song to the sermon, otherwise false>, "video_last_line": <number of the last line in the sermon video>, "reason": "<one sentence>"}
TXT;

    /**
     * Ask about the sermon output's end, unless an answer to the same question is banked.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans  The sermon output's parts, as detected
     * @return array{asked: bool, end: float|null, ties_to_sermon: bool|null}
     */
    public function prepare(MediaProcessingLog $log, array $sermonSpans): array
    {
        $question = $this->question($log, $sermonSpans);

        if ($question === null) {
            return ['asked' => false, 'end' => null, 'ties_to_sermon' => null];
        }

        if ($question['lines'] === []) {
            return ['asked' => false, 'end' => $question['end'], 'ties_to_sermon' => null];
        }

        $banked = $this->answer($log, $question['identity']);

        if ($banked !== null) {
            return ['asked' => false, 'end' => $banked['end'], 'ties_to_sermon' => $banked['ties_to_sermon']];
        }

        $calls = [$this->ask($log, $question['prompt']), $this->ask($log, $question['prompt'])];

        if ($calls[0]['ties_to_sermon'] !== $calls[1]['ties_to_sermon'] || $calls[0]['video_last_line'] !== $calls[1]['video_last_line']) {
            $calls[] = $this->ask($log, $question['prompt']);
        }

        $decision = $this->decision($calls, $question['lines']);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $this->kind($question['identity']), [
            'identity' => $question['identity'], 'prompt' => $question['prompt'], 'lines' => $question['lines'], 'calls' => $calls, ...$decision,
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
        $song = $this->song($log, $sermonSpans);

        return $song !== null ? (float) $song->start_time : null;
    }

    /**
     * The banked answer for the sermon output's end, or null when there is no song beside it or
     * the question was never asked of this transcript. With nothing credibly spoken before the
     * song there is nothing to judge, and the detected end stands.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @return array{end: float|null, ties_to_sermon: bool|null, line: string|null}|null
     */
    public function read(MediaProcessingLog $log, array $sermonSpans): ?array
    {
        $question = $this->question($log, $sermonSpans);

        return match (true) {
            $question === null => null,
            $question['lines'] === [] => ['end' => $question['end'], 'ties_to_sermon' => null, 'line' => null],
            default => $this->answer($log, $question['identity']),
        };
    }

    /**
     * The next song after the sermon output, reached across items that are no clip of their own
     * (an introduction, notices, a prayer), within {@see self::SONG_REACH}.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     */
    private function song(MediaProcessingLog $log, array $sermonSpans): ?ServiceSection
    {
        if ($sermonSpans === []) {
            return null;
        }

        $end = (float) $sermonSpans[array_key_last($sermonSpans)]['end_time'];
        $handlers = app(SectionPublicationHandlerFactory::class);

        foreach ($log->serviceSections()->orderBy('start_time')->orderBy('id')->get() as $section) {
            if ((float) $section->start_time < $end - 0.05) {
                continue;
            }

            if ((float) $section->start_time > $end + self::SONG_REACH) {
                return null;
            }

            if ($section->section_type === ServiceSectionType::Song) {
                return $section;
            }

            if ($handlers->forSection($section) !== null || $section->section_type === ServiceSectionType::Sermon) {
                return null;
            }
        }

        return null;
    }

    /**
     * The question for the sermon output's end: the numbered lines the model reads, the prompt and
     * the identity an answer is banked under. Null when no song follows; no lines when nothing
     * credible is said near the join.
     *
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @return array{end: float, lines: list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>, prompt: string, identity: array<string, mixed>}|null
     */
    private function question(MediaProcessingLog $log, array $sermonSpans): ?array
    {
        $last = array_key_last($sermonSpans);
        $song = $this->song($log, $sermonSpans);

        if ($last === null || $song === null) {
            return null;
        }

        $end = (float) $sermonSpans[$last]['end_time'];
        $songStart = (float) $song->start_time;
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

            if ($cue['start'] > $songStart + self::PAST_SONG_START || ($state === 'yes' && ++$afterMusic > self::LINES_AFTER_MUSIC)) {
                break;
            }

            $lines[] = ['n' => count($lines) + 1, 'start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => trim($cue['text']), 'music' => $state];
        }

        if ($lines === []) {
            return ['end' => $end, 'lines' => [], 'prompt' => '', 'identity' => []];
        }

        $prompt = $this->prompt($log, $sermonSpans, $song, $end, $lines);

        return ['end' => $end, 'lines' => $lines, 'prompt' => $prompt, 'identity' => [
            'processing_id' => $log->processing_id,
            'sermon_end' => round($end, 3),
            'song_start' => round($songStart, 3),
            'model' => (string) config('media-processing.service_structure.model'),
            'prompt_version' => self::PROMPT_VERSION,
            'transcript' => hash('sha256', $prompt),
            'media_processing' => MediaProcessingVersion::signature(),
        ]];
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
     * @param  list<array{start_time: float, end_time: float}>  $sermonSpans
     * @param  list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $lines
     */
    private function prompt(MediaProcessingLog $log, array $sermonSpans, ServiceSection $song, float $end, array $lines): string
    {
        $between = $log->serviceSections()->where('start_time', '>=', $end - 0.05)->where('start_time', '<', (float) $song->start_time)->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): string => sprintf('%s "%s"', str_replace('_', ' ', $section->section_type->value), $section->title))->all();
        $sermon = $log->serviceSections()->where('section_type', ServiceSectionType::Sermon)->where('start_time', '<', $end)->orderByDesc('start_time')->first();
        $text = '';
        $marked = false;

        foreach ($lines as $line) {
            if (! $marked && $line['start'] >= $end - 0.05) {
                $text .= "--- (the detected sermon section ends here) ---\n";
                $marked = true;
            }

            $text .= sprintf("%d. %s%s\n", $line['n'], ['no' => '', 'may' => '[music may start] ', 'yes' => '[music] '][$line['music']], $line['text']);
        }

        return sprintf("Sermon: \"%s\".\nNext song: \"%s\".\n%s\nTranscript:\n%s",
            $sermon->title ?? 'Sermon',
            $song->title,
            $between === [] ? '' : 'Detected between them: '.implode('; ', $between).".\n",
            $text,
        );
    }

    /** @return array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null} */
    private function ask(MediaProcessingLog $log, string $prompt): array
    {
        $model = (string) config('media-processing.service_structure.model');

        try {
            $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => self::SYSTEM_PROMPT], ['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'max_completion_tokens' => 4000,
            ], reasoningEffort: 'medium'));
            OpenAiUsageLogger::log($response, 'sermon_song_introduction', $model, $log->processing_id, 'medium');
            $answer = json_decode($response->choices[0]->message->content ?? 'null', true);
        } catch (\Throwable) {
            $answer = null;
        }

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
     * No end when the answers fail or name no line.
     *
     * @param  list<array{sermon_last_line: int|null, ties_to_sermon: bool|null, video_last_line: int|null, reason: string|null}>  $calls
     * @param  list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>  $lines
     * @return array{ties_to_sermon: bool|null, end: float|null, line: string|null}
     */
    private function decision(array $calls, array $lines): array
    {
        $votes = array_count_values(array_map(static fn (array $call): string => var_export($call['ties_to_sermon'], true), $calls));
        arsort($votes);
        $winner = array_key_first($votes);

        if ($winner === 'NULL' || $votes[$winner] * 2 <= count($calls)) {
            return ['ties_to_sermon' => null, 'end' => null, 'line' => null];
        }

        $ties = $winner === 'true';
        $numbers = array_filter(array_map(static fn (array $call): ?int => $call['ties_to_sermon'] === $ties ? $call['video_last_line'] : null, $calls), is_int(...));
        $byNumber = array_column($lines, null, 'n');
        $named = array_count_values($numbers);
        arsort($named);
        sort($numbers);
        $chosen = match (true) {
            $numbers === [] => null,
            reset($named) >= 2 => array_key_first($named),
            count($numbers) === 3 => $numbers[1],
            default => $numbers[0],
        };

        while ($chosen !== null && ($byNumber[$chosen]['music'] ?? 'yes') === 'yes') {
            $chosen = $chosen > 1 ? $chosen - 1 : null;
        }

        return $chosen === null
            ? ['ties_to_sermon' => $ties, 'end' => null, 'line' => null]
            : ['ties_to_sermon' => $ties, 'end' => $byNumber[$chosen]['end'], 'line' => $byNumber[$chosen]['text']];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{end: float|null, ties_to_sermon: bool|null, line: string|null}|null
     */
    private function answer(MediaProcessingLog $log, array $identity): ?array
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
                return [
                    'end' => is_numeric($payload['end']) ? (float) $payload['end'] : null,
                    'ties_to_sermon' => is_bool($payload['ties_to_sermon'] ?? null) ? $payload['ties_to_sermon'] : null,
                    'line' => is_string($payload['line'] ?? null) ? $payload['line'] : null,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $identity */
    private function kind(array $identity): string
    {
        return self::ARTIFACT_PREFIX.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
