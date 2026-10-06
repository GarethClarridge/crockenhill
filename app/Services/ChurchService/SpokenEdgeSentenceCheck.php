<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\MediaProcessingVersion;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiUsageLogger;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Whether a cut between two spoken items splits a sentence or a thought, asked of the structure
 * model and answered before the cut is made.
 *
 * Canary 11's listening (operator, 2026-10-06): a cut must keep each sentence wholly in or wholly
 * out of the output. Whisper's punctuation cannot say where a thought ends ("…let's have our
 * morning reading. | It's quite a short one." is one handover), and "you can tell that the
 * sentences are joined by meaning, so presumably an LLM can". A read-only evaluation on the
 * canary's judged edges agreed on edges between two spoken items. At song edges the words are
 * misheard lyrics ("Let's ‖ pray." is a sung "Bless the Lord"), and the answers moved with the
 * prompt, so song edges are left to the song rule and each edge is asked twice: only a move both
 * answers name is made.
 *
 * Answers are banked per cut on the edge step ({@see self::prepare()}) and only read when a plan
 * is made, as edge word timings are: planning runs many times and must not call a model. An edge
 * with no banked answer keeps its word-pause cut.
 */
class SpokenEdgeSentenceCheck
{
    /** Bumped whenever the prompt changes, so answers to an older question are not reused. */
    public const PROMPT_VERSION = 1;

    private const ARTIFACT_PREFIX = 'edge-sentence-';

    /** A section boundary further than this from the edge is not the edge's neighbour. */
    private const NEIGHBOUR_TOLERANCE = 2.0;

    /** Seconds of transcript the model reads on each side of the cut. */
    private const CONTEXT_SECONDS = 20.0;

    private const SYSTEM_PROMPT = <<<'TXT'
You check where a church-service video is cut. You get the transcript around one cut, marked ‖, and which side of the cut is in the video. Rules, from the person who reviews these videos:
- A cut must not split a sentence or a single thought. Each sentence goes wholly into the video or wholly out of it, on the side it belongs to by meaning. Transcript punctuation is automatic and can split one thought into two "sentences" ("Let's have our reading. It's quite a short one." is one handover).
- A cut between two complete thoughts is right, even if the neighbouring words are related.
Answer in JSON: {"decision": "keep" | "extend" | "shrink", "words_to_move": "<the exact words, copied from the transcript, that would cross the cut, or empty>", "reason": "<one sentence>"}. "extend" moves the cut outward so the video takes in the rest of a split thought; "shrink" moves it inward so the video drops the partial thought. Choose "keep" unless the cut splits a thought.
TXT;

    /**
     * Ask about every boundary between two spoken items whose cut has no banked answer.
     *
     * @return array{edges: int, asked: int, agreed_moves: int}
     */
    public function prepare(MediaProcessingLog $log): array
    {
        $plans = app(CueSafeExtractionPlan::class);
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        $summary = ['edges' => 0, 'asked' => 0, 'agreed_moves' => 0];
        $edges = [];

        foreach ($log->serviceSections()->orderBy('start_time')->get() as $section) {
            $edges[sprintf('start@%.3f', (float) $section->start_time)] = ['start', (float) $section->start_time];
            $edges[sprintf('end@%.3f', (float) $section->end_time)] = ['end', (float) $section->end_time];
        }

        foreach ($edges as [$edge, $original]) {
            if ($this->spokenSides($log, $original) === null) {
                continue;
            }

            $placed = $plans->wordPauseEdge($log, $cues, $original, $edge);

            if ($placed === null) {
                continue;
            }

            $summary['edges']++;
            $identity = $this->identity($log, $placed['window'], $original, $edge, $placed['time']);

            if ($this->read($log, $identity) !== null) {
                continue;
            }

            $prompt = $this->prompt($log, $cues, $placed['words'], $placed['window'], $placed['time'], $edge);
            $calls = [$this->ask($log, $prompt), $this->ask($log, $prompt)];
            $record = ['identity' => $identity, 'prompt' => $prompt, 'calls' => $calls, ...$this->agreement($calls)];
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $this->kind($identity), $record);
            $log->refresh();
            $summary['asked']++;
            $summary['agreed_moves'] += $record['agreed'] && $record['decision'] !== 'keep' ? 1 : 0;
        }

