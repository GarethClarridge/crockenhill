<?php

namespace Tests\Unit;

use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Services\BritishEnglishConverter;
use App\Services\SermonAnalysisService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAnalysisServiceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    private SermonAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up configuration
        config([
            'media-processing.analysis.openai_api_key' => 'test-api-key',
            'media-processing.analysis.model' => 'gpt-3.5-turbo',
            'openai.api_key' => 'test-api-key',
        ]);

        $logger = app(\App\Services\SermonProcessingLogger::class);
        $converter = app(BritishEnglishConverter::class);
        $repository = app(SermonRepository::class);
        $this->service = new SermonAnalysisService($logger, $converter, $repository);
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

        $logger = app(\App\Services\SermonProcessingLogger::class);
        $converter = app(BritishEnglishConverter::class);
        $repository = app(SermonRepository::class);
        new SermonAnalysisService($logger, $converter, $repository);
    }

    #[Test]
    public function it_validates_transcript_length(): void
    {
        $shortTranscript = 'Too short';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transcript validation failed');

        $this->service->analyzeSermon($shortTranscript);
    }

    #[Test]
    public function it_validates_transcript_word_count(): void
    {
        $fewWordsTranscript = str_repeat('word ', 10); // Only 10 words

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transcript validation failed');

        $this->service->analyzeSermon($fewWordsTranscript);
    }

    #[Test]
    public function it_gets_existing_series_from_database(): void
    {
        // Clear any existing sermons to ensure test isolation
        Sermon::query()->delete();

        // Create some test sermons with series
        Sermon::factory()->create(['series' => 'John Study']);
        Sermon::factory()->create(['series' => 'Romans Study']);
        Sermon::factory()->create(['series' => null]); // Should be ignored
        Sermon::factory()->create(['series' => '']); // Should be ignored

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getExistingSeries');
        $method->setAccessible(true);

        $existingSeries = $method->invoke($this->service);

        $this->assertIsArray($existingSeries);
        $this->assertContains('John Study', $existingSeries);
        $this->assertContains('Romans Study', $existingSeries);
        $this->assertCount(2, $existingSeries);
    }

    #[Test]
    public function it_validates_and_cleans_title_correctly(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAndCleanTitle');
        $method->setAccessible(true);

        // Test normal title
        $result = $method->invoke($this->service, 'God\'s Amazing Love');
        $this->assertEquals('God\'s Amazing Love', $result);

        // Test title with quotes
        $result = $method->invoke($this->service, '"The Heart of the Gospel"');
        $this->assertEquals('The Heart of the Gospel', $result);

        // Test long title (should be truncated to 12 words)
        $longTitle = 'This is a very long sermon title that exceeds the twelve word limit and should be truncated properly';
        $result = $method->invoke($this->service, $longTitle);
        $words = explode(' ', $result);
        $this->assertLessThanOrEqual(12, count($words));

        // Test empty title
        $result = $method->invoke($this->service, '');
        $this->assertEquals('Untitled sermon', $result);

        // Test very short title
        $result = $method->invoke($this->service, 'Hi');
        $this->assertEquals('Untitled sermon', $result);

        // Test title with single quotes
        $result = $method->invoke($this->service, '\'God\'s Love\'');
        $this->assertEquals('God\'s Love', $result);
    }

    #[Test]
    public function it_validates_bible_reference_format(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateBibleReference');
        $method->setAccessible(true);

        // Test valid references
        $this->assertEquals('John 3:16', $method->invoke($this->service, 'John 3:16'));
        $this->assertEquals('1 John 2:1-5', $method->invoke($this->service, '1 John 2:1-5'));
        $this->assertEquals('Romans 8:28-39', $method->invoke($this->service, 'Romans 8:28-39'));
        $this->assertEquals('2 Corinthians 5:17', $method->invoke($this->service, '2 Corinthians 5:17'));
        $this->assertEquals('Psalm 23', $method->invoke($this->service, 'Psalm 23'));

        // Test invalid references
        $this->assertNull($method->invoke($this->service, 'Not a reference'));
        $this->assertNull($method->invoke($this->service, 'Random text'));
        $this->assertNull($method->invoke($this->service, ''));
        $this->assertNull($method->invoke($this->service, 'Book'));
        $this->assertNull($method->invoke($this->service, '123'));
    }

    #[Test]
    public function it_generates_fallback_title_from_transcript(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateFallbackTitle');
        $method->setAccessible(true);

        // Test with meaningful content
        $transcript = 'Good morning everyone. Today we are going to explore the wonderful truth about God\'s love and mercy in the book of John.';
        $result = $method->invoke($this->service, $transcript);

        $this->assertNotEmpty($result);
        $this->assertNotEquals('Sermon - '.date('F j, Y'), $result); // Should not fall back to date

        // Test with transcript that should fall back to date
        $transcript = 'Good morning welcome today we are going to look at';
        $result = $method->invoke($this->service, $transcript);

        $this->assertStringContainsString('Sermon - ', $result);

        // Test with transcript containing meaningful words
        $transcript = 'The grace of our Lord Jesus Christ is sufficient for all our needs and troubles in this life.';
        $result = $method->invoke($this->service, $transcript);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('grace', strtolower($result));
    }

    #[Test]
    public function it_validates_and_cleans_analysis_data(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAndCleanAnalysisData');
        $method->setAccessible(true);

        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $analysisData = [
            'title' => 'God\'s Amazing Love',
            'series' => 'John Study',
            'reference' => 'John 3:16-21',
            'points' => ['First point', 'Second point', 'Third point'],
        ];

        $result = $method->invoke($this->service, $analysisData, $transcript);

        $this->assertEquals('God\'s Amazing Love', $result['title']);
        $this->assertEquals('John Study', $result['series']);
        $this->assertEquals('John 3:16-21', $result['reference']);
        $this->assertCount(3, $result['points']);
        $this->assertEquals($transcript, $result['transcript']);
    }

    #[Test]
    public function it_handles_null_and_empty_analysis_data(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAndCleanAnalysisData');
        $method->setAccessible(true);

        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $analysisData = [
            'title' => '',
            'series' => 'null',
            'reference' => 'none',
            'points' => [],
        ];

        $result = $method->invoke($this->service, $analysisData, $transcript);

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

        $result = $method->invoke($this->service, $analysisData, $transcript);

        $this->assertEquals('Valid Title', $result['title']);
        $this->assertNull($result['series']);
        $this->assertNull($result['reference']);
        $this->assertEquals(['Valid Point'], $result['points']); // Only valid point kept
    }

    #[Test]
    public function it_generates_fallback_analysis_data(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getFallbackAnalysisData');
        $method->setAccessible(true);

        $transcript = 'This is a sample sermon transcript about God\'s love and grace in our lives with enough content.';
        $result = $method->invoke($this->service, $transcript);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('reference', $result);
        $this->assertArrayHasKey('points', $result);
        $this->assertArrayHasKey('transcript', $result);

        $this->assertNotEmpty($result['title']);
        $this->assertNull($result['series']);
        $this->assertNull($result['reference']);
        $this->assertEquals(['Main Message'], $result['points']);
        $this->assertEquals($transcript, $result['transcript']);
    }

    #[Test]
    public function it_validates_transcript_with_various_conditions(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateTranscript');
        $method->setAccessible(true);

        // Test minimum length requirement
        $shortTranscript = str_repeat('a', 99); // Just under 100 chars
        $this->assertFalse($method->invoke($this->service, $shortTranscript));

        $validLengthTranscript = str_repeat('word ', 25); // 100+ chars, 25 words
        $this->assertTrue($method->invoke($this->service, $validLengthTranscript));

        // Test word count requirement
        $fewWordsTranscript = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen'; // 19 words
        $this->assertFalse($method->invoke($this->service, $fewWordsTranscript));

        $enoughWordsTranscript = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone'; // 21 words
        $this->assertTrue($method->invoke($this->service, $enoughWordsTranscript));

        // Test empty and whitespace
        $this->assertFalse($method->invoke($this->service, ''));
        $this->assertFalse($method->invoke($this->service, '   '));
        $this->assertFalse($method->invoke($this->service, "\n\t  \n"));
    }

    #[Test]
    public function it_identifies_non_retryable_errors(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isNonRetryableError');
        $method->setAccessible(true);

        // Non-retryable errors - using proper ErrorException constructor with array
        $badRequestError = new ErrorException(['message' => 'Bad Request', 'type' => 'invalid_request_error', 'code' => null], 400);
        $unauthorizedError = new ErrorException(['message' => 'Unauthorized', 'type' => 'authentication_error', 'code' => null], 401);
        $forbiddenError = new ErrorException(['message' => 'Forbidden', 'type' => 'permission_error', 'code' => null], 403);

        $this->assertTrue($method->invoke($this->service, $badRequestError));
        $this->assertTrue($method->invoke($this->service, $unauthorizedError));
        $this->assertTrue($method->invoke($this->service, $forbiddenError));

        // Retryable errors
        $serverError = new ErrorException(['message' => 'Internal Server Error', 'type' => 'server_error', 'code' => null], 500);
        $rateLimitError = new ErrorException(['message' => 'Rate Limit Exceeded', 'type' => 'rate_limit_error', 'code' => null], 429);
        $timeoutError = new ErrorException(['message' => 'Request Timeout', 'type' => 'timeout_error', 'code' => null], 408);

        $this->assertFalse($method->invoke($this->service, $serverError));
        $this->assertFalse($method->invoke($this->service, $rateLimitError));
        $this->assertFalse($method->invoke($this->service, $timeoutError));
    }

    #[Test]
    public function it_builds_comprehensive_analysis_prompt(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildAnalysisPrompt');
        $method->setAccessible(true);

        $transcript = 'This is a sample sermon transcript about God\'s love and grace.';
        $existingSeries = ['John Study', 'Romans Study', 'Christmas Messages'];

        $prompt = $method->invoke($this->service, $transcript, $existingSeries);

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
        $prompt = $method->invoke($this->service, $transcript, []);
        $this->assertStringContainsString('None available', $prompt);
    }

    #[Test]
    public function it_has_all_required_public_methods(): void
    {
        // Test that all required public methods exist
        $reflection = new \ReflectionClass($this->service);

        $this->assertTrue($reflection->hasMethod('analyzeSermon'));
        $this->assertTrue($reflection->hasMethod('generateTitle'));
        $this->assertTrue($reflection->hasMethod('identifySeries'));
        $this->assertTrue($reflection->hasMethod('extractBiblePassage'));
        $this->assertTrue($reflection->hasMethod('extractSermonPoints'));

        // Test that key private methods exist
        $this->assertTrue($reflection->hasMethod('isNonRetryableError'));
        $this->assertTrue($reflection->hasMethod('validateTranscript'));
        $this->assertTrue($reflection->hasMethod('getExistingSeries'));
        $this->assertTrue($reflection->hasMethod('buildAnalysisPrompt'));
        $this->assertTrue($reflection->hasMethod('validateAndCleanAnalysisData'));
    }

    #[Test]
    public function it_handles_database_errors_when_getting_existing_series(): void
    {
        // Temporarily change the database connection to an invalid one to trigger exception
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getExistingSeries');
        $method->setAccessible(true);

        // Set an invalid database connection that will cause an exception
        $originalConnection = config('database.default');
        config(['database.connections.invalid' => ['driver' => 'invalid']]);
        config(['database.default' => 'invalid']);

        try {
            $result = $method->invoke($this->service);
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            // Restore the original connection
            config(['database.default' => $originalConnection]);
        }
    }

    #[Test]
    public function it_validates_individual_method_calls_with_invalid_transcript(): void
    {
        $invalidTranscript = 'short';

        $this->expectException(Exception::class);
        $this->service->generateTitle($invalidTranscript);

        $this->expectException(Exception::class);
        $this->service->identifySeries($invalidTranscript, []);

        $this->expectException(Exception::class);
        $this->service->extractBiblePassage($invalidTranscript);

        $this->expectException(Exception::class);
        $this->service->extractSermonPoints($invalidTranscript);
    }

    #[Test]
    public function it_handles_analysis_data_with_mixed_types(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAndCleanAnalysisData');
        $method->setAccessible(true);

        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        // Test with mixed data types
        $analysisData = [
            'title' => 123, // Should be converted/handled
            'series' => true, // Should be handled
            'reference' => ['array'], // Should be handled
            'points' => 'string instead of array', // Should be handled
        ];

        $result = $method->invoke($this->service, $analysisData, $transcript);

        $this->assertEquals('123', $result['title']); // Current behavior: numeric values are kept as-is
        $this->assertNull($result['series']); // Invalid series becomes null
        $this->assertNull($result['reference']); // Invalid reference becomes null
        $this->assertEquals(['Main Message'], $result['points']); // Fallback for invalid points
    }
}
