<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonAnalysis;
use App\Services\BritishEnglishConverter;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Public\SermonRepository;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Sermon\SermonAnalysisPromptBuilder;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonAnalysisValidator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class SermonAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonAnalysisService $service;

    private SermonAnalysisValidator $validator;

    private SermonAnalysisPromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.analysis.service' => 'openai',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.analysis.model' => 'gpt-5.6-terra',
            'media-processing.analysis.reasoning_effort' => 'low',
            'openai.service_tier' => 'flex',
        ]);

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $this->validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), app(ScriptureReferenceResolver::class));
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

        $result = $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');

        $this->assertInstanceOf(SermonAnalysis::class, $result);
        $this->assertEquals('The Grace of God', $result->title);
        $this->assertEquals('Grace Series', $result->series);
        $this->assertEquals('Ephesians 2:8-9', $result->reference);
        $this->assertCount(2, $result->points);
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return $method === 'create'
                && $parameters['model'] === 'gpt-5.6-terra'
                && $parameters['service_tier'] === 'flex'
                && $parameters['reasoning_effort'] === 'low'
                && ! array_key_exists('temperature', $parameters);
        });
    }

    #[Test]
    public function it_throws_on_network_failure_letting_queue_retry(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $clientException = new ConnectException(
            'Network error',
            new Request('POST', 'test')
        );

        OpenAI::fake([
            new TransporterException($clientException),
        ]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_throws_on_server_error_letting_queue_retry(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], new Response(500));

        OpenAI::fake([$serverError]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_throws_on_authentication_error(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $unauthorizedError = new ErrorException(['message' => 'Unauthorized', 'type' => 'authentication_error', 'code' => null], new Response(401));

        OpenAI::fake([$unauthorizedError]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');

        OpenAI::assertSent(Chat::class, 1);
    }

    #[Test]
    #[DataProvider('httpFailures')]
    public function it_logs_http_failure_diagnostics_without_service_level_retry_scaffolding(
        int $status,
        string $message,
        string $errorType,
    ): void {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);
        $logger = $this->createMock(SermonProcessingLogger::class);
        $service = $this->makeServiceWithLogger($logger);

        $logger->expects($this->once())
            ->method('logApiCall')
            ->with(
                'test-processing-id',
                'OpenAI',
                'chat/completions',
                $this->greaterThanOrEqual(0.0),
                $status,
                $message,
                $this->callback(fn (array $context): bool => $context['model'] === 'gpt-5.6-terra'
                    && $context['error_type'] === $errorType),
            );

        OpenAI::fake([
            new ErrorException(
                ['message' => $message, 'type' => $errorType, 'code' => null],
                new Response($status),
            ),
        ]);

        $this->expectException(ErrorException::class);

        $service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_logs_wrapped_failure_diagnostics_without_exposing_request_context(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);
        $logger = $this->createMock(SermonProcessingLogger::class);
        $service = $this->makeServiceWithLogger($logger);

        $logger->expects($this->once())
            ->method('logError')
            ->with(
                'test-processing-id',
                'ai_analysis',
                $this->isInstanceOf(\Exception::class),
                $this->callback(fn (array $context): bool => $context['model'] === 'gpt-5.6-terra'
                    && $context['error_type'] === 'Exception'
                    && is_float($context['api_time_ms'])),
            );

        OpenAI::fake([new \RuntimeException('request failed for api_key=super-secret')]);

        $this->expectExceptionMessage('OpenAI API call failed.');

        $service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_throws_when_ai_generates_a_title_exceeding_the_character_limit(): void
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

        OpenAI::fake([
            CreateResponse::fake($mockResponseLong),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/AI title exceeds/');

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_throws_on_malformed_ai_response(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            new \TypeError('Malformed response'),
        ]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    private function makeServiceWithLogger(SermonProcessingLogger&MockObject $logger): SermonAnalysisService
    {
        return new SermonAnalysisService(
            $logger,
            app(SermonRepository::class),
            $this->validator,
            $this->promptBuilder,
        );
    }

    /** @return array<string, array{int, string, string}> */
    public static function httpFailures(): array
    {
        return [
            'authentication failure' => [401, 'Unauthorized', 'authentication_error'],
            'server failure' => [500, 'Internal Server Error', 'server_error'],
        ];
    }
}
