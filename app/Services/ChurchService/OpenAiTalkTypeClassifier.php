<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Contracts\TalkTypeClassifier;
use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Enums\TalkType;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiFlexFallback;
use App\Support\OpenAiUsageLogger;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use TypeError;

/**
 * Model assessment for short talks the ordered rules ({@see TalkTypeRules}) leave undecided.
 *
 * It only proposes: one of the five talk types or a distinct null disposition, an optional series,
 * a confidence and reasons. It cannot return sections, bounds, bindings or approvals, and the
 * strict schema plus {@see self::validated()} reject anything else. The model answers in its own
 * words only inside `reasons`; an answer outside the allowed set, a missing field, a non-finite
 * confidence or a truncated response is a `failed` proposal, never a guessed type.
 *
 * Provider failures are not swallowed: a genuine 429 (and any other transport error) propagates so
 * the caller's queue backoff owns it. Only a `flex_unavailable` refusal changes tier, through
 * {@see OpenAiFlexFallback}.
 */
class OpenAiTalkTypeClassifier implements TalkTypeClassifier
{
    public const string PROMPT_VERSION = 'talk-type-prompt-1';

    public const string SCHEMA_VERSION = 'talk-type-schema-1';

    public const string OPERATION = 'talk_type_proposal';

    /** @var list<string> */
    private const array NULL_DISPOSITIONS = ['not_a_talk', 'none_of_these', 'uncertain', 'insufficient_evidence'];

    private const int MAX_REASONS = 8;

    private const int MAX_REASON_LENGTH = 600;

    public function classify(TalkTypeInput $input, ?TalkTypeProposal $ruleProposal = null): TalkTypeProposal
    {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for talk-type proposals.');
        }

        $model = (string) config('media-processing.talk_type.model', 'gpt-5.6-luna');
        $effort = (string) config('media-processing.talk_type.reasoning_effort', 'medium');

