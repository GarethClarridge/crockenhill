<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\TalkTypeClassifier;
use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Enums\TalkType;
use App\Services\ChurchService\OpenAiTalkTypeClassifier;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiTalkTypeClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.analysis.openai_api_key', 'test-key');
        Config::set('media-processing.talk_type.model', 'gpt-5.6-luna');
        Config::set('media-processing.talk_type.reasoning_effort', 'medium');
        Config::set('openai.service_tier', 'flex');
    }

    #[Test]
    public function it_is_bound_to_its_contract_implementation(): void
    {
        $this->assertInstanceOf(OpenAiTalkTypeClassifier::class, $this->app->make(TalkTypeClassifier::class));
    }

    #[Test]
    public function the_prompt_gives_church_update_its_ruled_meaning_and_offers_no_catch_all(): void
    {
        $prompt = $this->classifier()->systemPrompt();

        $this->assertStringContainsString('conference or event', $prompt);
        $this->assertStringContainsString('brief encouragement', $prompt);
        $this->assertStringContainsString('never a catch-all', $prompt);
        $this->assertStringContainsString('none_of_these', $prompt);
        $this->assertStringContainsString('evening', $prompt);

        $schema = $this->classifier()->responseFormat();
        $answers = $schema['json_schema']['schema']['properties']['answer']['enum'];

        sort($answers);
        $expected = [...TalkType::values(), 'not_a_talk', 'none_of_these', 'uncertain', 'insufficient_evidence'];
        sort($expected);
        $this->assertSame($expected, $answers);
        foreach (['other', 'none', 'general', 'misc', 'catch_all'] as $forbidden) {
            $this->assertNotContains($forbidden, $answers);
        }
        $this->assertFalse($schema['json_schema']['schema']['additionalProperties']);
        $this->assertTrue($schema['json_schema']['strict']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function talkTypes(): array
    {
        return array_combine(TalkType::values(), array_map(fn (string $v): array => [$v], TalkType::values()));
    }

    #[Test]
    #[DataProvider('talkTypes')]
    public function it_returns_a_model_proposal_for_each_talk_type(string $type): void
    {
        $service = $type === 'childrens_talk' ? 'morning' : null;
        OpenAI::fake([$this->answer(['answer' => $type, 'series' => '  Heroes of the faith ', 'confidence' => 0.8])]);

        $proposal = $this->classifier()->classify($this->input(['service' => $service]));

        $this->assertSame(TalkType::from($type), $proposal->proposed);
        $this->assertSame('proposed', $proposal->disposition);
        $this->assertSame('model', $proposal->decidedBy);
        $this->assertSame('completed', $proposal->status);
        $this->assertSame(0.8, $proposal->confidence);
        $this->assertSame('Heroes of the faith', $proposal->proposedSeries);
        $this->assertNotSame([], $proposal->reasons);
    }

    #[Test]
    public function a_church_update_report_is_accepted_as_a_named_type(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'church_update', 'reasons' => ['Reports on the Keswick conference members attended.']])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertSame(TalkType::ChurchUpdate, $proposal->proposed);
    }

    #[Test]
    #[DataProvider('nullDispositions')]
    public function each_null_disposition_is_kept_distinct_with_no_type(string $disposition): void
    {
        OpenAI::fake([$this->answer(['answer' => $disposition, 'series' => null, 'confidence' => 0.4])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertNull($proposal->proposed);
        $this->assertSame($disposition, $proposal->disposition);
        $this->assertSame('model', $proposal->decidedBy);
        $this->assertSame('completed', $proposal->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nullDispositions(): array
    {
        return [
            'not a talk' => ['not_a_talk'],
            'none of these' => ['none_of_these'],
            'uncertain' => ['uncertain'],
            'insufficient evidence' => ['insufficient_evidence'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAnswers')]
    public function an_answer_outside_the_allowed_set_is_a_bounded_failure_not_a_guess(string $answer): void
    {
        OpenAI::fake([$this->answer(['answer' => $answer])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertNull($proposal->proposed);
        $this->assertNull($proposal->confidence);
        $this->assertSame('failed', $proposal->status);
        $this->assertSame('model', $proposal->decidedBy);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAnswers(): array
    {
        return ['other' => ['other'], 'none' => ['none'], 'general' => ['general'], 'song' => ['song'], 'empty' => ['']];
    }

    #[Test]
    public function an_evening_service_cannot_yield_a_family_talk(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'childrens_talk', 'series' => 'Bible heroes', 'confidence' => 0.9])]);

        $proposal = $this->classifier()->classify($this->input(['service' => 'evening']));

        $this->assertNull($proposal->proposed);
        $this->assertSame('uncertain', $proposal->disposition);
        $this->assertSame('unresolved', $proposal->status);
        $this->assertNull($proposal->confidence);
        $this->assertTrue($proposal->signals['evening_veto']);
        $this->assertSame('Bible heroes', $proposal->proposedSeries);
    }

    #[Test]
    public function a_family_talk_in_the_morning_is_accepted(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'childrens_talk'])]);

        $proposal = $this->classifier()->classify($this->input(['service' => 'morning']));

        $this->assertSame(TalkType::ChildrensTalk, $proposal->proposed);
    }

    #[Test]
    public function an_evening_service_may_still_yield_other_types(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'testimony'])]);

        $proposal = $this->classifier()->classify($this->input(['service' => 'evening']));

        $this->assertSame(TalkType::Testimony, $proposal->proposed);
    }

    #[Test]
    public function a_testimony_answer_keeps_the_withheld_by_default_signal(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'testimony'])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertTrue($proposal->signals['g10_withheld_by_default']);
        $this->assertTrue($proposal->signals['testimony_risk']);
    }

    #[Test]
    public function other_answers_carry_the_rule_stage_testimony_risk_forward(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'partner_update'])]);
        $rules = new TalkTypeProposal(signals: ['needs_model' => true, 'testimony_risk' => true, 'g10_withheld_by_default' => true]);

        $proposal = $this->classifier()->classify($this->input(), $rules);

        $this->assertTrue($proposal->signals['testimony_risk']);
        $this->assertTrue($proposal->signals['g10_withheld_by_default']);
    }

    #[Test]
    public function the_request_carries_the_evidence_and_the_configured_settings(): void
    {
        OpenAI::fake([$this->answer(['answer' => 'partner_update'])]);

        $this->classifier()->classify($this->input(['text' => 'Our friends in Uganda wrote to us.']));

        OpenAI::assertSent(Chat::class, function (string $method, array $params): bool {
            $user = $params['messages'][1]['content'];

            return $method === 'create'
                && $params['model'] === 'gpt-5.6-luna'
                && $params['reasoning_effort'] === 'medium'
                && $params['service_tier'] === 'flex'
                && $params['response_format']['json_schema']['strict'] === true
                && str_contains($user, 'Our friends in Uganda wrote to us.')
                && ! array_key_exists('temperature', $params);
        });
    }

    #[Test]
    #[DataProvider('malformedContents')]
    public function malformed_empty_or_incomplete_responses_are_a_bounded_failure(?string $content): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['content' => $content]]]])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertNull($proposal->proposed);
        $this->assertNull($proposal->confidence);
        $this->assertSame('failed', $proposal->status);
        $this->assertSame('model', $proposal->decidedBy);
        $this->assertNotSame([], $proposal->reasons);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function malformedContents(): array
    {
        $good = ['answer' => 'sermon', 'series' => null, 'confidence' => 0.5, 'reasons' => ['x']];

        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'not json' => ['The talk is a sermon.'],
            'cut off json' => ['{"answer":"sermon","series":nu'],
            'json scalar' => ['"sermon"'],
            'missing answer' => [json_encode(array_diff_key($good, ['answer' => 1]))],
            'missing confidence key' => [json_encode(array_diff_key($good, ['confidence' => 1]))],
            'confidence above one' => [json_encode([...$good, 'confidence' => 1.5])],
            'confidence negative' => [json_encode([...$good, 'confidence' => -0.1])],
            'confidence string' => [json_encode([...$good, 'confidence' => 'high'])],
            'proposed without confidence' => [json_encode([...$good, 'confidence' => null])],
            'reasons missing' => [json_encode(array_diff_key($good, ['reasons' => 1]))],
            'reasons empty for a type' => [json_encode([...$good, 'reasons' => []])],
            'reasons not strings' => [json_encode([...$good, 'reasons' => [['a' => 1]]])],
            'series not a string' => [json_encode([...$good, 'series' => 7])],
            'series too long' => [json_encode([...$good, 'series' => str_repeat('a', 256)])],
            'unexpected key' => [json_encode([...$good, 'start_time' => 12.0])],
        ];
    }

    #[Test]
    public function a_response_cut_off_by_the_token_limit_is_a_failure_even_if_it_parses(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [[
            'message' => ['content' => json_encode(['answer' => 'sermon', 'series' => null, 'confidence' => 0.9, 'reasons' => ['x']])],
            'finish_reason' => 'length',
        ]]])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertNull($proposal->proposed);
        $this->assertSame('failed', $proposal->status);
    }

    #[Test]
    public function a_model_refusal_is_a_failure(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [[
            'message' => ['content' => null, 'refusal' => 'I cannot help with that.'],
            'finish_reason' => 'stop',
        ]]])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertSame('failed', $proposal->status);
        $this->assertNull($proposal->proposed);
    }

    #[Test]
    public function a_real_rate_limit_propagates_and_is_sent_once(): void
    {
        OpenAI::fake([new RateLimitException(new Response(429, [], json_encode([
            'error' => ['code' => 'rate_limit_exceeded', 'type' => 'requests'],
        ], JSON_THROW_ON_ERROR)))]);

        try {
            $this->classifier()->classify($this->input());
            $this->fail('A genuine 429 must propagate.');
        } catch (RateLimitException $exception) {
            $this->assertSame(429, $exception->response->getStatusCode());
        }

        OpenAI::assertSent(Chat::class, 1);
    }

    #[Test]
    public function flex_unavailability_falls_back_and_logs_the_tier_the_call_ran_on(): void
    {
        Log::spy();
        OpenAI::fake([
            $this->flexUnavailable(),
            $this->answer(['answer' => 'church_update']),
        ]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertSame(TalkType::ChurchUpdate, $proposal->proposed);
        $this->assertSame('default', $proposal->provenance['service_tier']);
        $this->assertSame('flex', $proposal->provenance['requested_service_tier']);
        $this->assertTrue($proposal->provenance['fell_back_from_flex']);
        $this->assertSame('gpt-5.6-luna', $proposal->provenance['model']);
        $this->assertSame(OpenAiTalkTypeClassifier::PROMPT_VERSION, $proposal->provenance['prompt_version']);

        OpenAI::assertSent(Chat::class, 2);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'OpenAI chat completion usage'
                && $context['operation'] === 'talk_type_proposal'
                && $context['service_tier'] === 'default'
                && $context['requested_model'] === 'gpt-5.6-luna'
        )->once();
    }

    #[Test]
    public function a_call_that_stays_on_flex_logs_flex(): void
    {
        Log::spy();
        OpenAI::fake([$this->answer(['answer' => 'sermon'])]);

        $proposal = $this->classifier()->classify($this->input());

        $this->assertSame('flex', $proposal->provenance['service_tier']);
        $this->assertFalse($proposal->provenance['fell_back_from_flex']);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'OpenAI chat completion usage'
                && $context['service_tier'] === 'flex'
        )->once();
    }

    #[Test]
    public function a_missing_api_key_fails_loudly_without_a_call(): void
    {
        Config::set('media-processing.analysis.openai_api_key', null);
        Config::set('openai.api_key', null);
        OpenAI::fake([]);

        $this->expectException(\RuntimeException::class);

        $this->classifier()->classify($this->input());
    }

    private function classifier(): OpenAiTalkTypeClassifier
    {
        return new OpenAiTalkTypeClassifier;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function input(array $evidence = []): TalkTypeInput
    {
        return new TalkTypeInput(
            sectionId: 11,
            evidence: [...['text' => 'Some short talk.', 'title' => null, 'series' => null, 'item' => null,
                'service' => null, 'identity_conflict' => false, 'section_index' => 3, 'outline' => [],
                'transcript' => ['status' => 'ok', 'cues' => [['start' => 0.0, 'end' => 1.0, 'text' => 'x']]],
                'limitations' => []], ...$evidence],
            provenance: ['run_id' => 4],
            versions: ['classifier' => 'test'],
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function answer(array $answer): CreateResponse
    {
        return CreateResponse::fake(['choices' => [[
            'message' => ['content' => json_encode([...['series' => null, 'confidence' => 0.7, 'reasons' => ['Evidence in the text.']], ...$answer])],
            'finish_reason' => 'stop',
        ]]]);
    }

    private function flexUnavailable(): RateLimitException
    {
        return new RateLimitException(new Response(429, [
            'x-ratelimit-remaining-tokens' => '1999997',
            'retry-after' => '300',
        ], json_encode([
            'error' => ['message' => 'Flex does not have sufficient resources available.', 'type' => 'resource_unavailable', 'code' => 'flex_unavailable'],
        ], JSON_THROW_ON_ERROR)));
    }
}
