<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MojibakeRepair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MojibakeRepairTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function damagedText(): iterable
    {
        // All three sequences are from the OoS archive corpus, which carries 94 of them
        // across 11 orders of service.
        yield 'curly quotes around a song title' => ['NIP â€˜Behold the Lambâ€™', 'NIP ‘Behold the Lamb’'];
        yield 'en dash between clauses' => ['- Hymn â€“ NIP â€“ Creator God', '- Hymn – NIP – Creator God'];
        yield 'apostrophe inside a word' => ['Childrenâ€™s talk (with reading)', 'Children’s talk (with reading)'];
        yield 'middle dot' => ['10.30 Â· Morning', '10.30 · Morning'];
    }

    #[Test]
    #[DataProvider('damagedText')]
    public function it_repairs_utf8_read_as_windows_1252(string $damaged, string $expected): void
    {
        self::assertSame($expected, MojibakeRepair::repair($damaged));
    }

    /** @return iterable<string, array{string}> */
    public static function undamagedText(): iterable
    {
        yield 'already correct curly quotes' => ['NIP ‘Behold the Lamb’'];
        yield 'plain ascii' => ['Song 1 - Oceans'];
        yield 'empty' => [''];
        // Accented Latin text is valid Windows-1252 on its own, so the round-trip check is
        // what stops it being "repaired" into something else.
        yield 'accented latin prose' => ['Vous êtes ici, à côté'];
        yield 'characters Windows-1252 cannot hold' => ['聖歌 — praise'];
        yield 'a real en dash' => ['10,000 Reasons – Matt Redman'];
    }

    #[Test]
    #[DataProvider('undamagedText')]
    public function it_leaves_text_that_is_not_double_encoded_alone(string $text): void
    {
        self::assertSame($text, MojibakeRepair::repair($text));
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $once = MojibakeRepair::repair('NIP â€˜Behold the Lambâ€™');

        self::assertSame($once, MojibakeRepair::repair($once));
    }

    #[Test]
    public function it_passes_null_through(): void
    {
        self::assertNull(MojibakeRepair::repairNullable(null));
        self::assertSame('Children’s talk', MojibakeRepair::repairNullable('Childrenâ€™s talk'));
    }
}