        return $summary;
    }

    /**
     * The sections either side of a boundary, when both are spoken; null at a song or the
     * recording's ends.
     *
     * @return array{before: ServiceSection, after: ServiceSection}|null
     */
    public function spokenSides(MediaProcessingLog $log, float $time): ?array
    {
        $before = null;
        $after = null;

        foreach ($log->serviceSections()->orderBy('start_time')->get() as $section) {
            $start = (float) $section->start_time;
            $end = (float) $section->end_time;

            if ($end <= $time + self::NEIGHBOUR_TOLERANCE && $end >= $time - self::NEIGHBOUR_TOLERANCE && $start < $time) {
                $before = $section;
            }

            if ($start >= $time - self::NEIGHBOUR_TOLERANCE && $start <= $time + self::NEIGHBOUR_TOLERANCE && $end > $time && $after === null) {
                $after = $section;
            }
        }

        if ($before === null || $after === null || $before->is($after)
            || $before->section_type === ServiceSectionType::Song || $after->section_type === ServiceSectionType::Song) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @param  array{start: float, end: float}  $window
     * @return array<string, mixed>
     */
    public function identity(MediaProcessingLog $log, array $window, float $original, string $edge, float $cut): array
    {
        return [
            'processing_id' => $log->processing_id,
            'window' => [round($window['start'], 3), round($window['end'], 3)],
            'original' => round($original, 3),
            'edge' => $edge,
            'cut' => round($cut, 3),
            'model' => (string) config('media-processing.service_structure.model'),
            'prompt_version' => self::PROMPT_VERSION,
            'media_processing' => MediaProcessingVersion::signature(),
        ];
    }

    /**
     * The banked answer for this cut, or null when it was never asked.
     *
     * @param  array<string, mixed>  $identity
     * @return array{agreed: bool, decision: string|null, words_to_move: string|null}|null
     */
    public function read(MediaProcessingLog $log, array $identity): ?array
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

            if (is_array($payload) && ($payload['identity'] ?? null) == $identity && is_bool($payload['agreed'] ?? null)) {
                return ['agreed' => $payload['agreed'], 'decision' => $payload['decision'] ?? null, 'words_to_move' => $payload['words_to_move'] ?? null];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $identity */
    private function kind(array $identity): string
    {
        return self::ARTIFACT_PREFIX.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The transcript around the cut as the model reads it: whole lines, with the decoded words
     * splitting the line the cut falls in.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float}  $window
     */
    private function prompt(MediaProcessingLog $log, array $cues, array $words, array $window, float $cut, string $edge): string
    {
        $before = [];
        $after = [];

        foreach (CueSafeExtractionPlan::tokens($cues, $words, $window, $cut - self::CONTEXT_SECONDS, $cut + self::CONTEXT_SECONDS) as $token) {
            if (($token['start'] + $token['end']) / 2 < $cut) {
                $before[] = $token['word'];
            } else {
                $after[] = $token['word'];
            }
        }

        $sides = $this->spokenSides($log, $cut) ?? $this->spokenSides($log, $edge === 'start' ? $cut + 0.1 : $cut - 0.1);
        $video = $edge === 'start' ? ($sides['after'] ?? null) : ($sides['before'] ?? null);
        $neighbour = $edge === 'start' ? ($sides['before'] ?? null) : ($sides['after'] ?? null);

        return sprintf(
            "The video is the %s. Next to it is the %s.\nThe cut is where the video %s; the video is the text %s ‖.\n\nTranscript:\n%s ‖ %s",
            $video !== null ? str_replace('_', ' ', $video->section_type->value) : 'item',
            $neighbour !== null ? str_replace('_', ' ', $neighbour->section_type->value) : 'item',
            $edge === 'start' ? 'starts' : 'ends',
            $edge === 'start' ? 'after' : 'before',
            implode(' ', $before),
            implode(' ', $after),
        );
    }

    /** @return array{decision: string|null, words_to_move: string|null, reason: string|null} */
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
            OpenAiUsageLogger::log($response, 'edge_sentence_check', $model, $log->processing_id, 'medium');
            $answer = json_decode($response->choices[0]->message->content ?? 'null', true);
        } catch (\Throwable) {
            $answer = null;
        }

        $decision = is_array($answer) && in_array($answer['decision'] ?? null, ['keep', 'extend', 'shrink'], true) ? $answer['decision'] : null;

        return [
            'decision' => $decision,
            'words_to_move' => is_array($answer) && is_string($answer['words_to_move'] ?? null) ? $answer['words_to_move'] : null,
            'reason' => is_array($answer) && is_string($answer['reason'] ?? null) ? $answer['reason'] : null,
        ];
    }

    /**
     * @param  list<array{decision: string|null, words_to_move: string|null, reason: string|null}>  $calls
     * @return array{agreed: bool, decision: string|null, words_to_move: string|null}
     */
    private function agreement(array $calls): array
    {
        $normalize = static fn (?string $text): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $text)));
        [$first, $second] = $calls;
        $agreed = $first['decision'] !== null && $first['decision'] === $second['decision']
            && ($first['decision'] === 'keep' || $normalize($first['words_to_move']) === $normalize($second['words_to_move']));

        return ['agreed' => $agreed, 'decision' => $agreed ? $first['decision'] : null, 'words_to_move' => $agreed ? $first['words_to_move'] : null];
    }
}
