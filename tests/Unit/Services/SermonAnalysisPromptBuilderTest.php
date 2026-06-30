<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BritishEnglishConverter;
use App\Services\Sermon\SermonAnalysisPromptBuilder;
use App\Services\Sermon\SermonAnalysisValidator;
use PHPUnit\Framework\Attributes\Test;
use TechWilk\BibleVerseParser\BiblePassageParser;
use Tests\TestCase;

class SermonAnalysisPromptBuilderTest extends TestCase
{
    private SermonAnalysisPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), new BiblePassageParser);
        $this->builder = new SermonAnalysisPromptBuilder($validator);
    }

    #[Test]
    public function it_includes_series_and_transcript_in_prompt(): void
    {
        $transcript = 'This is a sample sermon transcript about God\'s love and grace.';
        $existingSeries = ['John Study', 'Romans Study', 'Christmas Messages'];

        $prompt = $this->builder->buildAnalysisPrompt($transcript, $existingSeries);

        $this->assertStringContainsString('John Study', $prompt);
        $this->assertStringContainsString('Romans Study', $prompt);
        $this->assertStringContainsString('Christmas Messages', $prompt);
        $this->assertStringContainsString($transcript, $prompt);
    }

    #[Test]
    public function it_includes_required_json_fields_in_prompt(): void
    {
        $prompt = $this->builder->buildAnalysisPrompt('Sample transcript content here.', []);

        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('title', $prompt);
        $this->assertStringContainsString('maximum 12 words and 60 characters', $prompt);
        $this->assertStringContainsString('series', $prompt);
        $this->assertStringContainsString('reference', $prompt);
        $this->assertStringContainsString('points', $prompt);
    }

    #[Test]
    public function it_shows_none_available_when_no_series_provided(): void
    {
        $prompt = $this->builder->buildAnalysisPrompt('Sample transcript content here.', []);
        $this->assertStringContainsString('None available', $prompt);
    }

    #[Test]
    public function it_generates_fallback_title_from_meaningful_words(): void
    {
        $transcript = 'The grace of our Lord Jesus Christ is sufficient for all our needs and troubles in this life.';
        $result = $this->builder->generateFallbackTitle($transcript);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('grace', strtolower($result));
    }

    #[Test]
    public function it_falls_back_to_date_when_no_meaningful_words_found(): void
    {
        $transcript = 'Good morning welcome today we are going to look at';
        $result = $this->builder->generateFallbackTitle($transcript);

        $this->assertStringContainsString('Sermon - ', $result);
    }

    #[Test]
    public function it_does_not_fall_back_to_date_when_meaningful_words_present(): void
    {
        $transcript = 'Good morning everyone. Today we are going to explore the wonderful truth about God\'s love and mercy in the book of John.';
        $result = $this->builder->generateFallbackTitle($transcript);

        $this->assertNotEmpty($result);
        $this->assertNotEquals('Sermon - '.date('F j, Y'), $result);
    }
}
