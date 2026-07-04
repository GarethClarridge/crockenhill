<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Song\Sync;

use App\Services\Song\Sync\OpenLpRowValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenLpRowValueTest extends TestCase
{
    #[Test]
    #[DataProvider('stringOrNullProvider')]
    public function it_normalises_strings_or_nulls(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, OpenLpRowValue::stringOrNull($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function stringOrNullProvider(): array
    {
        return [
            'valid string' => ['Hello', 'Hello'],
            'string with whitespace' => ['  Hello  ', 'Hello'],
            'empty string' => ['', null],
            'whitespace only' => ['   ', null],
            'null' => [null, null],
            'integer' => [123, null],
            'boolean' => [true, null],
            'array' => [[], null],
        ];
    }

    #[Test]
    #[DataProvider('intOrNullProvider')]
    public function it_normalises_ints_or_nulls(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, OpenLpRowValue::intOrNull($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function intOrNullProvider(): array
    {
        return [
            'valid int' => [123, 123],
            'numeric string' => ['456', 456],
            'numeric string with whitespace' => ['  789  ', 789],
            'non-numeric string' => ['abc', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'boolean' => [true, null],
            'array' => [[], null],
        ];
    }

    #[Test]
    #[DataProvider('parseTimestampProvider')]
    public function it_parses_timestamps(mixed $input, int $expected): void
    {
        if ($input === '2024-01-01 12:00:00') {
            $this->assertSame(strtotime('2024-01-01 12:00:00'), OpenLpRowValue::parseTimestamp($input));

            return;
        }

        $this->assertSame($expected, OpenLpRowValue::parseTimestamp($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function parseTimestampProvider(): array
    {
        return [
            'valid date string' => ['2024-01-01 12:00:00', 0], // expected is ignored in the test for this case
            'empty string' => ['', 0],
            'whitespace only' => ['   ', 0],
            'invalid date string' => ['not a date', 0],
            'null' => [null, 0],
            'integer' => [123456789, 0],
        ];
    }

    #[Test]
    public function it_extracts_source_song_ids(): void
    {
        $rows = [
            ['id' => 1, 'title' => 'Song 1'],
            ['id' => '2', 'title' => 'Song 2'],
            ['id' => '  3  ', 'title' => 'Song 3'],
            ['id' => null, 'title' => 'Song 4'],
            ['id' => 'abc', 'title' => 'Song 5'],
            ['no_id' => 6],
        ];

        $expected = [1, 2, 3];

        $this->assertSame($expected, OpenLpRowValue::sourceSongIds($rows));
    }

    #[Test]
    public function it_returns_empty_array_when_no_ids_present(): void
    {
        $rows = [
            ['title' => 'Song 1'],
            ['id' => null],
            ['id' => 'abc'],
        ];

        $this->assertSame([], OpenLpRowValue::sourceSongIds($rows));
    }
}
