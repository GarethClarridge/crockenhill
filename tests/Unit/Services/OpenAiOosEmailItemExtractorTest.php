<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosEmailItemExtractionResult;
use App\Exceptions\OosEmailExtractionTruncatedException;
use App\Services\Email\OpenAiOosEmailItemExtractor;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OpenAiOosEmailItemExtractorTest extends TestCase
{
    private OpenAiOosEmailItemExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new OpenAiOosEmailItemExtractor;
        Config::set('openai.api_key', 'test-key');
        Config::set('openai.service_tier', 'flex');
        Config::set('service-tracking.email_parsing.model', 'gpt-5.4-nano');
        Config::set('service-tracking.email_parsing.reasoning_effort', 'minimal');
        // Most tests here are about how one response is validated, not about
        // re-asking, and a retry would silently consume the next queued fake.
        // The retry tests raise this themselves.
        Config::set('service-tracking.email_parsing.extraction_attempts', 1);
    }

    #[Test]
    public function it_extracts_the_complete_service_plan_in_one_openai_call(): void
    {
        OpenAI::fake([$this->response([
            ['service' => 'morning', 'date' => '2026-03-09', 'items' => [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'Amazing Grace'],
                ['type' => 'sermon', 'title' => 'The Prodigal Son'],
            ], 'confidence' => 0.95],
        ], ['Extracted successfully'])]);

        $result = $this->extractor->extract(
            'Order of Service - March 9th',
            "Welcome\nSong: Amazing Grace\nSermon: The Prodigal Son",
            '2026-03-07',
        );

        $this->assertInstanceOf(OosEmailItemExtractionResult::class, $result);
        $this->assertSame('morning', $result->services[0]['service']);
        $this->assertSame('2026-03-09', $result->services[0]['date']);
        $this->assertCount(3, $result->items);
        $this->assertSame(0.95, $result->confidence);
        $this->assertSame(['Extracted successfully'], $result->notes);
        OpenAI::assertSent(Chat::class, 1);
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $serviceSchema = $parameters['response_format']['json_schema']['schema']['properties']['services']['items']['properties'];

            return $method === 'create'
                && $parameters['model'] === 'gpt-5.4-nano'
                && $parameters['service_tier'] === 'flex'
                && $parameters['reasoning_effort'] === 'none'
                && str_contains($parameters['messages'][1]['content'], 'Email received date: 2026-03-07 (Saturday)')
                && str_contains($parameters['messages'][1]['content'], '2026-03-08 Sunday')
                && str_contains($parameters['messages'][1]['content'], '2026-03-21 Saturday')
                && str_contains($parameters['messages'][1]['content'], '[L001] Welcome')
                && str_contains($parameters['messages'][0]['content'], 'A single order may have no heading')
                && str_contains($parameters['messages'][0]['content'], 'Subject-level dates apply to every service plan')
                && $parameters['response_format']['json_schema']['schema']['required']
                    === ['service_count', 'services', 'ignored_lines', 'notes']
                && $serviceSchema['service']['enum'] === ['morning', 'evening', 'other', 'unknown']
                && $serviceSchema['content_scope']['enum'] === ['full', 'partial', 'unknown']
                && $serviceSchema['date']['type'] === ['string', 'null']
                && $serviceSchema['service_evidence_line_ids']['items']['enum'] === [1, 2, 3]
                && $serviceSchema['items']['items']['properties']['source_line_ids']['items']['enum'] === [1, 2, 3]
                && $parameters['response_format']['json_schema']['schema']['properties']['ignored_lines']['items']['properties']['line_id']['enum'] === [1, 2, 3]
                && str_contains($parameters['messages'][0]['content'], 'service slot separately from its occasion')
                && str_contains($parameters['messages'][0]['content'], 'Sunday evening carol service is evening')
                && $serviceSchema['confidence']['type'] === 'number';
        });
    }

    /**
     * The shape the named-person hymn-intro rules ask the model for: a plan whose only boundary
     * evidence is a prose intro sentence, whose items are nothing but songs, and which is partial
     * rather than full. Asserting the prompt contains the rules that request this would only prove
     * the rules are still spelled the same way, so this pins the decoding instead — the intro line
     * stays out of items, every song survives in listed order, and "partial" is not flattened to
     * the "full" default that normaliseContentScope() applies to an absent scope.
     */
    #[Test]
    public function it_decodes_a_song_only_partial_plan_without_promoting_its_intro_line_to_an_item(): void
    {
        OpenAI::fake([$this->response([
            [
                'service' => 'morning',
                'date' => '2026-03-08',
                'content_scope' => 'partial',
                'service_evidence_line_ids' => [1],
                'items' => [
                    ['type' => 'song', 'title' => 'Be Thou My Vision', 'source_line_ids' => [2]],
                    ['type' => 'song', 'title' => 'In Christ Alone', 'source_line_ids' => [3]],
                    ['type' => 'song', 'title' => 'How Great Thou Art', 'source_line_ids' => [4]],
                ],
                'confidence' => 0.9,
            ],
        ])]);

        $result = $this->extractor->extract(
            'Sunday',
            "Jon would like the following hymns tomorrow morning:\nBe Thou My Vision\nIn Christ Alone\nHow Great Thou Art",
            '2026-03-07',
        );

        $this->assertSame('partial', $result->services[0]['content_scope']);
        $this->assertSame([1], $result->services[0]['service_evidence_line_ids']);
        $this->assertSame(['song', 'song', 'song'], array_column($result->items, 'type'));
        $this->assertSame(
            ['Be Thou My Vision', 'In Christ Alone', 'How Great Thou Art'],
            array_column($result->items, 'title'),
        );
        $this->assertNotContains(
            'Jon would like the following hymns tomorrow morning:',
            array_column($result->items, 'title'),
        );
    }

    /**
     * F64. Every line-id field is constrained to an enum of the real source line ids, but a
     * `json_schema` format only binds the model when it is marked strict — without that the
     * enums are advisory and the extractor can cite lines that do not exist. Strict mode also
     * restricts which keywords a schema may carry, so the schema must stay inside the subset
     * this codebase has already proven against the live API in OpenAiServiceStructureService.
     */
    #[Test]
    public function it_enforces_its_response_schema_with_strict_structured_output(): void
    {
        OpenAI::fake([$this->response([
            ['service' => 'morning', 'date' => '2026-03-09', 'items' => [
                ['type' => 'welcome', 'title' => 'Welcome'],
            ], 'confidence' => 0.95],
        ])]);

        $this->extractor->extract('Order of Service', "Welcome\nSong\nSermon", '2026-03-07');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $format = $parameters['response_format']['json_schema'];

            return $format['strict'] === true
                && $this->keywordsUsedIn($format['schema']) === [
                    'additionalProperties', 'enum', 'items', 'properties', 'required', 'type',
                ];
        });
    }

    #[Test]
    public function it_sends_only_the_candidate_disagreement_to_targeted_adjudication(): void
    {
        $initial = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.80,
            services: [['service' => 'morning', 'date' => '2026-03-09', 'items' => [['type' => 'song', 'title' => 'Amazing Grace']], 'confidence' => 0.80]],
        );
        $corrected = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.80,
            services: [['service' => 'morning', 'date' => '2026-03-09', 'items' => [['type' => 'prayer', 'title' => 'Amazing Grace']], 'confidence' => 0.80]],
        );
        OpenAI::fake([$this->response($initial->services)]);

        $this->extractor->adjudicate(
            'Order of Service',
            'Amazing Grace',
            '2026-03-07',
            $initial,
            $corrected,
            ['item_type_or_order'],
        );

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $content = $parameters['messages'][1]['content'];

            return str_contains($content, 'item_type_or_order')
                && str_contains($content, 'Candidate extractions:')
                && str_contains($content, 'Do not invent a third interpretation.');
        });
    }

    /**
     * Collects every JSON Schema keyword the schema actually uses, so an unsupported keyword
     * cannot be reintroduced anywhere in the tree without this test naming it.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function keywordsUsedIn(array $schema): array
    {
        $keywords = [];

        foreach ($schema as $key => $value) {
            $keywords[] = $key;

            // Under `properties` the keys are property names, not keywords, so recurse into
            // each child schema rather than into the map itself.
            foreach (($key === 'properties' ? $value : [$value]) as $child) {
                if (is_array($child) && ! array_is_list($child)) {
                    $keywords = array_merge($keywords, $this->keywordsUsedIn($child));
                }
            }
        }

        $keywords = array_values(array_unique($keywords));
        sort($keywords);

        return $keywords;
    }

    #[Test]
    public function it_preserves_the_extracted_content_scope_for_each_service_plan(): void
    {
        OpenAI::fake([$this->response([[
            'service' => 'morning',
            'date' => '2026-03-09',
            'content_scope' => 'partial',
            'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
            'confidence' => 0.96,
        ]])]);

        $result = $this->extractor->extract('Songs for Sunday', 'Amazing Grace', '2026-03-07');

        $this->assertSame('partial', $result->services[0]['content_scope']);
    }

    #[Test]
    public function it_extracts_multiple_service_plans_and_flattens_items(): void
    {
        OpenAI::fake([$this->response([
            ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Morning Sermon'],
            ], 'confidence' => 0.9],
            ['service' => 'evening', 'date' => null, 'items' => [
                ['type' => 'song', 'title' => 'Evening Hymn'],
            ], 'confidence' => 0.8],
        ], ['Two services found'])]);

        $result = $this->extractor->extract('Order of Service - Sunday 12 July 2026', 'Body', '2026-07-10');

        $this->assertCount(2, $result->services);
        $this->assertSame('morning', $result->services[0]['service']);
        $this->assertSame('2026-07-12', $result->services[0]['date']);
        $this->assertNull($result->services[1]['date']);
        $this->assertCount(3, $result->items);
        $this->assertEqualsWithDelta(0.85, $result->confidence, 0.001);
        $this->assertSame(['Two services found'], $result->notes);
    }

    #[Test]
    public function it_throws_exception_when_api_key_is_missing(): void
    {
        Config::set('openai.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key not configured');

        $this->extractor->extract('Subject', 'Body', '2026-07-10');
    }

    #[Test]
    public function it_rejects_an_invalid_received_date_before_sending_a_request(): void
    {
        OpenAI::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid email received date 2026-02-30.');

        try {
            $this->extractor->extract('Subject', 'Body', '2026-02-30');
        } finally {
            OpenAI::assertSent(Chat::class, 0);
        }
    }

    #[Test]
    public function it_throws_exception_when_openai_returns_empty_content(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [['message' => ['content' => '']]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Received empty response from OpenAI');

        $this->extractor->extract('Subject', 'Body', '2026-07-10');
    }

    #[Test]
    public function it_throws_exception_when_openai_returns_invalid_json(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Not a JSON string']]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode OoS email parser response as JSON');

        $this->extractor->extract('Subject', 'Body', '2026-07-10');
    }

    #[Test]
    public function it_rejects_a_response_without_typed_service_plans(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [['message' => ['content' => json_encode([
                'items' => [['type' => 'song', 'title' => 'Legacy song']],
                'confidence' => 0.9,
                'notes' => [],
            ])]]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain typed service plans');

        $this->extractor->extract('Subject', 'Body', '2026-07-10');
    }

    #[Test]
    public function it_normalises_invalid_nested_values_defensively(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [['message' => ['content' => json_encode([
                'services' => [
                    ['service' => ' morning ', 'date' => ' 2026-03-09 ', 'items' => [
                        ['type' => 'song', 'title' => 'Valid Song'],
                        ['type' => '', 'title' => 'Missing Type'],
                        ['type' => 'prayer', 'title' => ' '],
                        ['invalid' => 'format'],
                        'not-an-array',
                    ], 'confidence' => 1.5],
                    'not-an-array',
                ],
                'notes' => ['Valid note', '', '  ', 123, null],
            ])]]],
        ])]);

        $result = $this->extractor->extract('Subject', 'Body', '2026-07-10');

        $this->assertCount(1, $result->services);
        $this->assertSame('morning', $result->services[0]['service']);
        $this->assertSame('2026-03-09', $result->services[0]['date']);
        $this->assertSame(1.0, $result->services[0]['confidence']);
        $this->assertCount(1, $result->items);
        $this->assertSame('Valid Song', $result->items[0]['title']);
        $this->assertSame(['Valid note'], $result->notes);
    }

    /**
     * @param  list<array{service:string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>  $services
     * @param  list<string>  $notes
     */
    /**
     * The 2026-08-11 staging run lost 2020-03-29 to a single unusable response.
     * Re-asking is the remedy: the same input parsed cleanly on the next call.
     */
    #[Test]
    public function an_unusable_response_is_retried_rather_than_losing_the_service(): void
    {
        Config::set('service-tracking.email_parsing.extraction_attempts', 3);
        OpenAI::fake([
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"services":']]]]),
            $this->response([
                ['service' => 'morning', 'date' => '2026-03-09', 'items' => [
                    ['type' => 'sermon', 'title' => 'The Prodigal Son'],
                ], 'confidence' => 0.9],
            ]),
        ]);

        $result = $this->extractor->extract('Order of Service', 'Sermon: The Prodigal Son', '2026-03-07');

        $this->assertCount(1, $result->items);
        OpenAI::assertSent(Chat::class, 2);
    }

    #[Test]
    public function retries_are_bounded_and_the_last_failure_is_raised(): void
    {
        Config::set('service-tracking.email_parsing.extraction_attempts', 2);
        OpenAI::fake([
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"services":']]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"services":']]]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode OoS email parser response as JSON.');

        try {
            $this->extractor->extract('Order of Service', 'Sermon', '2026-03-07');
        } finally {
            OpenAI::assertSent(Chat::class, 2);
        }
    }

    /**
     * A `json_schema` response format cannot emit malformed JSON, so the realistic
     * cause of a decode failure is truncation at the completion budget. Saying so
     * is what makes the next occurrence diagnosable.
     */
    #[Test]
    public function a_truncated_response_names_the_completion_budget(): void
    {
        Config::set('service-tracking.email_parsing.extraction_attempts', 1);
        Config::set('service-tracking.email_parsing.max_completion_tokens', 1234);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"services":']]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated at the 1234-token completion budget');

        $this->extractor->extract('Order of Service', 'Sermon', '2026-03-07');
    }

    /**
     * Hidden reasoning tokens are billed against the same ceiling as the visible JSON, and the
     * 6000-token figure was measured at `none`/`minimal` output only. Without headroom, raising
     * effort truncates, retries and reports the stronger setting as the worse one — a harness
     * artefact that would disqualify a model on its own merits.
     */
    #[Test]
    public function it_adds_reasoning_headroom_to_the_completion_budget(): void
    {
        Config::set('service-tracking.email_parsing.model', 'gpt-5.6-luna');
        Config::set('service-tracking.email_parsing.reasoning_effort', 'medium');
        Config::set('service-tracking.email_parsing.max_completion_tokens', 6000);
        Config::set('service-tracking.email_parsing.reasoning_token_headroom', ['medium' => 16000]);

        OpenAI::fake([$this->response([
            ['service' => 'morning', 'date' => '2026-03-09', 'items' => []],
        ])]);

        $this->extractor->extract('Order of Service', 'Sermon', '2026-03-09');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return $parameters['max_completion_tokens'] === 22000;
        });
    }

    /**
     * `minimal` is sent as `none` on GPT-5.4+, so headroom keyed on the configured label would
     * grant a budget the request cannot spend.
     */
    #[Test]
    public function it_grants_no_headroom_when_the_effort_sent_is_none(): void
    {
        Config::set('service-tracking.email_parsing.model', 'gpt-5.4-nano');
        Config::set('service-tracking.email_parsing.reasoning_effort', 'minimal');
        Config::set('service-tracking.email_parsing.max_completion_tokens', 6000);
        Config::set('service-tracking.email_parsing.reasoning_token_headroom', ['minimal' => 9999, 'none' => 0]);

        OpenAI::fake([$this->response([
            ['service' => 'morning', 'date' => '2026-03-09', 'items' => []],
        ])]);

        $this->extractor->extract('Order of Service', 'Sermon', '2026-03-09');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return $parameters['max_completion_tokens'] === 6000;
        });
    }

    /**
     * A budget failure and a model-quality failure must be separable, or a ceiling sized for one
     * reasoning effort masquerades as a worse model when the effort is raised.
     */
    #[Test]
    public function a_truncated_response_raises_the_dedicated_truncation_type(): void
    {
        Config::set('service-tracking.email_parsing.extraction_attempts', 1);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"services":']]],
            ]),
        ]);

        $this->expectException(OosEmailExtractionTruncatedException::class);

        $this->extractor->extract('Order of Service', 'Sermon', '2026-03-07');
    }

    private function response(array $services, array $notes = []): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [['message' => ['content' => json_encode([
                'services' => $services,
                'notes' => $notes,
            ])]]],
        ]);
    }
}
