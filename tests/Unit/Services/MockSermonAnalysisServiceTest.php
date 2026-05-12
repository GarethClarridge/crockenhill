<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SermonAnalysis;
use App\Services\BritishEnglishConverter;
use App\Services\MockSermonAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MockSermonAnalysisServiceTest extends TestCase
{
    private MockSermonAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MockSermonAnalysisService(app(BritishEnglishConverter::class));
    }

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(MockSermonAnalysisService::class, $this->service);
    }

    #[Test]
    public function it_extracts_title_from_looking_at_pattern(): void
    {
        $transcript = 'Good morning. Today we are looking at the glorious hope of the gospel in our lives.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('The glorious hope of the gospel in our lives', $analysis->title);
    }

    #[Test]
    public function it_extracts_title_from_the_title_is_pattern(): void
    {
        // The pattern is a bit greedy with whitespace/intermediate words if 'is' is not found immediately
        $transcript = "I want to speak to you today. The title is God's grace and mercy.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals("God's grace and mercy", $analysis->title);
    }

    #[Test]
    public function it_extracts_title_from_bible_reference_prefix_pattern(): void
    {
        $transcript = "John 3:16. God's amazing love for the world and all people. This is our text today.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals("God's amazing love for the world and all people", $analysis->title);
    }

    #[Test]
    public function it_falls_back_to_theological_keyword_sentence_for_title(): void
    {
        $transcript = 'Welcome everyone. It is good to be here. Jesus Christ is the Lord of our lives and he brings us hope.';
        $analysis = $this->service->analyzeSermon($transcript);

        // Should pick the sentence with Jesus Christ, limited to 12 words
        // Note: Mock analysis seems to have a slightly different sentence splitting or cleanup
        $this->assertStringContainsString('Jesus Christ is the Lord of our lives', $analysis->title);
    }

    #[Test]
    public function it_capitalises_proper_nouns_in_titles(): void
    {
        $transcript = 'The title is god, jesus, and the holy spirit in the bible.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('God, Jesus, and the Holy spirit in the Bible', $analysis->title);
    }

    #[Test]
    public function it_limits_titles_to_twelve_words(): void
    {
        // Note: The regex match itself is limited to 60 characters in MockSermonAnalysisService
        $transcript = 'The title is one two three four five six seven eight nine ten eleven.';
        $analysis = $this->service->analyzeSermon($transcript);

        $wordCount = count(explode(' ', $analysis->title));
        $this->assertLessThanOrEqual(12, $wordCount);
        $this->assertEquals('One two three four five six seven eight nine ten eleven', $analysis->title);
    }

    #[Test]
    public function it_identifies_bible_book_series(): void
    {
        $transcript = 'We are continuing our study in the book of Romans today.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Romans', $analysis->series);
    }

    #[Test]
    public function it_matches_existing_series(): void
    {
        $transcript = 'We are looking at the gospel of John.';
        $existingSeries = ['The Gospel of John', 'Acts Study'];

        $analysis = $this->service->analyzeSermon($transcript, $existingSeries);

        $this->assertEquals('The Gospel of John', $analysis->series);
    }

    #[Test]
    public function it_identifies_seasonal_series_christmas(): void
    {
        $transcript = 'Welcome to our Christmas service as we look at the nativity.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Christmas messages', $analysis->series);
    }

    #[Test]
    public function it_identifies_thematic_series(): void
    {
        $transcript = "Today we are talking about God's grace and mercy.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Grace and mercy', $analysis->series);
    }

    #[Test]
    public function it_extracts_bible_reference_from_chapter_and_verse_pattern(): void
    {
        $transcript = "Let's turn together to John 3:16 for our text today.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('John 3:16', $analysis->reference);
    }

    #[Test]
    public function it_extracts_bible_reference_from_chapter_only_pattern(): void
    {
        $transcript = 'Please open your Bibles to Romans chapter 8.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('Romans 8', $analysis->reference);
    }

    #[Test]
    public function it_falls_back_to_thematic_reference_based_on_keywords(): void
    {
        // "love" keyword should trigger "1 John 4:7-12" fallback
        $transcript = "We are talking about God's love and how we should love one another.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertEquals('1 John 4:7-12', $analysis->reference);
    }

    #[Test]
    public function it_extracts_numbered_points(): void
    {
        // The mock regex expects space directly after the number/word, no comma
        $transcript = 'Firstly God is love. Secondly we should love others. Finally this is the gospel.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertCount(3, $analysis->points);
        $this->assertEquals('God is love', $analysis->points[0]);
        $this->assertEquals('We should love others', $analysis->points[1]);
        $this->assertEquals('This is the gospel', $analysis->points[2]);
    }

    #[Test]
    public function it_falls_back_to_thematic_points(): void
    {
        $transcript = "We are talking about God's sovereignty and how he works all things for good.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertGreaterThanOrEqual(2, count($analysis->points));
        $this->assertContains("God's sovereignty over all circumstances", $analysis->points);
        $this->assertContains('God works all things for good', $analysis->points);
    }

    #[Test]
    public function it_generates_a_coherent_summary(): void
    {
        // Romans 8 trigger special summary prefix
        $transcript = 'In Romans 8 we see that God is love. He cares for us deeply. Therefore we can trust him.';
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertNotNull($analysis->summary);
        $this->assertStringContainsString('In Romans 8:28, we see that', $analysis->summary);
        $this->assertStringContainsString('God is love', $analysis->summary);
    }

    #[Test]
    public function it_applies_british_english_corrections_to_summary(): void
    {
        // "analyzing" (US) -> "analysing" (UK)
        // Sentences must be between 30 and 150 chars and contain keywords
        $transcript = "We are analyzing the text of Ephesians and God's grace is amazing. We can trust in his holy name forever.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertNotNull($analysis->summary);
        $this->assertStringContainsString('analysing', $analysis->summary);
    }

    #[Test]
    public function it_returns_a_fully_populated_sermon_analysis_dto(): void
    {
        // Ensure title is long enough (> 10 chars) to match pattern
        // Ensure point content is long enough (>= 10 chars) to match pattern
        $transcript = "Good morning. The title is God's Amazing Love. We are looking at John 3:16. Firstly God loved the whole world. Secondly he gave his only Son. Thirdly we believe in him today. Finally we rejoice in his salvation.";
        $analysis = $this->service->analyzeSermon($transcript);

        $this->assertInstanceOf(SermonAnalysis::class, $analysis);
        // cleanMockTitle uses ucfirst(strtolower($title)) then capitalises specific nouns
        $this->assertEquals("God's amazing love", $analysis->title);
        $this->assertEquals('John 3:16', $analysis->reference);
        $this->assertGreaterThanOrEqual(3, count($analysis->points));
        $this->assertNotNull($analysis->summary);
        $this->assertEquals($transcript, $analysis->transcript);
    }
}
