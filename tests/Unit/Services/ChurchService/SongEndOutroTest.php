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

    /**
     * 1353 §4451: the talking follows the singing with no pause; the last sung word is taken to be its
     * start. The talking is as loud as the singing, so any outro after it would carry the trace the
     * separation leaves: the song ends where the talking starts.
     */
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
        $this->assertEqualsWithDelta(self::CUT + 1.4, $ending['outro_end'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 0.3, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(0.1, $ending['fade_out'], 0.001);
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

    /**
     * 1309 §3926 (11-08): loud talking over a long outro. The separation leaves a trace of the talking,
     * so the song ends once that trace would be heard through the fade, before the outro has gone.
     */
    #[Test]
    public function loud_talking_over_a_long_outro_ends_the_song_before_the_talking_shows(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0, -24.0], [0, 1.0, -70.0], [1.0, 16, -24.0]],
            instruments: [[-8, 6.0, -20.0], [6.0, 16, -62.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.0, self::CUT);

        $this->assertNotNull($ending);
        $this->assertEqualsWithDelta(self::CUT + 5.5, $ending['outro_end'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 1.2, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(1.2, $ending['fade_out'], 0.001);
        $this->assertNotNull($ending['voice_removal'] ?? null);
    }

    /** 4407 (11-08, right at +6.1): quiet talking stays hidden through the fade, so the outro plays out. */
    #[Test]
    public function quiet_talking_over_a_long_outro_lets_the_outro_play_out(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0, -24.0], [0, 1.0, -70.0], [1.0, 16, -40.0]],
            instruments: [[-8, 6.0, -20.0], [6.0, 16, -62.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.0, self::CUT);

        $this->assertNotNull($ending);
        $this->assertEqualsWithDelta($ending['outro_end'], $ending['time'], 0.001);
    }

    /**
     * 1117 §1966 (11-08: the shorter ending "removes the 'has done'"): quiet singing under loud
     * talking. Judged against the talking, the singing read as silence and the last sung word went
     * back to the edge of the stretch; judged against the singing, it is where the singing stops.
     * The voice rising into the talking is the talking's start, not singing.
     */
    #[Test]
    public function quiet_singing_is_judged_against_the_singing_not_the_talking(): void
    {
        $stems = $this->stems(
            voice: [[-8, -2.8, -50.0], [-2.8, 0.6, -85.0], [0.6, 1.4, -50.0], [1.4, 16, -23.0]],
            instruments: [[-8, 2.0, -40.0], [2.0, 16, -70.0]],
        );

        $voice = (new SongEndOutro)->voice($stems, self::CUT + 0.74);

        $this->assertEqualsWithDelta(self::CUT + 1.4, $voice['speech'], 0.001);
        $this->assertEqualsWithDelta(self::CUT - 2.8, $voice['last_sung'], 0.001);
    }

    /**
     * 969 §173622 (11-08: "that's the first spoken sound (an intake of breath). The last sung word is
     * a few seconds earlier"): a breath well under the singing's level is not a sung phrase.
     */
    #[Test]
    public function a_breath_before_the_talking_is_not_the_last_sung_word(): void
    {
        $stems = $this->stems(
            voice: [[-8, -3.8, -42.0], [-3.8, -0.4, -80.0], [-0.4, 0.2, -55.0], [0.2, 2.1, -85.0], [2.1, 16, -40.0]],
            instruments: [[-8, 2.0, -40.0], [2.0, 16, -70.0]],
        );

        $voice = (new SongEndOutro)->voice($stems, self::CUT + 1.4);

        $this->assertEqualsWithDelta(self::CUT + 2.1, $voice['speech'], 0.001);
        $this->assertEqualsWithDelta(self::CUT - 3.8, $voice['last_sung'], 0.001);
    }

    /**
     * 2858, 3586, 907 (11-08, preferred with it taken out): the song ends at a silence, but voice that
     * is not singing sounds after the last sung word. It is taken out with the voice track from there,
     * as under the talking.
     */
    #[Test]
    public function voice_left_after_the_singing_before_a_silence_is_taken_out(): void
    {
        $stems = $this->stems(
            voice: [[-8, -1.0, -30.0], [-1.0, 0.5, -95.0], [0.5, 1.1, -45.0], [1.1, 2.5, -95.0], [2.5, 16, -35.0]],
            instruments: [[-8, 1.5, -30.0], [1.5, 16, -70.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 2.0, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::SILENCE, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT - 1.0, $ending['last_sung'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 1.5, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(self::CUT - 1.0, $ending['voice_removal']['from'] ?? null, 0.001);
    }

    /**
     * 969 §173622 (11-08, preferred): a breath into the microphone lands in the instrument track,
     * 35 dB above the dying outro, so taking the voice out leaves it. The song ends before it.
     */
    #[Test]
    public function a_noise_breaking_into_the_dying_outro_ends_the_song_before_it(): void
    {
        $stems = $this->stems(
            voice: [[-8, -3.8, -42.0], [-3.8, 2.1, -85.0], [2.1, 16, -40.0]],
            instruments: [[-8, 0.9, -55.0], [0.9, 1.4, -20.0], [1.4, 16, -80.0]],
        );

        $ending = (new SongEndOutro)->ending($stems, self::CUT + 1.4, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::SILENCE, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 1.4, $ending['outro_end'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 0.9, $ending['noise'] ?? null, 0.001);
        $this->assertEqualsWithDelta(self::CUT + 0.9, $ending['time'], 0.001);
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
     * 1088 §1780 (heard 11-09): no speech is agreed after the cut, but the song carries on, singing to
     * +1.5 s and an outro dying away at +4.3 s; the talking is +8.5 s. The voice track finds the
     * talking where the voice comes back after a pause, and the song ends at the silence before it.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_ends_at_the_silence_before_the_talking_the_voice_track_finds(): void
    {
        $stems = $this->stems(
            voice: [[-8, 1.5, -24.0], [1.5, 8.5, -98.0], [8.5, 16, -35.0]],
            instruments: [[-8, 4.3, -30.0], [4.3, 16, -60.0]],
        );

        $ending = (new SongEndOutro)->endingFromTheVoiceTrack($stems, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::SILENCE, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 8.5, $ending['speech'], 0.001);
        $this->assertEqualsWithDelta(self::CUT + 4.3, $ending['time'], 0.001);
        $this->assertEqualsWithDelta(0.1, $ending['fade_out'], 0.001);
    }

    /**
     * 1365 §4562 (heard 11-09: "should be about 58:43"): a note held past the cut, the instruments
     * quietened under it. Read from the cut, the outro "died" at +1.0 s; it is read from the last sung
     * word, so the song never ends before its singing.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_never_ends_before_its_last_sung_word(): void
    {
        $stems = $this->stems(
            voice: [[-8, 4.0, -24.0], [4.0, 10, -98.0], [10, 16, -35.0]],
            instruments: [[-8, 1.0, -20.0], [1.0, 4.7, -42.0], [4.7, 16, -65.0]],
        );

        $ending = (new SongEndOutro)->endingFromTheVoiceTrack($stems, self::CUT);

        $this->assertNotNull($ending);
        $this->assertSame(SongEndOutro::SILENCE, $ending['song_end_outro']);
        $this->assertEqualsWithDelta(self::CUT + 4.7, $ending['time'], 0.001);
    }

    /**
     * Heard 11-09 (27 endings in three rounds): where the talking the voice track finds starts under
     * the music, the new ending was preferred 6 times and today's cut 8, and nothing measured told them
     * apart. The cut before the speech stands.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_whose_outro_runs_under_the_talking_keeps_its_cut(): void
    {
        $stems = $this->stems(
            voice: [[-8, 0.2, -24.0], [0.2, 1.4, -70.0], [1.4, 16, -40.0]],
            instruments: [[-8, 2.0, -20.0], [2.0, 4.5, -33.0], [4.5, 16, -62.0]],
        );

        $this->assertNull((new SongEndOutro)->endingFromTheVoiceTrack($stems, self::CUT));
    }

    /** With no voice coming back after the cut, there is no talking to end before. */
    #[Test]
    public function a_song_with_no_agreed_speech_and_no_talking_on_the_voice_track_keeps_its_cut(): void
    {
        $stems = $this->stems(
            voice: [[-8, 1.5, -24.0], [1.5, 16, -98.0]],
            instruments: [[-8, 4.3, -30.0], [4.3, 16, -60.0]],
        );

        $this->assertNull((new SongEndOutro)->endingFromTheVoiceTrack($stems, self::CUT));
    }

    /** A breath after the singing (voice for 0.1 s) is not the talking: the talking is the voice held after it. */
    #[Test]
    public function a_breath_after_the_singing_is_not_the_talking_the_voice_track_finds(): void
    {
        $stems = $this->stems(
            voice: [[-8, 1.5, -24.0], [1.5, 3.0, -98.0], [3.0, 3.1, -30.0], [3.1, 8.5, -98.0], [8.5, 16, -35.0]],
            instruments: [[-8, 4.3, -30.0], [4.3, 16, -60.0]],
        );

        $ending = (new SongEndOutro)->endingFromTheVoiceTrack($stems, self::CUT);

        $this->assertNotNull($ending);
        $this->assertEqualsWithDelta(self::CUT + 8.5, $ending['speech'], 0.001);
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
