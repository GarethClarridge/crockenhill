<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BritishEnglishConverter;
use App\Services\Sermon\SermonAnalysisValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAnalysisValidatorTest extends TestCase
{
    private SermonAnalysisValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class));
    }

    #[Test]
    public function it_accepts_valid_transcript(): void
    {
        $valid = str_repeat('word ', 25); // 100+ chars, 25 words
        $this->assertTrue($this->validator->validateTranscript($valid));
    }

    #[Test]
    public function it_rejects_transcript_that_is_too_short(): void
    {
        $this->assertFalse($this->validator->validateTranscript(str_repeat('a', 99)));
    }

    #[Test]
    public function it_rejects_transcript_with_too_few_words(): void
    {
        // 19 words but long enough in chars
        $transcript = 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen';
        $this->assertFalse($this->validator->validateTranscript($transcript));
    }

    #[Test]
    public function it_rejects_empty_and_whitespace_transcripts(): void
    {
        $this->assertFalse($this->validator->validateTranscript(''));
        $this->assertFalse($this->validator->validateTranscript('   '));
        $this->assertFalse($this->validator->validateTranscript("\n\t  \n"));
    }

    #[Test]
    public function it_cleans_title_removing_surrounding_quotes(): void
    {
        $this->assertEquals('The Heart of the Gospel', $this->validator->validateAndCleanTitle('"The Heart of the Gospel"'));
        $this->assertEquals('God\'s Love', $this->validator->validateAndCleanTitle('\'God\'s Love\''));
    }

    #[Test]
    public function it_truncates_title_to_12_words(): void
    {
        $long = 'This is a very long sermon title that exceeds the twelve word limit and should be truncated';
        $result = $this->validator->validateAndCleanTitle($long);
        $this->assertLessThanOrEqual(12, count(explode(' ', $result)));
    }

    #[Test]
    public function it_does_not_truncate_long_titles(): void
    {
        $long = 'This is a title exceeding the character limit for sermons';

        $result = $this->validator->validateAndCleanTitle($long);

        $this->assertEquals($long, $result);
    }

    #[Test]
    public function it_detects_titles_exceeding_character_limit(): void
    {
        $this->assertTrue($this->validator->isTitleTooLong(str_repeat('a', 61)));
        $this->assertFalse($this->validator->isTitleTooLong(str_repeat('a', 60)));
        $this->assertFalse($this->validator->isTitleTooLong('Short title'));
    }

    #[Test]
    public function it_returns_untitled_sermon_for_empty_or_too_short_titles(): void
    {
        $this->assertEquals('Untitled sermon', $this->validator->validateAndCleanTitle(''));
        $this->assertEquals('Untitled sermon', $this->validator->validateAndCleanTitle('Hi'));
    }

    #[Test]
    public function it_validates_standard_bible_references(): void
    {
        $this->assertEquals('John 3:16', $this->validator->validateBibleReference('John 3:16'));
        $this->assertEquals('1 John 2:1-5', $this->validator->validateBibleReference('1 John 2:1-5'));
        $this->assertEquals('Romans 8:28-39', $this->validator->validateBibleReference('Romans 8:28-39'));
        $this->assertEquals('2 Corinthians 5:17', $this->validator->validateBibleReference('2 Corinthians 5:17'));
        $this->assertEquals('Psalm 23', $this->validator->validateBibleReference('Psalm 23'));
        $this->assertEquals('Song of Solomon 1:1', $this->validator->validateBibleReference('Song of Solomon 1:1'));
        $this->assertEquals('Song of Songs 2:10', $this->validator->validateBibleReference('Song of Songs 2:10'));
    }

    #[Test]
    public function it_rejects_invalid_bible_references(): void
    {
        $this->assertNull($this->validator->validateBibleReference('Not a reference'));
        $this->assertNull($this->validator->validateBibleReference('Random text'));
        $this->assertNull($this->validator->validateBibleReference(''));
        $this->assertNull($this->validator->validateBibleReference('Book'));
        $this->assertNull($this->validator->validateBibleReference('123'));
    }

    #[Test]
    public function it_rejects_prose_wrapped_around_a_bible_reference(): void
    {
        $this->assertNull($this->validator->validateBibleReference('The passage is John 3:16'));
        $this->assertNull($this->validator->validateBibleReference('Not a reference 3'));
        $this->assertNull($this->validator->validateBibleReference('This sermon expounds Romans 8'));
    }

    #[Test]
    public function it_returns_null_summary_for_empty_or_too_short_input(): void
    {
        $this->assertNull($this->validator->validateAndCleanSummary(''));
        $this->assertNull($this->validator->validateAndCleanSummary('Too short'));
    }

    #[Test]
    public function it_returns_cleaned_summary_for_valid_input(): void
    {
        $summary = 'This is a meaningful summary of the sermon that is long enough to pass validation checks.';
        $result = $this->validator->validateAndCleanSummary($summary);
        $this->assertNotNull($result);
        $this->assertStringContainsString('meaningful', $result);
    }

    #[Test]
    public function it_truncates_summary_to_200_words_and_ends_on_a_period(): void
    {
        // 200 words followed by a 201st word containing a period at the end of the block.
        $words = array_fill(0, 199, 'word');
        $words[] = 'last.';
        $words[] = 'extra';
        $summary = implode(' ', $words);

        $result = $this->validator->validateAndCleanSummary($summary);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('.', $result);
        $this->assertEquals(200, count(explode(' ', $result)));
    }

    #[Test]
    public function it_truncates_summary_to_200_words_and_ends_on_an_exclamation_mark(): void
    {
        $words = array_fill(0, 199, 'word');
        $words[] = 'last!';
        $words[] = 'extra';
        $summary = implode(' ', $words);

        $result = $this->validator->validateAndCleanSummary($summary);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('!', $result);
    }

    #[Test]
    public function it_truncates_summary_to_200_words_and_ends_on_a_question_mark(): void
    {
        $words = array_fill(0, 199, 'word');
        $words[] = 'last?';
        $words[] = 'extra';
        $summary = implode(' ', $words);

        $result = $this->validator->validateAndCleanSummary($summary);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('?', $result);
    }

    #[Test]
    public function it_truncates_summary_to_exactly_200_words_if_no_sentence_boundary_within_threshold(): void
    {
        // Period at word 100 (50% mark), which is < 80% threshold of the 200-word block.
        $words = array_fill(0, 99, 'word');
        $words[] = 'mid.';
        $words = array_merge($words, array_fill(0, 100, 'word'));
        $words[] = 'extra';
        $summary = implode(' ', $words);

        $result = $this->validator->validateAndCleanSummary($summary);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('word', $result);
        $this->assertEquals(200, count(explode(' ', $result)));
    }

    #[Test]
    public function it_truncates_summary_to_exactly_200_words_if_no_sentence_boundary_at_all(): void
    {
        $words = array_fill(0, 210, 'word');
        $summary = implode(' ', $words);

        $result = $this->validator->validateAndCleanSummary($summary);

        $this->assertNotNull($result);
        $this->assertEquals(200, count(explode(' ', $result)));
        $this->assertStringEndsWith('word', $result);
    }

    #[Test]
    public function it_normalises_null_and_none_values_in_analysis_data(): void
    {
        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $result = $this->validator->validateAndCleanAnalysisData([
            'title' => '',
            'series' => 'null',
            'reference' => 'none',
            'points' => [],
        ], $transcript);

        $this->assertEquals('Untitled sermon', $result['title']);
        $this->assertNull($result['series']);
        $this->assertNull($result['reference']);
        $this->assertEquals(['Main Message'], $result['points']);
    }

    #[Test]
    public function it_keeps_valid_analysis_data_intact(): void
    {
        $transcript = 'Sample transcript content for testing purposes with enough words to pass validation.';

        $result = $this->validator->validateAndCleanAnalysisData([
            'title' => 'God\'s Amazing Love',
            'series' => 'John Study',
            'reference' => 'John 3:16-21',
            'points' => ['First point', 'Second point', 'Third point'],
        ], $transcript);

        $this->assertEquals('God\'s Amazing Love', $result['title']);
        $this->assertEquals('John Study', $result['series']);
        $this->assertEquals('John 3:16-21', $result['reference']);
        $this->assertCount(3, $result['points']);
        $this->assertEquals($transcript, $result['transcript']);
    }
}
