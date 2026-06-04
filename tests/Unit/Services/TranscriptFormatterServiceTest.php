<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\TranscriptFormatterService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptFormatterServiceTest extends TestCase
{
    private TranscriptFormatterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TranscriptFormatterService::class);
    }

    // ── formatAsMarkdown ──────────────────────────────────────────────────────

    #[Test]
    public function it_returns_an_empty_string_for_blank_input(): void
    {
        $result = $this->service->formatAsMarkdown('');

        $this->assertSame('', $result);
    }

    #[Test]
    public function it_formats_a_single_sentence_as_a_paragraph(): void
    {
        $result = $this->service->formatAsMarkdown('This is a single sentence.');

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('This is a single sentence', $result);
    }

    #[Test]
    public function it_separates_paragraphs_with_double_newlines(): void
    {
        $transcript = 'This is the first sentence. This is the second sentence. Now let me move on. This is a new topic starting here.';

        $result = $this->service->formatAsMarkdown($transcript);

        $this->assertStringContainsString("\n\n", $result);
    }

    #[Test]
    public function it_trims_leading_and_trailing_whitespace_from_input(): void
    {
        $result = $this->service->formatAsMarkdown('   Hello world.   ');

        $this->assertStringNotContainsString('   ', $result);
    }

    // ── splitIntoSentences ────────────────────────────────────────────────────

    #[Test]
    public function it_splits_sentences_on_capital_letter_after_punctuation(): void
    {
        $sentences = $this->service->splitIntoSentences('First sentence. Second sentence. Third sentence.');

        $this->assertCount(3, $sentences);
        $this->assertSame('First sentence.', $sentences[0]);
        $this->assertSame('Second sentence.', $sentences[1]);
    }

    #[Test]
    public function it_filters_out_very_short_fragments(): void
    {
        $sentences = $this->service->splitIntoSentences('OK. This is a proper sentence. Yes.');

        // "OK." and "Yes." are ≤3 chars and should be filtered
        foreach ($sentences as $sentence) {
            $this->assertGreaterThan(3, strlen($sentence));
        }
    }

    #[Test]
    public function it_returns_empty_array_for_empty_input(): void
    {
        $sentences = $this->service->splitIntoSentences('');

        $this->assertSame([], $sentences);
    }

    // ── groupSentencesIntoParagraphs ──────────────────────────────────────────

    #[Test]
    public function it_returns_empty_array_when_given_no_sentences(): void
    {
        $result = $this->service->groupSentencesIntoParagraphs([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_breaks_into_a_new_paragraph_on_topic_transition_words(): void
    {
        // Transition phrases are only evaluated when sentenceCount >= 2.
        // Sentence 1 and 2 accumulate in paragraph 1. Sentence 3 ("Now...") at
        // sentenceCount=3 triggers a break → paragraph 1 closes. Sentence 4 becomes paragraph 2.
        $sentences = [
            'This is the first sentence in the paragraph.',
            'This is the second sentence in the paragraph.',
            'Now let me explain something very important here today.',
            'This is the start of the second paragraph now.',
        ];

        $paragraphs = $this->service->groupSentencesIntoParagraphs($sentences);

        $this->assertCount(2, $paragraphs);
    }

    #[Test]
    public function it_caps_paragraphs_at_eight_sentences(): void
    {
        $sentences = array_fill(0, 9, 'This is a filler sentence here.');

        $paragraphs = $this->service->groupSentencesIntoParagraphs($sentences);

        // First paragraph should hold exactly 8 sentences, second holds 1
        $this->assertCount(2, $paragraphs);
    }

    // ── shouldStartNewParagraph ───────────────────────────────────────────────

    #[Test]
    public function it_does_not_break_before_at_least_two_sentences(): void
    {
        $result = $this->service->shouldStartNewParagraph('Now let me turn to the next point.', 1);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_breaks_on_sermon_transition_phrases(): void
    {
        $this->assertTrue($this->service->shouldStartNewParagraph('Now let me turn to the passage.', 3));
        $this->assertTrue($this->service->shouldStartNewParagraph('Firstly, we see that God is love.', 2));
        $this->assertTrue($this->service->shouldStartNewParagraph('Finally, let us consider this.', 4));
    }

    #[Test]
    public function it_breaks_on_bible_reference_at_start_of_sentence(): void
    {
        $result = $this->service->shouldStartNewParagraph('Romans 8 tells us that nothing can separate us.', 2);

        $this->assertTrue($result);
    }

    // ── cleanupPunctuation ────────────────────────────────────────────────────

    #[Test]
    public function it_collapses_multiple_spaces_to_one(): void
    {
        $result = $this->service->cleanupPunctuation('This  has   extra   spaces.');

        $this->assertSame('This has extra spaces.', $result);
    }

    #[Test]
    public function it_removes_space_before_punctuation(): void
    {
        $result = $this->service->cleanupPunctuation('Hello , world .');

        $this->assertStringNotContainsString(' ,', $result);
        $this->assertStringNotContainsString(' .', $result);
    }

    #[Test]
    public function it_appends_a_period_when_no_terminal_punctuation_exists(): void
    {
        $result = $this->service->cleanupPunctuation('This sentence has no punctuation');

        $this->assertStringEndsWith('.', $result);
    }

    #[Test]
    public function it_does_not_double_punctuate_a_sentence_ending_with_a_question_mark(): void
    {
        $result = $this->service->cleanupPunctuation('Is this correct?');

        $this->assertStringEndsWith('?', $result);
        $this->assertStringNotContainsString('?.', $result);
    }
}
