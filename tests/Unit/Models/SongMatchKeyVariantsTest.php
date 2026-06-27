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
