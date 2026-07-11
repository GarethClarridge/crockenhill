<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Song;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongMatchKeyVariantsTest extends TestCase
{
    #[Test]
    #[DataProvider('variantDataProvider')]
    public function it_generates_correct_match_key_variants(string $input, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, Song::matchKeyVariants($input));
    }

    #[Test]
    #[DataProvider('firstLineKeyDataProvider')]
    public function it_derives_the_first_line_key_from_lyrics(?string $lyricsPlain, ?string $expected): void
    {
        $this->assertSame($expected, Song::firstLineKeyFromLyrics($lyricsPlain));
    }

    #[Test]
    #[DataProvider('matchKeyDataProvider')]
    public function it_derives_a_punctuation_insensitive_match_key(string $input, string $expected): void
    {
        $this->assertSame($expected, Song::matchKey($input));
    }

    public static function matchKeyDataProvider(): array
    {
        return [
            'empty string' => [
                'input' => '',
                'expected' => '',
            ],
            'punctuation only' => [
                'input' => "'’”)(,",
                'expected' => '',
            ],
            'curly quotes stripped' => [
                'input' => '‘From the breaking of the dawn’',
                'expected' => 'from the breaking of the dawn',
            ],
            'mismatched quote stripped' => [
                'input' => "When I was lost'",
                'expected' => 'when i was lost',
            ],
            'space before comma collapsed' => [
                'input' => 'Come , let us join our cheerful songs',
                'expected' => 'come let us join our cheerful songs',
            ],
            'trailing comma from first-line key' => [
                'input' => 'all creatures of our god and king,',
                'expected' => 'all creatures of our god and king',
            ],
            'digit runs stay separated' => [
                'input' => '10,000 Reasons',
                'expected' => '10 000 reasons',
            ],
            'strips OpenLP @ search text' => [
                'input' => 'He Will Hold Me Fast@when i fear my faith will fail',
                'expected' => 'he will hold me fast',
            ],
            'apostrophes vanish to match openlp-stripped keys' => [
                'input' => 'The church’s one foundation',
                'expected' => 'the churchs one foundation',
            ],
            'straight apostrophe contraction' => [
                'input' => "Your word is good, it's ever faithful",
                'expected' => 'your word is good its ever faithful',
            ],
        ];
    }

    public static function firstLineKeyDataProvider(): array
    {
        return [
            'null lyrics' => [
                'lyricsPlain' => null,
                'expected' => null,
            ],
            'blank lyrics' => [
                'lyricsPlain' => "  \n\n  ",
                'expected' => null,
            ],
            'first non-empty line, canonicalised' => [
                'lyricsPlain' => "\n  What Love Could  Remember \nNo wrongs we have done",
                'expected' => 'what love could remember',
            ],
            'single line' => [
                'lyricsPlain' => 'Amazing grace how sweet the sound',
                'expected' => 'amazing grace how sweet the sound',
            ],
            // A run-together lyrics paragraph (no line breaks) would otherwise
            // derive a key longer than the first_line_key column — clamp it.
            'run-together paragraph clamped to column length' => [
                'lyricsPlain' => str_repeat('a', 400),
                'expected' => str_repeat('a', Song::FIRST_LINE_KEY_MAX_LENGTH),
            ],
        ];
    }

    public static function variantDataProvider(): array
    {
        return [
            'empty string' => [
                'input' => '',
                'expected' => [],
            ],
            'whitespace only' => [
                'input' => '   ',
                'expected' => [],
            ],
            'simple title' => [
                'input' => 'Amazing Grace',
                'expected' => ['amazing grace'],
            ],
            'title with "O " prefix' => [
                'input' => 'O Jesus I Have Promised',
                'expected' => ['o jesus i have promised', 'oh jesus i have promised'],
            ],
            'title with "Oh " prefix' => [
                'input' => 'Oh Come All Ye Faithful',
                'expected' => ['oh come all ye faithful', 'o come all ye faithful'],
            ],
            'title with "O" but not as a word' => [
                'input' => 'Only Believe',
                'expected' => ['only believe'],
            ],
            'title with "Oh" but not as a word' => [
                'input' => 'Ohio',
                'expected' => ['ohio'],
            ],
            'normalized spacing and case' => [
                'input' => '  OH   Come   All  ',
                'expected' => ['oh come all', 'o come all'],
            ],
            'strips OpenLP @ search text' => [
                'input' => 'O Jesus I Have Promised@F9',
                'expected' => ['o jesus i have promised', 'oh jesus i have promised'],
            ],
        ];
    }
}
