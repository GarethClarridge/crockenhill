<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Song\OpenLpLyricsParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenLpLyricsParserTest extends TestCase
{
    private OpenLpLyricsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OpenLpLyricsParser;
    }

    #[Test]
    public function it_extracts_verse_text_in_order(): void
    {
        $lyricsXml = <<<'XML'
<song>
  <lyrics>
    <verse type="v" label="1">Verse one line one
Verse one line two</verse>
    <verse type="c" label="1">Chorus line</verse>
  </lyrics>
</song>
XML;

        $result = $this->parser->parse($lyricsXml);

        $this->assertSame("Verse one line one\nVerse one line two\n\nChorus line", $result['lyrics_plain']);
        $this->assertSame([], $result['warnings']);
    }

    #[Test]
    public function it_applies_verse_order_when_present(): void
    {
        $lyricsXml = <<<'XML'
<song>
  <lyrics>
    <verse type="v" label="1">Verse one</verse>
    <verse type="c" label="1">Chorus one</verse>
    <verse type="v" label="2">Verse two</verse>
  </lyrics>
</song>
XML;

        $result = $this->parser->parse($lyricsXml, 'c1 v1 c1 v2');

        $this->assertSame("Chorus one\n\nVerse one\n\nChorus one\n\nVerse two", $result['lyrics_plain']);
        $this->assertSame([], $result['warnings']);
    }

    #[Test]
    public function it_warns_when_verse_order_references_missing_verses_and_appends_unreferenced_verses(): void
    {
        $lyricsXml = <<<'XML'
<song>
  <lyrics>
    <verse type="v" label="1">Verse one</verse>
    <verse type="c" label="1">Chorus one</verse>
    <verse type="v" label="2">Verse two</verse>
  </lyrics>
</song>
XML;

        $result = $this->parser->parse($lyricsXml, 'c1 b1');

        $this->assertSame("Chorus one\n\nVerse one\n\nVerse two", $result['lyrics_plain']);
        $this->assertSame(['Verse order referenced missing verse keys: b1.'], $result['warnings']);
    }

    #[Test]
    public function it_returns_warning_for_invalid_xml(): void
    {
        $result = $this->parser->parse('<song><lyrics><verse>Broken');

        $this->assertNull($result['lyrics_plain']);
        $this->assertSame(['Lyrics XML could not be parsed.'], $result['warnings']);
    }

    #[Test]
    public function it_falls_back_to_document_text_when_no_verse_nodes_exist(): void
    {
        $result = $this->parser->parse('<song><lyrics><p>Fallback body text</p></lyrics></song>');

        $this->assertSame('Fallback body text', $result['lyrics_plain']);
        $this->assertSame(['No verse nodes found in lyrics XML; used fallback text extraction.'], $result['warnings']);
    }
}
