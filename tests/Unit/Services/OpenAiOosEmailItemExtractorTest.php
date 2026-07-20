<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosEmailItemExtractionResult;
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
                && str_contains($parameters['messages'][1]['content'], 'Email received date: 2026-03-07')
                && $serviceSchema['service']['enum'] === ['morning', 'evening', 'other', 'unknown']
                && $serviceSchema['date']['pattern'] === '^\\d{4}-\\d{2}-\\d{2}$'
                && $serviceSchema['confidence']['minimum'] === 0
                && $serviceSchema['confidence']['maximum'] === 1;
        });
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
