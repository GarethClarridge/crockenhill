<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SermonAnalysis;
use App\Repositories\SermonRepository;
use App\Services\BritishEnglishConverter;
use App\Services\SermonAnalysisPromptBuilder;
use App\Services\SermonAnalysisService;
use App\Services\SermonAnalysisValidator;
use App\Services\SermonProcessingLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAnalysisServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SermonAnalysisService $service;

    private SermonAnalysisValidator $validator;

    private SermonAnalysisPromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.analysis.service' => 'openai',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.analysis.retry_delay_base' => 0,
            'media-processing.analysis.max_retries' => 3,
        ]);

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $this->validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class));
        $this->promptBuilder = new SermonAnalysisPromptBuilder($this->validator);

        $this->service = new SermonAnalysisService(
            $logger,
            $repository,
            $this->validator,
            $this->promptBuilder
        );
    }

    #[Test]
    public function it_successfully_analyzes_a_sermon_on_the_first_attempt(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => 'The Grace of God',
                            'series' => 'Grace Series',
                            'reference' => 'Ephesians 2:8-9',
                            'points' => ['Point 1', 'Point 2'],
                            'summary' => 'A sermon about the amazing grace of God.',
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->service->analyzeSermon($transcript);

        $this->assertInstanceOf(SermonAnalysis::class, $result);
        $this->assertEquals('The Grace of God', $result->title);
        $this->assertEquals('Grace Series', $result->series);
        $this->assertEquals('Ephesians 2:8-9', $result->reference);
        $this->assertCount(2, $result->points);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 1);
    }

    #[Test]
    public function it_retries_and_succeeds_after_a_network_failure(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Succeeds After Retry',
                            'series' => null,
                            'reference' => null,
                            'points' => ['Main point'],
                            'summary' => 'Summary after retry.',
                        ]),
                    ],
                ],
            ],
        ];

        $clientException = new \GuzzleHttp\Exception\ConnectException(
            'Network error',
            new \GuzzleHttp\Psr7\Request('POST', 'test')
        );

        OpenAI::fake([
            new TransporterException($clientException),
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Succeeds After Retry', $result->title);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 2);
    }

    #[Test]
    public function it_retries_and_succeeds_after_a_server_error(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Succeeds After 500',
                            'series' => null,
                            'reference' => null,
                            'points' => ['Main point'],
                            'summary' => 'Summary after 500.',
                        ]),
                    ],
                ],
            ],
        ];

        // ErrorException needs array for its first argument when using OpenAI SDK mock
        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], 500);

        OpenAI::fake([
            $serverError,
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Succeeds After 500', $result->title);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 2);
    }

    #[Test]
    public function it_stops_retries_and_returns_fallback_on_non_retryable_error(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        // 401 is non-retryable
        $unauthorizedError = new ErrorException(['message' => 'Unauthorized', 'type' => 'authentication_error', 'code' => null], 401);

        // Mock OpenAI to return 401
        OpenAI::fake([
            $unauthorizedError,
        ]);

        $result = $this->service->analyzeSermon($transcript);

        // Should return fallback data
        $this->assertNotEmpty($result->title);
        $this->assertEquals(['Main Message'], $result->points);

        // Assert that only one OpenAI call was made (non-retryable)
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 1);
    }

    #[Test]
    public function it_retries_when_ai_generates_a_title_exceeding_the_character_limit(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $longTitle = str_repeat('A', 61); // Exceeds 60 chars

        $mockResponseLong = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => $longTitle,
                            'series' => null,
                            'reference' => null,
                            'points' => ['Point'],
                            'summary' => 'Summary',
                        ]),
                    ],
                ],
            ],
        ];

        $mockResponseShort = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Short Title',
                            'series' => null,
                            'reference' => null,
                            'points' => ['Point'],
                            'summary' => 'Summary',
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponseLong),
            CreateResponse::fake($mockResponseShort),
        ]);

        $result = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Short Title', $result->title);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 2);
    }

    #[Test]
    public function it_retries_when_parsing_the_ai_response_fails(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        // Malformed JSON will cause a TypeError in some versions of the SDK or caught in parseAiResponse
        // Actually, executeAiRequest has a try/catch for TypeError too.

        $mockResponseValid = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Valid Title',
                            'series' => null,
                            'reference' => null,
                            'points' => ['Point'],
                            'summary' => 'Summary',
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            new \TypeError('Malformed response'), // Simulated TypeError
            CreateResponse::fake($mockResponseValid),
        ]);

        $result = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Valid Title', $result->title);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 2);
    }

    #[Test]
    public function it_returns_fallback_data_after_all_retry_attempts_fail(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], 500);

        OpenAI::fake([
            $serverError,
            $serverError,
            $serverError,
        ]);

        $result = $this->service->analyzeSermon($transcript);

        // Should return fallback data
        $this->assertNotEmpty($result->title);
        $this->assertEquals(['Main Message'], $result->points);
        OpenAI::assertSent(\OpenAI\Resources\Chat::class, 3);
    }
}
