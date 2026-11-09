<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\SongEndSpeechOnset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A rule that moves a song's end past its cut keeps the cut it replaced: the next item still starts
 * there, and the cut's evidence is prepared there, so its decodes and stems stay the same (E27, E30).
 */
class SongEndCutBeforeTheMoveTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function movesPastTheCut(): array
    {
        return ['agreed speech onset' => [SongEndSpeechOnset::REASON], 'voice-track talking' => [CueSafeExtractionPlan::SONG_END_VOICE_TRACK_TALKING]];
    }

    #[Test]
    #[DataProvider('movesPastTheCut')]
    public function a_song_end_moved_past_its_cut_reads_as_the_cut_it_replaced(string $reason): void
    {
        $entry = ['edge' => 'end', 'time' => 1004.3, 'reason' => $reason, 'replaced' => ['time' => 1000.0, 'reason' => 'song_end_speech']];

        $cut = CueSafeExtractionPlan::cutBeforeTheMove($entry);

        $this->assertSame(1000.0, $cut['time']);
        $this->assertSame('song_end_speech', $cut['reason']);
        $this->assertSame('end', $cut['edge']);
    }

    #[Test]
    public function a_song_end_no_rule_moved_is_its_own_cut(): void
    {
        $entry = ['edge' => 'end', 'time' => 1000.0, 'reason' => CueSafeExtractionPlan::SONG_END_FADE];

        $this->assertSame($entry, CueSafeExtractionPlan::cutBeforeTheMove($entry));
    }
}
