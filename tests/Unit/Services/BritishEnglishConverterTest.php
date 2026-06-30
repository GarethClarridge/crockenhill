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
    public function it_does_not_convert_small_ize_words_like_size(): void
    {
        $this->assertEquals('size', $this->converter->convert('size'));
        $this->assertEquals('resize', $this->converter->convert('resize'));
        $this->assertEquals('oversize', $this->converter->convert('oversize'));
        $this->assertEquals('capsized', $this->converter->convert('capsized'));
        $this->assertEquals('prizes', $this->converter->convert('prizes'));
    }

    #[Test]
    public function it_does_not_misconvert_er_words_like_smiter(): void
    {
        $this->assertEquals('smiter', $this->converter->convert('smiter'));
        $this->assertEquals('limiter', $this->converter->convert('limiter'));
        $this->assertEquals('bitter', $this->converter->convert('bitter'));
    }

    #[Test]
    public function it_converts_savior_to_saviour(): void
    {
        $this->assertEquals('saviour', $this->converter->convert('savior'));
        $this->assertEquals('Saviour', $this->converter->convert('Savior'));
    }

    #[Test]
    public function it_converts_compound_er_words_to_re(): void
    {
        $this->assertEquals('kilometre', $this->converter->convert('kilometer'));
        $this->assertEquals('millimetre', $this->converter->convert('millimeter'));
        $this->assertEquals('epicentre', $this->converter->convert('epicenter'));
        $this->assertEquals('amphitheatre', $this->converter->convert('amphitheater'));
        $this->assertEquals('nanometre', $this->converter->convert('nanometer'));
    }

    #[Test]
    public function it_leaves_measurement_devices_ending_in_meter_alone(): void
    {
        $this->assertEquals('thermometer', $this->converter->convert('thermometer'));
        $this->assertEquals('barometer', $this->converter->convert('barometer'));
        $this->assertEquals('parameter', $this->converter->convert('parameter'));
        $this->assertEquals('diameter', $this->converter->convert('diameter'));
        $this->assertEquals('perimeter', $this->converter->convert('perimeter'));
        $this->assertEquals('altimeter', $this->converter->convert('altimeter'));
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

        $this->assertFalse(Cache::has('british_english_corrections'));

        $this->converter->convert('color');

        $this->assertTrue(Cache::has('british_english_corrections'));
    }

    #[Test]
    public function clear_cache_removes_cached_corrections(): void
    {
        $this->converter->convert('color');
        $this->assertTrue(Cache::has('british_english_corrections'));

        $this->converter->clearCache();

        $this->assertFalse(Cache::has('british_english_corrections'));
    }

    #[Test]
    public function get_stats_returns_expected_structure(): void
    {
        Storage::fake();
        $stats = $this->converter->getStats();

        $this->assertArrayHasKey('total_patterns', $stats);
        $this->assertArrayHasKey('cache_key', $stats);
        $this->assertArrayHasKey('cache_ttl', $stats);
        $this->assertArrayHasKey('external_wordlist_available', $stats);

        $this->assertGreaterThan(0, $stats['total_patterns']);
        $this->assertEquals(86400, $stats['cache_ttl']);
        $this->assertFalse($stats['external_wordlist_available']);
    }

    #[Test]
    public function it_uses_external_word_list_when_available(): void
    {
        Storage::fake();
        Cache::flush();

        $wordList = ['color' => 'colour', 'organize' => 'organise'];
        Storage::put('spelling/american-british-words.json', json_encode($wordList));

        $result = $this->converter->convert('color');
        $this->assertEquals('colour', $result);
    }

    #[Test]
    public function get_stats_reports_external_wordlist_available(): void
    {
        Storage::fake();
        Cache::flush();

        $wordList = ['color' => 'colour'];
        Storage::put('spelling/american-british-words.json', json_encode($wordList));

        $stats = $this->converter->getStats();

        $this->assertTrue($stats['external_wordlist_available']);
    }
}
