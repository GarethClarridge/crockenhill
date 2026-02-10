<?php

namespace Tests\Unit\Services;

use App\Services\AudioChunkingService;
use App\Services\AudioTranscriptionService;
use App\Services\BritishEnglishConverter;
use App\Services\MediaProcessingLogger;
use App\Services\TranscriptStorageService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioTranscriptionServiceFormattingTest extends TestCase
{
    private AudioTranscriptionService $service;

    private MediaProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.transcription.openai_api_key', 'test-key');
        Config::set('openai.api_key', 'test-key');
        Config::set('media-processing.storage.sermon_disk', 'local');

        $this->logger = Mockery::mock(MediaProcessingLogger::class);
        $this->logger->shouldReceive('logProcessingStep')->andReturn(true);
        $this->logger->shouldReceive('logFileOperation')->andReturn(true);
        $this->logger->shouldReceive('logApiCall')->andReturn(true);
        $this->logger->shouldReceive('logError')->andReturn(true);

        $storageService = app(TranscriptStorageService::class);
        $converter = app(BritishEnglishConverter::class);
        $chunkingService = new AudioChunkingService($this->logger);
        $this->service = new AudioTranscriptionService($this->logger, $storageService, $converter, $chunkingService);
    }

    #[Test]
    public function it_splits_transcript_into_sentences_at_sentence_boundaries(): void
    {
        $method = $this->getPrivateMethod('splitIntoSentences');

        $transcript = 'The Lord is my shepherd. I shall not want. He leads me beside still waters.';
        $sentences = $method->invoke($this->service, $transcript);

        $this->assertCount(3, $sentences);
        $this->assertEquals('The Lord is my shepherd.', $sentences[0]);
        $this->assertEquals('I shall not want.', $sentences[1]);
        $this->assertEquals('He leads me beside still waters.', $sentences[2]);
    }

    #[Test]
    public function it_filters_out_short_fragments_from_sentences(): void
    {
        $method = $this->getPrivateMethod('splitIntoSentences');

        $transcript = 'A real sentence here. OK. Another proper sentence follows.';
        $sentences = $method->invoke($this->service, $transcript);

        // "OK." is only 3 chars and should be filtered
        foreach ($sentences as $sentence) {
            $this->assertGreaterThan(3, strlen($sentence));
        }
    }

    #[Test]
    public function it_detects_topic_transition_phrases(): void
    {
        $method = $this->getPrivateMethod('shouldStartNewParagraph');

        // Should trigger paragraph breaks (with sentenceCount >= 2)
        $transitions = [
            'Now let us consider the second point.',
            'So what does this mean for us?',
            'Firstly, we need to understand grace.',
            'Turn with me to Romans chapter 8.',
            'The first thing I want to say is this.',
            'In conclusion, let me remind you.',
            'Let me draw your attention to verse 5.',
        ];

        foreach ($transitions as $sentence) {
            $this->assertTrue(
                $method->invoke($this->service, $sentence, 3),
                "Expected paragraph break for: {$sentence}"
            );
        }
    }

    #[Test]
    public function it_does_not_break_paragraph_before_minimum_sentences(): void
    {
        $method = $this->getPrivateMethod('shouldStartNewParagraph');

        // Even with a transition phrase, should not break with only 1 sentence
        $this->assertFalse(
            $method->invoke($this->service, 'Now let us consider this.', 1)
        );
    }

    #[Test]
    public function it_detects_bible_reference_at_sentence_start(): void
    {
        $method = $this->getPrivateMethod('shouldStartNewParagraph');

        // Bible reference pattern: capital word followed by number
        $this->assertTrue(
            $method->invoke($this->service, 'Romans 8 tells us something important.', 3)
        );

        $this->assertTrue(
            $method->invoke($this->service, 'Genesis 1 begins with creation.', 2)
        );
    }

    #[Test]
    public function it_does_not_trigger_paragraph_break_for_regular_sentences(): void
    {
        $method = $this->getPrivateMethod('shouldStartNewParagraph');

        $regularSentences = [
            'This is just a normal sentence about faith.',
            'He spoke about grace and mercy.',
            'The congregation sang together.',
        ];

        foreach ($regularSentences as $sentence) {
            $this->assertFalse(
                $method->invoke($this->service, $sentence, 3),
                "Should not break paragraph for: {$sentence}"
            );
        }
    }

    #[Test]
    public function it_groups_sentences_into_paragraphs_with_max_limit(): void
    {
        $method = $this->getPrivateMethod('groupSentencesIntoParagraphs');

        // Create 12 regular sentences (no transition phrases)
        $sentences = [];
        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "This is regular sentence number {$i} about the topic.";
        }

        $paragraphs = $method->invoke($this->service, $sentences);

        // Max 8 sentences per paragraph, so 12 sentences should produce at least 2 paragraphs
        $this->assertGreaterThanOrEqual(2, count($paragraphs));
    }

    #[Test]
    public function it_handles_empty_sentence_array(): void
    {
        $method = $this->getPrivateMethod('groupSentencesIntoParagraphs');

        $paragraphs = $method->invoke($this->service, []);

        $this->assertIsArray($paragraphs);
        $this->assertEmpty($paragraphs);
    }

    #[Test]
    public function it_cleans_up_spacing_around_punctuation(): void
    {
        $method = $this->getPrivateMethod('cleanupPunctuation');

        // Fix multiple spaces
        $this->assertStringNotContainsString('  ', $method->invoke($this->service, 'Hello   world.'));

        // Fix space before punctuation
        $result = $method->invoke($this->service, 'Hello , world .');
        $this->assertStringNotContainsString(' ,', $result);
    }

    #[Test]
    public function it_adds_final_punctuation_when_missing(): void
    {
        $method = $this->getPrivateMethod('cleanupPunctuation');

        $result = $method->invoke($this->service, 'This sentence has no ending punctuation');
        $this->assertMatchesRegularExpression('/[.!?]$/', $result);
    }

    #[Test]
    public function it_preserves_existing_final_punctuation(): void
    {
        $method = $this->getPrivateMethod('cleanupPunctuation');

        $this->assertStringEndsWith('.', $method->invoke($this->service, 'Ends with period.'));
        $this->assertStringEndsWith('!', $method->invoke($this->service, 'Ends with exclamation!'));
        $this->assertStringEndsWith('?', $method->invoke($this->service, 'Ends with question?'));
    }

    #[Test]
    public function it_applies_british_english_spelling(): void
    {
        $method = $this->getPrivateMethod('applyBritishEnglishSpelling');

        $result = $method->invoke($this->service, 'The organization recognized the color.');

        // BritishEnglishConverter should convert these
        $this->assertStringContainsString('organis', $result);
        $this->assertStringContainsString('colour', $result);
    }

    #[Test]
    public function it_formats_full_transcript_as_markdown_with_paragraphs(): void
    {
        $method = $this->getPrivateMethod('formatAsMarkdown');

        $longTranscript = 'Good morning everyone. Today we are going to look at the book of Romans. '
            .'This is a wonderful passage about grace. It speaks to us about God\'s love. '
            .'Now let us turn to chapter eight. Here Paul writes about the Spirit. '
            .'The Spirit gives us life and freedom. We are set free from condemnation. '
            .'In conclusion, let me say this. God loves you and has a plan for your life.';

        $result = $method->invoke($this->service, $longTranscript);

        // Should have paragraph breaks (double newlines)
        $this->assertStringContainsString("\n\n", $result);

        // Should preserve content
        $this->assertStringContainsString('Romans', $result);
        $this->assertStringContainsString('Spirit', $result);
    }

    #[Test]
    public function it_identifies_non_retryable_error_codes(): void
    {
        $method = $this->getPrivateMethod('isNonRetryableError');

        // Note: OpenAI's ErrorException extends Exception but parent::__construct
        // is called without a code, so getCode() always returns 0.
        // This means isNonRetryableError() will always return false with the current
        // implementation. We test the actual behaviour here to capture the baseline
        // before the service extraction refactor.
        $nonRetryableCodes = [400, 401, 413];
        foreach ($nonRetryableCodes as $code) {
            $exception = new \OpenAI\Exceptions\ErrorException([
                'message' => 'Test error',
                'type' => 'test_error',
                'code' => (string) $code,
            ], $code);

            // getCode() returns 0 because ErrorException doesn't pass code to parent
            $this->assertEquals(0, $exception->getCode());
            // So isNonRetryableError always returns false
            $this->assertFalse($method->invoke($this->service, $exception));
        }
    }

    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
