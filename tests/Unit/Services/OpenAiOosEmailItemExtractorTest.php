<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosEmailItemExtractionResult;
use App\Services\Email\OpenAiOosEmailItemExtractor;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
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
    }

    #[Test]
    public function it_extracts_items_from_email_using_openai(): void
    {
        $subject = 'Order of Service - March 9th';
        $body = "Welcome\nSong: Amazing Grace\nSermon: The Prodigal Son";

        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'items' => [
                                ['type' => 'welcome', 'title' => 'Welcome'],
                                ['type' => 'song', 'title' => 'Amazing Grace'],
                                ['type' => 'sermon', 'title' => 'The Prodigal Son'],
                            ],
                            'confidence' => 0.95,
                            'notes' => ['Extracted successfully'],
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->extractor->extract($subject, $body);

        $this->assertInstanceOf(OosEmailItemExtractionResult::class, $result);
        $this->assertCount(3, $result->items);
        $this->assertEquals('welcome', $result->items[0]['type']);
        $this->assertEquals('Welcome', $result->items[0]['title']);
        $this->assertEquals(0.95, $result->confidence);
        $this->assertEquals(['Extracted successfully'], $result->notes);
    }

    #[Test]
    public function it_extracts_multiple_service_plans_and_flattens_items(): void
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'services' => [
                                ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                                    ['type' => 'welcome', 'title' => 'Welcome'],
                                    ['type' => 'sermon', 'title' => 'Morning Sermon'],
                                ], 'confidence' => 0.9],
                                ['service' => 'evening', 'date' => null, 'items' => [
                                    ['type' => 'song', 'title' => 'Evening Hymn'],
                                ], 'confidence' => 0.8],
                            ],
                            'notes' => ['Two services found'],
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([CreateResponse::fake($mockResponse)]);

        $result = $this->extractor->extract('Order of Service - Sunday 12 July 2026', 'Body');

        $this->assertCount(2, $result->services);
        $this->assertSame('morning', $result->services[0]['service']);
        $this->assertSame('2026-07-12', $result->services[0]['date']);
        $this->assertNull($result->services[1]['date']);
        // Items are flattened across services for backward compatibility.
        $this->assertCount(3, $result->items);
        // Overall confidence is the mean of the plan confidences.
        $this->assertEqualsWithDelta(0.85, $result->confidence, 0.001);
        $this->assertSame(['Two services found'], $result->notes);
    }

    #[Test]
    public function it_throws_exception_when_api_key_is_missing(): void
    {
        Config::set('openai.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key not configured');

        $this->extractor->extract('Subject', 'Body');
    }

    #[Test]
    public function it_throws_exception_when_openai_returns_empty_content(): void
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => '',
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Received empty response from OpenAI');

        $this->extractor->extract('Subject', 'Body');
    }

    #[Test]
    public function it_throws_exception_when_openai_returns_invalid_json(): void
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Not a JSON string',
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode OoS email parser response as JSON');

        $this->extractor->extract('Subject', 'Body');
    }

    #[Test]
    public function it_normalises_items_by_skipping_invalid_entries(): void
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'items' => [
                                ['type' => 'song', 'title' => 'Valid Song'],
                                ['type' => '', 'title' => 'Missing Type'],
                                ['type' => 'prayer', 'title' => ' '], // Empty title after trim
                                ['invalid' => 'format'],
                                'not-an-array',
                            ],
                            'confidence' => 0.8,
                            'notes' => [],
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->extractor->extract('Subject', 'Body');

        $this->assertCount(1, $result->items);
        $this->assertEquals('song', $result->items[0]['type']);
        $this->assertEquals('Valid Song', $result->items[0]['title']);
    }

    #[Test]
    public function it_normalises_confidence_score_to_range_0_to_1(): void
    {
        // Test upper bound
        $mockResponseHigh = [
            'choices' => [['message' => ['content' => json_encode(['items' => [], 'confidence' => 1.5, 'notes' => []])]]],
        ];
        OpenAI::fake([CreateResponse::fake($mockResponseHigh)]);
        $resultHigh = $this->extractor->extract('Subject', 'Body');
        $this->assertEquals(1.0, $resultHigh->confidence);

        // Test lower bound
        $mockResponseLow = [
            'choices' => [['message' => ['content' => json_encode(['items' => [], 'confidence' => -0.5, 'notes' => []])]]],
        ];
        OpenAI::fake([CreateResponse::fake($mockResponseLow)]);
        $resultLow = $this->extractor->extract('Subject', 'Body');
        $this->assertEquals(0.0, $resultLow->confidence);

        // Test non-numeric
        $mockResponseInvalid = [
            'choices' => [['message' => ['content' => json_encode(['items' => [], 'confidence' => 'high', 'notes' => []])]]],
        ];
        OpenAI::fake([CreateResponse::fake($mockResponseInvalid)]);
        $resultInvalid = $this->extractor->extract('Subject', 'Body');
        $this->assertEquals(0.0, $resultInvalid->confidence);
    }

    #[Test]
    public function it_normalises_notes_by_skipping_empty_or_non_string_values(): void
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'items' => [],
                            'confidence' => 1.0,
                            'notes' => [
                                'Valid note',
                                '',
                                '  ',
                                123,
                                null,
                            ],
                        ]),
                    ],
                ],
            ],
        ];

        OpenAI::fake([
            CreateResponse::fake($mockResponse),
        ]);

        $result = $this->extractor->extract('Subject', 'Body');

        $this->assertCount(1, $result->notes);
        $this->assertEquals('Valid note', $result->notes[0]);
    }
}
