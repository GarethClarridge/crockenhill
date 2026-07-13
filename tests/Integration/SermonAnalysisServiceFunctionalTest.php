<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Models\Sermon;
use App\Services\BritishEnglishConverter;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Public\SermonRepository;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Sermon\SermonAnalysisPromptBuilder;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonAnalysisValidator;
use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAnalysisServiceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    private SermonAnalysisService $service;

    private SermonAnalysisValidator $validator;

    private SermonAnalysisPromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up configuration
        config([
            'media-processing.analysis.openai_api_key' => 'test-api-key',
            'openai.api_key' => 'test-api-key',
        ]);

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $this->validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), app(ScriptureReferenceResolver::class));
        $this->promptBuilder = new SermonAnalysisPromptBuilder($this->validator);
        $this->service = new SermonAnalysisService($logger, $repository, $this->validator, $this->promptBuilder);
    }

    #[Test]
    public function it_throws_exception_when_openai_api_key_not_configured(): void
    {
        config([
            'media-processing.analysis.service' => 'openai',
            'media-processing.analysis.openai_api_key' => '',
            'openai.api_key' => '',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key not configured');

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), app(ScriptureReferenceResolver::class));
        $promptBuilder = new SermonAnalysisPromptBuilder($validator);
        new SermonAnalysisService($logger, $repository, $validator, $promptBuilder);
    }

    #[Test]
    public function it_validates_transcript_length(): void
    {
        $shortTranscript = 'Too short';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transcript validation failed');

        $this->service->analyzeSermon($shortTranscript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_validates_transcript_word_count(): void
    {
        $fewWordsTranscript = str_repeat('word ', 10); // Only 10 words

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transcript validation failed');

        $this->service->analyzeSermon($fewWordsTranscript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_includes_existing_series_in_analysis_prompt(): void
    {
        // Clear any existing sermons to ensure test isolation
        Sermon::query()->delete();

        // Create some test sermons with series
        Sermon::factory()->create(['series' => 'John Study']);
        Sermon::factory()->create(['series' => 'Romans Study']);
        Sermon::factory()->create(['series' => null]); // Should be ignored
        Sermon::factory()->create(['series' => '']); // Should be ignored

        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'God\'s Amazing Love',
                                'series' => 'John Study',
                                'reference' => 'John 3:16-21',
                                'points' => ['First point', 'Second point', 'Third point'],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $prompt = $parameters['messages'][1]['content'];

            // Verify that only valid series are included in the prompt, correctly formatted
            return str_contains($prompt, "EXISTING SERMON SERIES (match one if applicable):\nJohn Study, Romans Study\n");
        });
    }

    #[Test]
    public function it_validates_and_cleans_title_correctly(): void
    {
        // Test normal title
        $result = $this->validator->validateAndCleanTitle('God\'s Amazing Love');
        $this->assertEquals('God\'s Amazing Love', $result);

        // Test title with quotes
        $result = $this->validator->validateAndCleanTitle('"The Heart of the Gospel"');
        $this->assertEquals('The Heart of the Gospel', $result);

        // Test long title (should be truncated to 12 words)
        $longTitle = 'This is a very long sermon title that exceeds the twelve word limit and should be truncated properly';
        $result = $this->validator->validateAndCleanTitle($longTitle);
        $words = explode(' ', $result);
        $this->assertLessThanOrEqual(12, count($words));

        // Test empty title
        $result = $this->validator->validateAndCleanTitle('');
        $this->assertEquals('Untitled sermon', $result);

        // Test very short title
        $result = $this->validator->validateAndCleanTitle('Hi');
        $this->assertEquals('Untitled sermon', $result);

        // Test title with single quotes
        $result = $this->validator->validateAndCleanTitle('\'God\'s Love\'');
        $this->assertEquals('God\'s Love', $result);
    }

    #[Test]
    public function it_validates_bible_reference_format(): void
    {
        // Test valid references
        $this->assertEquals('John 3:16', $this->validator->validateBibleReference('John 3:16'));
        $this->assertEquals('1 John 2:1-5', $this->validator->validateBibleReference('1 John 2:1-5'));
        $this->assertEquals('Romans 8:28-39', $this->validator->validateBibleReference('Romans 8:28-39'));
        $this->assertEquals('2 Corinthians 5:17', $this->validator->validateBibleReference('2 Corinthians 5:17'));
        $this->assertEquals('Psalm 23', $this->validator->validateBibleReference('Psalm 23'));

        // Test invalid references
        $this->assertNull($this->validator->validateBibleReference('Not a reference'));
        $this->assertNull($this->validator->validateBibleReference('Random text'));
        $this->assertNull($this->validator->validateBibleReference(''));
        $this->assertNull($this->validator->validateBibleReference('Book'));
        $this->assertNull($this->validator->validateBibleReference('123'));
    }

    #[Test]
    public function it_generates_fallback_title_from_transcript(): void
    {
        // Test with meaningful content
        $transcript = 'Good morning everyone. Today we are going to explore the wonderful truth about God\'s love and mercy in the book of John.';
        $result = $this->promptBuilder->generateFallbackTitle($transcript);

        $this->assertNotEmpty($result);
        $this->assertNotEquals('Sermon - '.date('F j, Y'), $result); // Should not fall back to date

        // Test with transcript that should fall back to date
        $transcript = 'Good morning welcome today we are going to look at';
        $result = $this->promptBuilder->generateFallbackTitle($transcript);

        $this->assertStringContainsString('Sermon - ', $result);

        // Test with transcript containing meaningful words
        $transcript = 'The grace of our Lord Jesus Christ is sufficient for all our needs and troubles in this life.';
        $result = $this->promptBuilder->generateFallbackTitle($transcript);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('grace', strtolower($result));
    }

    #[Test]
    public function it_validates_and_cleans_analysis_data(): void
    {
        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $analysisData = [
            'title' => 'God\'s Amazing Love',
            'series' => 'John Study',
            'reference' => 'John 3:16-21',
            'points' => ['First point', 'Second point', 'Third point'],
        ];

        $result = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        $this->assertEquals('God\'s Amazing Love', $result['title']);
        $this->assertEquals('John Study', $result['series']);
        $this->assertEquals('John 3:16-21', $result['reference']);
        $this->assertCount(3, $result['points']);
        $this->assertEquals($transcript, $result['transcript']);
    }

    #[Test]
    public function it_handles_null_and_empty_analysis_data(): void
    {
        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $analysisData = [
            'title' => '',
            'series' => 'null',
            'reference' => 'none',
            'points' => [],
        ];

        $result = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        $this->assertEquals('Untitled sermon', $result['title']);
        $this->assertNull($result['series']);
        $this->assertNull($result['reference']);
        $this->assertEquals(['Main Message'], $result['points']); // Fallback point

        // Test with various null representations
        $analysisData = [
            'title' => 'Valid Title',
            'series' => 'None',
            'reference' => 'NULL',
            'points' => ['', '  ', 'Valid Point'],
        ];

        $result = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        $this->assertEquals('Valid Title', $result['title']);
        $this->assertNull($result['series']);
        $this->assertNull($result['reference']);
        $this->assertEquals(['Valid Point'], $result['points']); // Only valid point kept
    }

    #[Test]
    public function it_preserves_long_ai_titles_without_truncating(): void
    {
        $longTitle = 'This is a sermon title that is definitely longer than sixty characters in total';

        $analysis = SermonAnalysis::fromAiAnalysis([
            'title' => $longTitle,
            'points' => [],
            'transcript' => str_repeat('t', 200),
        ]);

        // Word-limited to 12, but not character-truncated
        $this->assertLessThanOrEqual(12, count(explode(' ', $analysis->title)));
        $this->assertStringNotContainsString('...', $analysis->title);
    }

    #[Test]
    public function it_validates_transcript_with_various_conditions(): void
    {
        // Test minimum length requirement
        $shortTranscript = str_repeat('a', 99); // Just under 100 chars
        $this->assertFalse($this->validator->validateTranscript($shortTranscript));

        $validLengthTranscript = str_repeat('word ', 25); // 100+ chars, 25 words
        $this->assertTrue($this->validator->validateTranscript($validLengthTranscript));

        // Test word count requirement
        $fewWordsTranscript = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen'; // 19 words
        $this->assertFalse($this->validator->validateTranscript($fewWordsTranscript));

        $enoughWordsTranscript = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone'; // 21 words
        $this->assertTrue($this->validator->validateTranscript($enoughWordsTranscript));

        // Test empty and whitespace
        $this->assertFalse($this->validator->validateTranscript(''));
        $this->assertFalse($this->validator->validateTranscript('   '));
        $this->assertFalse($this->validator->validateTranscript("\n\t  \n"));
    }

    #[Test]
    public function it_throws_on_openai_errors_letting_queue_retry(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], new Response(500));
        OpenAI::fake([$serverError]);

        $this->expectException(Exception::class);

        $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');
    }

    #[Test]
    public function it_builds_comprehensive_analysis_prompt(): void
    {
        $transcript = 'This is a sample sermon transcript about God\'s love and grace.';
        $existingSeries = ['John Study', 'Romans Study', 'Christmas Messages'];

        $prompt = $this->promptBuilder->buildAnalysisPrompt($transcript, $existingSeries);

        $this->assertStringContainsString('John Study', $prompt);
        $this->assertStringContainsString('Romans Study', $prompt);
        $this->assertStringContainsString('Christmas Messages', $prompt);
        $this->assertStringContainsString($transcript, $prompt);
        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('title', $prompt);
        $this->assertStringContainsString('series', $prompt);
        $this->assertStringContainsString('reference', $prompt);
        $this->assertStringContainsString('points', $prompt);

        // Test with empty series
        $prompt = $this->promptBuilder->buildAnalysisPrompt($transcript, []);
        $this->assertStringContainsString('None available', $prompt);
    }

    #[Test]
    public function it_implements_analysis_interface(): void
    {
        $this->assertInstanceOf(SermonAnalysisInterface::class, $this->service);
    }

    #[Test]
    public function it_falls_back_to_empty_series_list_on_database_error(): void
    {
        $transcript = str_repeat('This is a valid sermon transcript with enough words to pass validation. ', 10);

        // Mock OpenAI to avoid actual API call
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Test Title',
                                'series' => null,
                                'reference' => null,
                                'points' => ['Point'],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        // Set an invalid database connection that will cause an exception when getting series
        $originalConnection = config('database.default');
        config(['database.connections.invalid' => ['driver' => 'invalid']]);
        config(['database.default' => 'invalid']);

        try {
            // Should not throw and prompt should still be built
            $this->service->analyzeSermon($transcript, processingId: 'test-processing-id');

            OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
                return str_contains($parameters['messages'][1]['content'], 'None available');
            });
        } finally {
            // Restore the original connection
            config(['database.default' => $originalConnection]);
        }
    }

    #[Test]
    public function it_handles_analysis_data_with_mixed_types(): void
    {
        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        // Test with mixed data types
        $analysisData = [
            'title' => 123, // Should be converted/handled
            'series' => true, // Should be handled
            'reference' => ['array'], // Should be handled
            'points' => 'string instead of array', // Should be handled
        ];

        $result = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        $this->assertEquals('123', $result['title']); // Current behavior: numeric values are kept as-is
        $this->assertNull($result['series']); // Invalid series becomes null
        $this->assertNull($result['reference']); // Invalid reference becomes null
        $this->assertEquals(['Main Message'], $result['points']); // Fallback for invalid points
    }
}