        try {
            $tiered = OpenAiFlexFallback::send(
                OpenAiChatPayload::forModel([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->userPrompt($input, $ruleProposal)],
                    ],
                    'response_format' => $this->responseFormat(),
                    'service_tier' => config('openai.service_tier'),
                    'temperature' => 0.1,
                    'max_completion_tokens' => (int) config('media-processing.talk_type.max_completion_tokens', 8000),
                ], reasoningEffort: $effort),
                static fn (array $payload): CreateResponse => OpenAI::chat()->create($payload),
                self::OPERATION,
            );
        } catch (TypeError $exception) {
            return $this->failed('The provider response could not be read.', $this->baseProvenance($model, $effort), [
                'error' => $exception::class.': '.$exception->getMessage(),
            ]);
        }

        $response = $tiered->response;
        OpenAiUsageLogger::log($response, self::OPERATION, $model, null, $effort, $tiered->serviceTier);

        $choice = $response->choices[0] ?? null;
        $provenance = $this->baseProvenance($model, $effort) + [
            'response_model' => $response->model,
            'requested_service_tier' => config('openai.service_tier'),
            'service_tier' => $tiered->serviceTier,
            'fell_back_from_flex' => $tiered->fellBackFromFlex,
            'finish_reason' => $choice?->finishReason,
        ];

        if ($choice === null) {
            return $this->failed('The provider returned no choice.', $provenance);
        }
        if (in_array($choice->finishReason, ['length', 'content_filter'], true)) {
            return $this->failed("The response stopped early ({$choice->finishReason}).", $provenance);
        }

        $content = $choice->message->content;
        if (! is_string($content) || trim($content) === '') {
            return $this->failed('The provider returned an empty or refused response.', $provenance);
        }

        $answer = $this->validated($content);
        if (is_string($answer)) {
            return $this->failed($answer, $provenance);
        }

        return $this->proposal($answer, $input, $ruleProposal, $provenance);
    }

    public function systemPrompt(): string
    {
        return <<<'TEXT'
You assess ONE short talk from a Baptist church service and PROPOSE what kind of talk it is. You only
propose: you never move boundaries, change what the order of service says, or approve anything. A person
confirms every type. Use only the supplied evidence; do not invent facts. Use British English.

Answer with exactly one value for "answer":
- sermon: the main expository address of the service.
- childrens_talk: the family talk in the MORNING service's family slot, whoever it is aimed at; or a talk
  that speaks directly to children in this section with substantive teaching, as a separate item from the
  main address. Catechism, hero-of-faith and Bible-character teaching items are family talks and name a
  series. Evening services have no children's talks: never answer childrens_talk for an evening service.
- partner_update: a report on a partner or mission organisation's work, or a ministry the church supports.
- testimony: a personal account of someone's own faith. A conversion anecdote inside a biography, an
  interview, or a ministry report is not a testimony.
- church_update: a report on a conference or event members went to, or a brief encouragement or other
  general short talk for the church. It is a named, narrow type and never a catch-all: do NOT use it
  because the other labels do not fit. Use none_of_these for that.
- not_a_talk: an introduction, dismissal, notice or comment with no talk in its own right.
- none_of_these: a real talk that fits none of the types above.
- uncertain: the evidence points to more than one type and you cannot choose.
- insufficient_evidence: the text is missing, partial or too short to judge. Missing or unobservable
  speech is not evidence that there is no talk.

Rules:
- A title word, a bare mention of God or the Bible, or a copied seasonal phrase does not by itself decide a type.
- Recommendations, past references and introductions about a series do not make this talk part of it.
  Give "series" only when this talk is positively part of a named recurring series, else null.
- If testimony-like and ministry-report evidence are mixed, prefer uncertain over guessing.
- "confidence" is a number from 0 to 1 for your answer (null only when the answer is not a type); it is
  uncalibrated, so be honest rather than decisive.
- "reasons" lists one to six short quotations or observations from the evidence that justify the answer.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'talk_type_proposal',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['answer', 'series', 'confidence', 'reasons'],
                    'properties' => [
                        'answer' => ['type' => 'string', 'enum' => [...TalkType::values(), ...self::NULL_DISPOSITIONS]],
                        'series' => ['type' => ['string', 'null']],
                        'confidence' => ['type' => ['number', 'null']],
                        'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    private function userPrompt(TalkTypeInput $input, ?TalkTypeProposal $ruleProposal): string
    {
        $evidence = $input->evidence;
        $transcript = is_array($evidence['transcript'] ?? null) ? $evidence['transcript'] : [];
        unset($transcript['cues']);
        $evidence['transcript'] = $transcript;
        unset($evidence['held_state']);

        $payload = ['evidence' => $evidence];
        if ($ruleProposal !== null) {
            $payload['rule_stage'] = [
                'rule_id' => $ruleProposal->ruleId,
                'conflicts' => $ruleProposal->signals['conflicts'] ?? [],
                'role_features' => $ruleProposal->signals['role_features'] ?? null,
                'testimony_risk' => $ruleProposal->signals['testimony_risk'] ?? false,
                'proposed_series' => $ruleProposal->proposedSeries,
            ];
        }

        return "Assess this section.\n".json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @return array{answer: string, series: ?string, confidence: ?float, reasons: list<string>}|string a failure reason
     */
    private function validated(string $content): array|string
    {
        $decoded = json_decode($content, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return 'The response was not a JSON object.';
        }
        $keys = ['answer', 'series', 'confidence', 'reasons'];
        if (array_diff(array_keys($decoded), $keys) !== [] || array_diff($keys, array_keys($decoded)) !== []) {
            return 'The response did not have exactly the required fields.';
        }

        $answer = $decoded['answer'];
        if (! is_string($answer) || ! in_array($answer, [...TalkType::values(), ...self::NULL_DISPOSITIONS], true)) {
            return 'The answer was not an allowed type or disposition.';
        }

        $series = $decoded['series'];
        if ($series !== null && ! is_string($series)) {
            return 'The series was not a string.';
        }
        $series = $series === null ? null : trim($series);
        if ($series !== null && mb_strlen($series) > 255) {
            return 'The series was longer than 255 characters.';
        }

        $confidence = $decoded['confidence'];
        if ($confidence !== null && ((! is_int($confidence) && ! is_float($confidence)) || ! is_finite((float) $confidence) || $confidence < 0 || $confidence > 1)) {
            return 'The confidence was not a number between 0 and 1.';
        }
        $isType = TalkType::tryFrom($answer) !== null;
        if ($isType && $confidence === null) {
            return 'A type answer carried no confidence.';
        }

        $reasons = $decoded['reasons'];
        if (! is_array($reasons) || ! array_is_list($reasons) || count($reasons) > self::MAX_REASONS) {
            return 'The reasons were not a short list.';
        }
        foreach ($reasons as $reason) {
            if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
                return 'A reason was not a bounded non-empty string.';
            }
        }
        if ($isType && $reasons === []) {
            return 'A type answer carried no reasons.';
        }

        return ['answer' => $answer, 'series' => $series === '' ? null : $series,
            'confidence' => $confidence === null ? null : (float) $confidence, 'reasons' => $reasons];
    }

    /**
     * @param  array{answer: string, series: ?string, confidence: ?float, reasons: list<string>}  $answer
     * @param  array<string, mixed>  $provenance
     */
    private function proposal(array $answer, TalkTypeInput $input, ?TalkTypeProposal $ruleProposal, array $provenance): TalkTypeProposal
    {
        $type = TalkType::tryFrom($answer['answer']);
        $signals = [
            'testimony_risk' => ($ruleProposal?->signals['testimony_risk'] ?? false) === true || $type === TalkType::Testimony,
            'g10_withheld_by_default' => ($ruleProposal?->signals['g10_withheld_by_default'] ?? false) === true || $type === TalkType::Testimony,
        ];
        $reasons = array_map(static fn (string $reason): array => ['text' => $reason], $answer['reasons']);

        if ($type === TalkType::ChildrensTalk && ($input->evidence['service'] ?? null) === 'evening') {
            return new TalkTypeProposal(
                proposedSeries: $answer['series'],
                disposition: 'uncertain',
                reasons: [['rule_id' => 'T9.evening_veto', 'reason' => 'The model proposed a family talk, but evening services have no children\'s talks.'], ...$reasons],
                decidedBy: 'model',
                ruleId: 'T9.evening_veto',
                status: 'unresolved',
                provenance: $provenance,
                signals: [...$signals, 'evening_veto' => true],
            );
        }

        return new TalkTypeProposal(
            proposed: $type,
            proposedSeries: $answer['series'],
            disposition: $type === null ? $answer['answer'] : 'proposed',
            confidence: $answer['confidence'],
            reasons: $reasons,
            decidedBy: 'model',
            status: 'completed',
            provenance: $provenance,
            signals: $signals,
        );
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $extra
     */
    private function failed(string $reason, array $provenance, array $extra = []): TalkTypeProposal
    {
        return new TalkTypeProposal(
            disposition: 'uncertain',
            reasons: [['reason' => $reason]],
            decidedBy: 'model',
            status: 'failed',
            provenance: [...$provenance, ...$extra],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseProvenance(string $model, string $effort): array
    {
        return ['model' => $model, 'reasoning_effort' => $effort,
            'prompt_version' => self::PROMPT_VERSION, 'schema_version' => self::SCHEMA_VERSION];
    }
}
