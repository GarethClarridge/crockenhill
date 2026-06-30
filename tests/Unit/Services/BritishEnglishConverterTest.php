<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BritishEnglishConverter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BritishEnglishConverterTest extends TestCase
{
    private BritishEnglishConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new BritishEnglishConverter;
        Cache::flush();
        Storage::fake();
    }

    #[Test]
    public function it_converts_ize_endings_to_ise(): void
    {
        $this->assertEquals('recognise', $this->converter->convert('recognize'));
        $this->assertEquals('organise', $this->converter->convert('organize'));
        $this->assertEquals('emphasise', $this->converter->convert('emphasize'));
    }

    #[Test]
    public function it_converts_ization_endings_to_isation(): void
    {
        $this->assertEquals('organisation', $this->converter->convert('organization'));
        $this->assertEquals('realisation', $this->converter->convert('realization'));
    }

    #[Test]
    public function it_converts_or_endings_to_our(): void
    {
        $this->assertEquals('colour', $this->converter->convert('color'));
        $this->assertEquals('honour', $this->converter->convert('honor'));
        $this->assertEquals('behaviour', $this->converter->convert('behavior'));
        $this->assertEquals('neighbour', $this->converter->convert('neighbor'));
    }

    #[Test]
    public function it_converts_er_endings_to_re(): void
    {
        $this->assertEquals('centre', $this->converter->convert('center'));
        $this->assertEquals('theatre', $this->converter->convert('theater'));
        $this->assertEquals('metre', $this->converter->convert('meter'));
    }

    #[Test]
    public function it_converts_ense_endings_to_ence(): void
    {
        $this->assertEquals('defence', $this->converter->convert('defense'));
        $this->assertEquals('offence', $this->converter->convert('offense'));
        $this->assertEquals('licence', $this->converter->convert('license'));
    }

    #[Test]
    public function it_converts_catalog_words_to_ogue(): void
    {
        $this->assertEquals('catalogue', $this->converter->convert('catalog'));
        $this->assertEquals('dialogue', $this->converter->convert('dialog'));
    }

    #[Test]
    public function it_converts_yze_to_yse(): void
    {
        $this->assertEquals('analyse', $this->converter->convert('analyze'));
        $this->assertEquals('analysing', $this->converter->convert('analyzing'));
        $this->assertEquals('analysed', $this->converter->convert('analyzed'));
        $this->assertEquals('paralyse', $this->converter->convert('paralyze'));
    }

    #[Test]
    public function it_converts_common_individual_words(): void
    {
        $this->assertEquals('grey', $this->converter->convert('gray'));
        $this->assertEquals('sulphur', $this->converter->convert('sulfur'));
        $this->assertEquals('sceptical', $this->converter->convert('skeptical'));
        $this->assertEquals('plough', $this->converter->convert('plow'));
        $this->assertEquals('mould', $this->converter->convert('mold'));
        $this->assertEquals('pyjamas', $this->converter->convert('pajamas'));
        $this->assertEquals('cosy', $this->converter->convert('cozy'));
        $this->assertEquals('doughnut', $this->converter->convert('donut'));
    }

    #[Test]
    public function it_does_not_convert_capsize(): void
    {
        $this->assertEquals('capsize', $this->converter->convert('capsize'));
    }

    #[Test]
    public function it_does_not_convert_seize(): void
    {
        $this->assertEquals('seize', $this->converter->convert('seize'));
    }

    #[Test]
    public function it_converts_text_with_multiple_american_words(): void
    {
        $text = 'The organization will analyze the color of the theater program.';
        $result = $this->converter->convert($text);

        $this->assertStringContainsString('organisation', $result);
        $this->assertStringContainsString('analyse', $result);
        $this->assertStringContainsString('colour', $result);
        $this->assertStringContainsString('theatre', $result);
    }

    #[Test]
    public function it_returns_already_british_text_unchanged(): void
    {
        $text = 'The colour of the theatre is grey.';
        $this->assertEquals($text, $this->converter->convert($text));
    }

    #[Test]
    public function it_returns_empty_string_unchanged(): void
    {
        $this->assertEquals('', $this->converter->convert(''));
    }

    #[Test]
    public function it_caches_corrections(): void
    {
        Cache::flush();

        // 1. Provide an external word list with a unique mapping
        $wordList = ['testingword' => 'hardenedword'];
        Storage::put('spelling/american-british-words.json', (string) json_encode($wordList));

        // 2. Trigger the load and cache
        $this->converter->convert('testingword');

        // 3. Remove the source file
        Storage::delete('spelling/american-british-words.json');

        // 4. A new instance should still use the cached corrections
        $newConverter = new BritishEnglishConverter;
        $this->assertEquals('hardenedword', $newConverter->convert('testingword'));
    }

    #[Test]
    public function clear_cache_removes_cached_corrections(): void
    {
        Cache::flush();

        // 1. Setup cached external corrections
        $wordList = ['testingword' => 'hardenedword'];
        Storage::put('spelling/american-british-words.json', (string) json_encode($wordList));
        $this->converter->convert('testingword');
        Storage::delete('spelling/american-british-words.json');

        // 2. Verify it is currently cached
        $this->assertEquals('hardenedword', (new BritishEnglishConverter)->convert('testingword'));

        // 3. Clear the cache
        $this->converter->clearCache();

        // 4. A new instance should now fall back to built-in (or original text if no match)
        $newConverter = new BritishEnglishConverter;
        $this->assertEquals('testingword', $newConverter->convert('testingword'));
    }

    #[Test]
    public function get_stats_returns_expected_structure(): void
    {
        $stats = $this->converter->getStats();

        $this->assertArrayHasKey('total_patterns', $stats);
        $this->assertArrayHasKey('cache_key', $stats);
        $this->assertArrayHasKey('cache_ttl', $stats);
        $this->assertArrayHasKey('external_wordlist_available', $stats);

        $this->assertGreaterThan(0, $stats['total_patterns']);
        $this->assertGreaterThan(0, $stats['cache_ttl']);
        $this->assertNotEmpty($stats['cache_key']);
        $this->assertFalse($stats['external_wordlist_available']);
    }

    #[Test]
    public function it_uses_external_word_list_when_available(): void
    {
        Cache::flush();

        $wordList = ['color' => 'colour', 'organize' => 'organise'];
        Storage::put('spelling/american-british-words.json', (string) json_encode($wordList));

        $result = $this->converter->convert('color');
        $this->assertEquals('colour', $result);
    }

    #[Test]
    public function get_stats_reports_external_wordlist_available(): void
    {
        Cache::flush();

        $wordList = ['color' => 'colour'];
        Storage::put('spelling/american-british-words.json', (string) json_encode($wordList));

        $stats = $this->converter->getStats();

        $this->assertTrue($stats['external_wordlist_available']);
    }
}
