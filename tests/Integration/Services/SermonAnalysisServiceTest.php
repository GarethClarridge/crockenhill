<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonAnalysis;
use App\Services\BritishEnglishConverter;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Public\SermonRepository;
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
use PHPUnit\Framework\Attributes\Test;
use TechWilk\BibleVerseParser\BiblePassageParser;
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
            'media-processing.analysis.retry_delay_base' => 0,
            'media-processing.analysis.max_retries' => 3,
        ]);

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $this->validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), new BiblePassageParser);
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
        OpenAI::assertSent(Chat::class, 1);
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

        $this->service->analyzeSermon($transcript);
    }

    #[Test]
    public function it_throws_on_server_error_letting_queue_retry(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], new Response(500));

        OpenAI::fake([$serverError]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript);
    }

    #[Test]
    public function it_throws_on_authentication_error(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $unauthorizedError = new ErrorException(['message' => 'Unauthorized', 'type' => 'authentication_error', 'code' => null], new Response(401));

        OpenAI::fake([$unauthorizedError]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript);

        OpenAI::assertSent(Chat::class, 1);
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

        $this->service->analyzeSermon($transcript);
    }

    #[Test]
    public function it_throws_on_malformed_ai_response(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            new \TypeError('Malformed response'),
        ]);

        $this->expectException(\Exception::class);

        $this->service->analyzeSermon($transcript);
    }

    #[Test]
    public function it_generates_a_title_from_transcript(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Generated Title',
                                'series' => null,
                                'reference' => null,
                                'points' => ['Point'],
                                'summary' => 'Summary',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $title = $this->service->generateTitle($transcript);

        $this->assertEquals('Generated Title', $title);
    }

    #[Test]
    public function it_identifies_series_from_transcript(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);
        $existingSeries = ['Grace Series', 'John Series'];

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Sermon Title',
                                'series' => 'Grace Series',
                                'reference' => null,
                                'points' => ['Point'],
                                'summary' => 'Summary',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $series = $this->service->identifySeries($transcript, $existingSeries);

        $this->assertEquals('Grace Series', $series);
    }

    #[Test]
    public function it_extracts_bible_passage_from_transcript(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Sermon Title',
                                'series' => null,
                                'reference' => 'John 3:16',
                                'points' => ['Point'],
                                'summary' => 'Summary',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $reference = $this->service->extractBiblePassage($transcript);

        $this->assertEquals('John 3:16', $reference);
    }

    #[Test]
    public function it_extracts_sermon_points_from_transcript(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Sermon Title',
                                'series' => null,
                                'reference' => null,
                                'points' => ['Point 1', 'Point 2'],
                                'summary' => 'Summary',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $points = $this->service->extractSermonPoints($transcript);

        $this->assertCount(2, $points);
        $this->assertEquals('Point 1', $points[0]);
        $this->assertEquals('Point 2', $points[1]);
    }
}
