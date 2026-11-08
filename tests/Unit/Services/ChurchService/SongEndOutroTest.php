<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\SongEndOutro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RA3e, heard 11-08 (video plan §4.1d, E29): a song that runs into speech ends where its separated
 * tracks say. Each shape below is taken from a heard end's separation, times from the cut at 1000 s.
 */
class SongEndOutroTest extends TestCase
{
    private const CUT = 1000.0;

    /**
     * 1305 §3891: the singing stops at the cut, the voice is quiet until the talking at +2.8 s, and
     * the instruments die away by +4.3 s. The operator's rule: take the talking out from the last
     * sung word and fade from it to the outro's end. Under the talking, the end is where half the
     * next second is quiet, so it lands as the outro goes: +3.8 s.
     */
    #[Test]
    public function talking_over_the_outro_is_taken_out_and_the_outro_fades_from_the_last_sung_word(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0, -24.0], [0, 2.8, -65.0], [2.8, 16, -32.0]],
            instruments: [[-8, 1.5, -20.0], [1.5, 4.3, -33.0], [4.3, 16, -62.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 2.1, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::UNDER_SPEECH, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 2.8, $ending['speech'], 0.001);
        $this->assertEqualsWithDelta(self::CUT, $ending['last_sung'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 3.8, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(3.8, $ending['fade_out'], 0.001);
        $this->assertEqualsWithDelta(self::CUT, $ending['voice_removal']['from'], 0.001);
        $this->assertSame(self::CUT - 8.0, $ending['voice_removal']['instruments_start']);
    }

    /**
     * 961 §828: an instrumental outro dies away (−33 dB to −54 by +2.0 s) before the talking at
     * +2.5 s; a 0.1 s blip of voice at +2.0 s is not the talking. The song ends at the silence.
     */
    #[Test]
    public function an_outro_that_dies_before_the_talking_ends_the_song_at_the_silence(): void
    {
        $stems = $this->stems(
            voice: [[-8, 2.0, -98.0], [2.0, 2.1, -55.0], [2.1, 2.5, -85.0], [2.5, 16, -38.0]],
            instruments: [[-8, 1.2, -33.0], [1.2, 1.8, -45.0], [1.8, 2.6, -56.0], [2.6, 2.8, -46.0], [2.8, 16, -64.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.77, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::SILENCE, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 2.5, $ending['speech'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 1.8, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(0.1, $ending['fade_out'], 0.001);
        $this->assertArrayNotHasKey('voice_removal', $ending);
    }

    /** 1353 §4451: the talking follows the singing with no pause; the last sung word is taken to be its start. */
    #[Test]
    public function singing_that_runs_straight_into_talking_is_taken_out_from_the_talkings_start(): void
    {
        $stems = $this->stems(
            voice: [[-8, 16, -22.0]],
            instruments: [[-8, 0.7, -20.0], [0.7, 1.9, -33.0], [1.9, 16, -55.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 0.3, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::UNDER_SPEECH, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 0.3, $ending['last_sung'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 1.4, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(1.1, $ending['fade_out'], 0.001);
    }

    /**
     * 1168 §2312: the singing stopped 5.5 s before the talking, so the fade starts 4 s before it
     * (operator, 11-08). The outro is still sounding when the talking starts, and goes with it.
     */
    #[Test]
    public function a_long_instrumental_before_the_talking_plays_until_four_seconds_before_it(): void
    {
        $stems = $this->stems(
            voice: [[-8, -5.5, -25.0], [-5.5, 1.3, -97.0], [1.3, 16, -30.0]],
            instruments: [[-8, 1.0, -30.0], [1.0, 1.6, -45.0], [1.6, 16, -60.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 0.56, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::UNDER_SPEECH, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT - 5.5, $ending['last_sung'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 1.3, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(1.3 - (1.3 - 4.0), $ending['fade_out'], 0.001);
        $this->assertEqualsWithDelta(self::CUT - 5.5, $ending['voice_removal']['from'], 0.001);
    }

    /**
     * 1117 §1966: the talking leaks into the instrument track as short spikes. The outro died with
     * the talking's start (operator, 11-08: "about 69:39"), but a second with every frame quiet only
     * came in a pause, 12 s on. Half the second quiet ends it at the talking.
     */
    #[Test]
    public function talking_that_leaks_into_the_instruments_does_not_keep_the_outro_playing(): void
    {
        $leak = [[-8, 1.0, -20.0]];
        for ($at = 1.0; $at < 16; $at += 0.5) {
            $leak[] = [$at, $at + 0.2, -30.0];
            $leak[] = [$at + 0.2, $at + 0.5, -70.0];
        }
        $stems = $this->stems(voice: [[-8, 0, -24.0], [0, 1.0, -70.0], [1.0, 16, -30.0]], instruments: $leak);

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.0, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::UNDER_SPEECH, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 1.0, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(1.0, $ending['fade_out'], 0.001);
    }

    /**
     * 1016 §1314: the recording itself cuts from the music to digital silence, 4.9 s after the last
     * sung word. The operator chose a 3 s fade into the cut over 0.1 s and 1.5 s (11-08).
     */
    #[Test]
    public function a_recording_that_cuts_out_is_faded_into_the_cut(): void
    {
        $stems = $this->stems(
            voice: [[-8, -4.9, -24.0], [-4.9, 1.8, -95.0], [1.8, 16, -30.0]],
            instruments: [[-8, 0, -34.0], [0, 1.8, -86.0], [1.8, 16, -45.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.1, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::RECORDING_CUT, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(3.0, $ending['fade_out'], 0.001);
        $this->assertArrayNotHasKey('voice_removal', $ending);
    }

    /** 939 §637: the singing stops 0.3 s before the recording cuts out, so the fade starts there, not 3 s back. */
    #[Test]
    public function a_recording_cut_never_fades_the_last_sung_word(): void
    {
        $stems = $this->stems(
            voice: [[-8, -3.3, -24.0], [-3.3, 0.3, -95.0], [0.3, 16, -30.0]],
            instruments: [[-8, -3.0, -29.0], [-3.0, 0.3, -90.0], [0.3, 16, -45.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 0.8, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::RECORDING_CUT, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT - 3.3, $ending['last_sung'], 0.001);
        $this->assertEqualsWithDelta(self::CUT - 3.0, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(0.3, $ending['fade_out'], 0.001);
    }

    /** A recording cut where the singing runs into it has no room for a fade beyond a click's. */
    #[Test]
    public function a_recording_cut_through_the_singing_only_avoids_a_click(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0, -24.0], [0, 2.0, -95.0], [2.0, 16, -30.0]],
            instruments: [[-8, 0, -29.0], [0, 2.0, -90.0], [2.0, 16, -45.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.5, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::RECORDING_CUT, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(0.1, $ending['fade_out'], 0.001);
    }

    /**
     * 1379 §176320: the stream drops out for 1.4 s, the outro comes back quietly, and the recording
     * cuts out for good at +0.3 s. The operator heard the end about 3 s after the dropout (11-08).
     */
    #[Test]
    public function music_that_comes_back_after_a_dropout_ends_at_the_later_cut(): void
    {
        $stems = $this->stems(
            voice: [[-8, -4.5, -24.0], [-4.5, 2.3, -95.0], [2.3, 16, -33.0]],
            instruments: [[-8, -2.4, -23.0], [-2.4, -0.9, -85.0], [-0.9, 0.3, -50.0], [0.3, 16, -90.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.59, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::RECORDING_CUT, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 0.3, $ending['time'], 0.001);
    }

    /** Music under a prayer never dies away in the separated stretch: there is no ending to find. */
    #[Test]
    public function music_that_plays_on_under_the_talking_finds_no_ending(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0, -24.0], [0, 1.0, -70.0], [1.0, 16, -30.0]],
            instruments: [[-8, 16, -22.0]],
        );

        $this->assertNull((new SongEndOutro)->ending($stems, self::CUT + 1.0, self::CUT));
    }

    /**
     * Frames every 0.1 s from the cut − 8 s to the cut + 16 s, each span at one level.
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $voice
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $instruments
     * @return array{start: float, frame_seconds: float, voice_db: list<float>, instruments_db: list<float>, instruments: array{disk: string, path: string}}
     */
    private function stems(array $voice, array $instruments): array
    {
        $frames = static function (array $spans): array {
            $levels = [];
            for ($index = 0; $index < 240; $index++) {
                $time = -8.0 + $index * 0.1 + 0.0001;
                foreach ($spans as [$from, $to, $db]) {
                    if ($time >= $from && $time < $to) {
                        $levels[] = $db;
                        break;
                    }
                }
            }

            return $levels;
        };

        return ['start' => self::CUT - 8.0, 'frame_seconds' => 0.1, 'voice_db' => $frames($voice), 'instruments_db' => $frames($instruments),
            'instruments' => ['disk' => 'local', 'path' => 'stems.flac']];
    }
}
