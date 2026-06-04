<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BritishEnglishConverter;
use App\Services\Media\Audio\TranscriptFormatterService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioTranscriptionServiceFormattingTest extends TestCase
{
    private TranscriptFormatterService $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new TranscriptFormatterService(app(BritishEnglishConverter::class));
    }

    #[Test]
    public function it_splits_transcript_into_sentences_at_sentence_boundaries(): void
    {
        $transcript = 'The Lord is my shepherd. I shall not want. He leads me beside still waters.';
        $sentences = $this->formatter->splitIntoSentences($transcript);

        $this->assertCount(3, $sentences);
        $this->assertEquals('The Lord is my shepherd.', $sentences[0]);
        $this->assertEquals('I shall not want.', $sentences[1]);
        $this->assertEquals('He leads me beside still waters.', $sentences[2]);
    }

    #[Test]
    public function it_filters_out_short_fragments_from_sentences(): void
    {
        $transcript = 'A real sentence here. OK. Another proper sentence follows.';
        $sentences = $this->formatter->splitIntoSentences($transcript);

        // "OK." is only 3 chars and should be filtered
        foreach ($sentences as $sentence) {
            $this->assertGreaterThan(3, strlen($sentence));
        }
    }

    #[Test]
    public function it_detects_topic_transition_phrases(): void
    {
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
                $this->formatter->shouldStartNewParagraph($sentence, 3),
                "Expected paragraph break for: {$sentence}"
            );
        }
    }

    #[Test]
    public function it_does_not_break_paragraph_before_minimum_sentences(): void
    {
        // Even with a transition phrase, should not break with only 1 sentence
        $this->assertFalse(
            $this->formatter->shouldStartNewParagraph('Now let us consider this.', 1)
        );
    }

    #[Test]
    public function it_detects_bible_reference_at_sentence_start(): void
    {
        // Bible reference pattern: capital word followed by number
        $this->assertTrue(
            $this->formatter->shouldStartNewParagraph('Romans 8 tells us something important.', 3)
        );

        $this->assertTrue(
            $this->formatter->shouldStartNewParagraph('Genesis 1 begins with creation.', 2)
        );
    }

    #[Test]
    public function it_does_not_trigger_paragraph_break_for_regular_sentences(): void
    {
        $regularSentences = [
            'This is just a normal sentence about faith.',
            'He spoke about grace and mercy.',
            'The congregation sang together.',
        ];

        foreach ($regularSentences as $sentence) {
            $this->assertFalse(
                $this->formatter->shouldStartNewParagraph($sentence, 3),
                "Should not break paragraph for: {$sentence}"
            );
        }
    }

    #[Test]
    public function it_groups_sentences_into_paragraphs_with_max_limit(): void
    {
        // Create 12 regular sentences (no transition phrases)
        $sentences = [];
        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "This is regular sentence number {$i} about the topic.";
        }

        $paragraphs = $this->formatter->groupSentencesIntoParagraphs($sentences);

        // Max 8 sentences per paragraph, so 12 sentences should produce at least 2 paragraphs
        $this->assertGreaterThanOrEqual(2, count($paragraphs));
    }

    #[Test]
    public function it_handles_empty_sentence_array(): void
    {
        $paragraphs = $this->formatter->groupSentencesIntoParagraphs([]);

        $this->assertIsArray($paragraphs);
        $this->assertEmpty($paragraphs);
    }

    #[Test]
    public function it_cleans_up_spacing_around_punctuation(): void
    {
        // Fix multiple spaces
        $this->assertStringNotContainsString('  ', $this->formatter->cleanupPunctuation('Hello   world.'));

        // Fix space before punctuation
        $result = $this->formatter->cleanupPunctuation('Hello , world .');
        $this->assertStringNotContainsString(' ,', $result);
    }

    #[Test]
    public function it_adds_final_punctuation_when_missing(): void
    {
        $result = $this->formatter->cleanupPunctuation('This sentence has no ending punctuation');
        $this->assertMatchesRegularExpression('/[.!?]$/', $result);
    }

    #[Test]
    public function it_preserves_existing_final_punctuation(): void
    {
        $this->assertStringEndsWith('.', $this->formatter->cleanupPunctuation('Ends with period.'));
        $this->assertStringEndsWith('!', $this->formatter->cleanupPunctuation('Ends with exclamation!'));
        $this->assertStringEndsWith('?', $this->formatter->cleanupPunctuation('Ends with question?'));
    }

    #[Test]
    public function it_applies_british_english_spelling_via_cleanup_punctuation(): void
    {
        $result = $this->formatter->cleanupPunctuation('The organization recognized the color.');

        // BritishEnglishConverter should convert these
        $this->assertStringContainsString('organis', $result);
        $this->assertStringContainsString('colour', $result);
    }

    #[Test]
    public function it_formats_full_transcript_as_markdown_with_paragraphs(): void
    {
        $longTranscript = 'Good morning everyone. Today we are going to look at the book of Romans. '
            .'This is a wonderful passage about grace. It speaks to us about God\'s love. '
            .'Now let us turn to chapter eight. Here Paul writes about the Spirit. '
            .'The Spirit gives us life and freedom. We are set free from condemnation. '
            .'In conclusion, let me say this. God loves you and has a plan for your life.';

        $result = $this->formatter->formatAsMarkdown($longTranscript);

        // Should have paragraph breaks (double newlines)
        $this->assertStringContainsString("\n\n", $result);

        // Should preserve content
        $this->assertStringContainsString('Romans', $result);
        $this->assertStringContainsString('Spirit', $result);
    }
}
