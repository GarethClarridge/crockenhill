<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\HistoricVideoCorroborationGrade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HistoricVideoCorroborationGradeTest extends TestCase
{
    #[Test]
    #[DataProvider('recordings')]
    public function it_grades_a_recording(int $fileCount, ?float $minutes, HistoricVideoCorroborationGrade $expected): void
    {
        $this->assertSame($expected, HistoricVideoCorroborationGrade::forRecording($fileCount, $minutes));
    }

    /** @return array<string, array{int, ?float, HistoricVideoCorroborationGrade}> */
    public static function recordings(): array
    {
        return [
            // The boundary is the empirical separation in the operator's own
            // hand-labelled inventory: shortest full 41.4, longest partial 39.8.
            'longest hand-labelled partial' => [1, 39.8, HistoricVideoCorroborationGrade::ShortPartial],
            'shortest hand-labelled full' => [1, 41.4, HistoricVideoCorroborationGrade::Full],
            'exactly at the boundary' => [1, 40.0, HistoricVideoCorroborationGrade::Full],
            'just below the boundary' => [1, 39.99, HistoricVideoCorroborationGrade::ShortPartial],
            'sermon-only clip' => [1, 22.0, HistoricVideoCorroborationGrade::ShortPartial],
            'unprobed single recording' => [1, null, HistoricVideoCorroborationGrade::Unknown],
            // File count wins over duration: several segments totalling a full
            // service still need adjudication that nothing is missing between them.
            'multiple segments totalling a full service' => [3, 68.0, HistoricVideoCorroborationGrade::Fragmented],
            'unprobed multiple segments' => [3, null, HistoricVideoCorroborationGrade::Fragmented],
        ];
    }

    #[Test]
    public function only_a_full_recording_may_certify_song_membership(): void
    {
        $this->assertTrue(HistoricVideoCorroborationGrade::Full->corroboratesSongMembership());
        $this->assertFalse(HistoricVideoCorroborationGrade::ShortPartial->corroboratesSongMembership());
        $this->assertFalse(HistoricVideoCorroborationGrade::Fragmented->corroboratesSongMembership());
        $this->assertFalse(HistoricVideoCorroborationGrade::Unknown->corroboratesSongMembership());
    }
}
