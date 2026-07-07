<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ChurchService\ReadingReferenceExtractor;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use TechWilk\BibleVerseParser\BiblePassageParser;
use Tests\TestCase;

class ReadingReferenceExtractorTest extends TestCase
{
    private function resolver(): ScriptureReferenceResolver
    {
        return new ScriptureReferenceResolver(new BiblePassageParser);
    }

    /**
     * An extractor whose model response is injected, bypassing the live OpenAI call so the
     * normalize + confidence-gate logic can be exercised deterministically.
     *
     * @param  array{reference: string|null, confidence: float}  $modelResponse
     */
    private function extractorReturning(array $modelResponse): ReadingReferenceExtractor
    {
        Config::set('media-processing.analysis.service', 'openai');

        return new class($this->resolver(), $modelResponse) extends ReadingReferenceExtractor
        {
            /**
             * @param  array{reference: string|null, confidence: float}  $modelResponse
             */
            public function __construct(ScriptureReferenceResolver $resolver, private array $modelResponse)
            {
                parent::__construct($resolver);
            }

            protected function requestReference(string $transcript): array
            {
                return $this->modelResponse;
            }
        };
    }

    // ── Model-inference path (named from content) ─────────────────────────────

    #[Test]
    public function it_normalizes_a_model_named_psalm(): void
    {
        $result = $this->extractorReturning(['reference' => 'Psalm 148', 'confidence' => 0.95])
            ->extract('Praise the Lord from the heavens, praise him in the heights above.');

        $this->assertSame('Psalm 148', $result['reference']);
        $this->assertSame('transcript_ai', $result['source']);
        $this->assertSame(0.95, $result['confidence']);
    }

    #[Test]
    public function it_normalizes_a_model_named_narrative_passage(): void
    {
        $result = $this->extractorReturning(['reference' => 'Joshua 2:1-24', 'confidence' => 0.9])
            ->extract('Then Joshua son of Nun secretly sent two spies from Shittim.');

        $this->assertNotNull($result['reference']);
        $this->assertStringContainsString('Joshua', (string) $result['reference']);
        $this->assertStringContainsString('2', (string) $result['reference']);
        $this->assertSame('transcript_ai', $result['source']);
    }

    #[Test]
    public function it_preserves_every_subrange_of_a_multi_part_reading(): void
    {
        // normalize() would truncate this to its first subrange.
        $result = $this->extractorReturning(['reference' => 'Luke 18:31-33, 35-43', 'confidence' => 0.9])
            ->extract('And taking the twelve, he said to them, we are going up to Jerusalem.');

        $this->assertSame('Luke 18:31-33, Luke 18:35-43', $result['reference']);
        $this->assertSame('transcript_ai', $result['source']);
    }

    #[Test]
    public function it_returns_none_when_the_model_declines_for_a_benediction(): void
    {
        $result = $this->extractorReturning(['reference' => null, 'confidence' => 0.2])
            ->extract('May the grace of our Lord Jesus Christ be with you all, now and for evermore.');

        $this->assertNull($result['reference']);
        $this->assertSame('none', $result['source']);
    }

    #[Test]
    public function it_returns_none_when_the_model_output_is_unparseable(): void
    {
        $result = $this->extractorReturning(['reference' => 'the book of made-up things 99', 'confidence' => 0.9])
            ->extract('Some recited words.');

        $this->assertNull($result['reference']);
        $this->assertSame('none', $result['source']);
        $this->assertSame('the book of made-up things 99', $result['raw']);
    }

    #[Test]
    public function it_rejects_a_reference_below_the_confidence_threshold(): void
    {
        Config::set('media-processing.reading_references.min_confidence', 0.6);

        $result = $this->extractorReturning(['reference' => 'Romans 8:28', 'confidence' => 0.3])
            ->extract('All things work together for good.');

        $this->assertNull($result['reference']);
        $this->assertSame('none', $result['source']);
    }

    // ── Mock path (only explicit references) ──────────────────────────────────

    #[Test]
    public function the_mock_service_extracts_an_explicitly_named_reference(): void
    {
        Config::set('media-processing.analysis.service', 'mock');

        $extractor = new ReadingReferenceExtractor($this->resolver());

        $result = $extractor->extract('Our reading this morning is from Joshua chapter 2.');

        $this->assertNotNull($result['reference']);
        $this->assertStringContainsString('Joshua', (string) $result['reference']);
        $this->assertSame('transcript_ai', $result['source']);
    }

    #[Test]
    public function the_mock_service_preserves_a_multi_part_reference(): void
    {
        Config::set('media-processing.analysis.service', 'mock');

        $extractor = new ReadingReferenceExtractor($this->resolver());

        $result = $extractor->extract('Our reading is Luke 18:31-33, 35-43. Please stand as we read together.');

        // The scanner must keep both subranges, matching the live model path.
        $this->assertSame('Luke 18:31-33, Luke 18:35-43', $result['reference']);
        $this->assertSame('transcript_ai', $result['source']);
    }

    #[Test]
    public function the_mock_service_returns_none_for_an_unannounced_reading(): void
    {
        Config::set('media-processing.analysis.service', 'mock');

        $extractor = new ReadingReferenceExtractor($this->resolver());

        $result = $extractor->extract('May the grace of our Lord be with you all.');

        $this->assertNull($result['reference']);
        $this->assertSame('none', $result['source']);
    }

    #[Test]
    public function it_returns_none_for_an_empty_transcript(): void
    {
        $extractor = new ReadingReferenceExtractor($this->resolver());

        $result = $extractor->extract('   ');

        $this->assertNull($result['reference']);
        $this->assertSame('none', $result['source']);
    }
}
