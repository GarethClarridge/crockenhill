<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\SongEndSpeechOnset;
use App\Services\Media\Audio\AudioTimeline;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * RA3, heard 11-07 (video plan §4.1d, song-end listening and A/B pages): a song that runs into
 * speech is cut 0.1 s before the first spoken word, but the edge decode stretches that word back
 * over the outro's last note, so the fade took the note. The aim is everything up to the first
 * spoken word in the song. Four overlapping decodes on the cut agree on the speech's words, and the
 * cut moves to just before them, unless the music has already stopped at the cut. Each shape below
 * is taken from a heard end.
 */
class SongEndSpeechOnsetTest extends TestCase
{
    private const CUT = 1000.0;

    #[Test]
    public function the_cut_is_decoded_in_four_overlapping_single_chunk_windows(): void
    {
        $windows = (new SongEndSpeechOnset)->windows(self::CUT, 5000.0);

        $this->assertSame([[991.0, 1003.0], [993.0, 1005.0], [995.0, 1007.0], [997.0, 1009.0]],
            array_values(array_map(static fn (array $window): array => [$window['start'], $window['end']], $windows)));
        // Whisper splits audio over 30 s in two and drops or snaps words at the join.
        $this->assertTrue(array_all($windows, static fn (array $window): bool => $window['end'] - $window['start'] <= 28.0
            && $window['window_rule'] === SongEndSpeechOnset::RULE));
    }

    #[Test]
    public function windows_stay_inside_the_recording(): void
    {
        $windows = (new SongEndSpeechOnset)->windows(5.0, 8.0);

        $this->assertTrue(array_all($windows, static fn (array $window): bool => $window['start'] >= 0.0 && $window['end'] <= 8.0 && $window['end'] > $window['start']));
    }

    /**
     * 1096 §1814: "I" is decoded from the cut to +1.9 s over the outro's last note; the windows
     * agree on "know that my Redeemer" from +1.75 s. Heard cut short; the later ending was either.
     */
    #[Test]
    public function a_song_keeps_its_last_note_up_to_the_speech_its_decodes_agree_on(): void
    {
        $timeline = $this->timeline([[990, 1000, 0.71, 0.01], [1000, 1010, 0.48, 0.62]]);
        $words = $this->decodes([['start' => 1000.08, 'end' => 1001.92, 'word' => ' I'], ...$this->spoken(1001.75, ['know', 'that', 'my', 'Redeemer'])]);

        $cut = (new SongEndSpeechOnset)->cutFrom($words, self::CUT, $timeline);

        $this->assertEqualsWithDelta(1001.75 - 0.3, $cut, 0.001);
    }

    /**
     * 1242 §3010: "in prayer," is decoded at different times in each window and nobody speaks there
     * (operator: "I can't hear any speech in the gap"); the later ending was preferred.
     */
    #[Test]
    public function words_the_windows_disagree_on_do_not_hold_the_cut_back(): void
    {
        $timeline = $this->timeline([[990, 1000, 0.75, 0.02], [1000, 1010, 0.56, 0.59]]);
        $words = $this->decodes($this->spoken(1001.37, ["let's", 'pray', 'together']));
        $windows = (new SongEndSpeechOnset)->windows(self::CUT, 5000.0);
        foreach (array_keys($windows) as $index => $name) {
            array_unshift($words[$name], ['start' => 1000.2 + $index * 0.5, 'end' => 1000.45 + $index * 0.5, 'word' => ' in'],
                ['start' => 1000.5 + $index * 0.5, 'end' => 1000.9 + $index * 0.5, 'word' => ' prayer,']);
        }

        $cut = (new SongEndSpeechOnset)->cutFrom($words, self::CUT, $timeline);

        $this->assertEqualsWithDelta(1001.37 - 0.3, $cut, 0.001);
    }

    /**
     * 1040 §1508: the cut falls between "…sit down" and "I'm going to leave", in speech with no
     * music; the current ending was preferred. With the music stopped the song has nothing to keep.
     */
    #[Test]
    public function a_cut_where_the_music_has_stopped_stays(): void
    {
        $timeline = $this->timeline([[990, 1000, 0.77, 0.02], [1000, 1010, 0.02, 0.78]]);
        $words = $this->decodes($this->spoken(1001.29, ["I'm", 'going', 'to', 'leave']));

        $this->assertNull((new SongEndSpeechOnset)->cutFrom($words, self::CUT, $timeline));
    }

    #[Test]
    public function a_cut_is_never_moved_earlier(): void
    {
        $timeline = $this->timeline([[990, 1010, 0.7, 0.4]]);
        $words = $this->decodes($this->spoken(1000.1, ["Let's", 'pray', 'together']));

        $this->assertNull((new SongEndSpeechOnset)->cutFrom($words, self::CUT, $timeline));
    }

    /** One agreed word is not yet speech: a sung word can agree on its own. */
    #[Test]
    public function a_single_agreed_word_does_not_move_the_cut(): void
    {
        $timeline = $this->timeline([[990, 1010, 0.7, 0.4]]);
        $words = $this->decodes($this->spoken(1002.0, ['Amen.']));

        $this->assertNull((new SongEndSpeechOnset)->cutFrom($words, self::CUT, $timeline));
    }

    #[Test]
    public function without_agreed_words_the_cut_stays(): void
    {
        $timeline = $this->timeline([[990, 1010, 0.7, 0.4]]);

        $this->assertNull((new SongEndSpeechOnset)->cutFrom($this->decodes([]), self::CUT, $timeline));
    }

    /**
     * 1168 §2312, aligned decodes (11-08): each window stretched "Let's pray." differently (decoder
     * starts 997.1–1001.0), so with the decoder's own times nothing agreed until "May God himself"
     * at +4.6 s and the cut moved 4.4 s, over the prayer's first words (current preferred). The
     * aligned ends agree: "Let's" ends at 1001.6 s, so the speech starts at 1000.8 s.
     */
    #[Test]
    public function aligned_decodes_find_the_first_spoken_words_the_decoders_own_times_smear(): void
    {
        $timeline = $this->timeline([[990, 1000, 0.7, 0.1], [1000, 1010, 0.5, 0.6]]);
        $words = [];
        foreach (array_keys((new SongEndSpeechOnset)->windows(self::CUT, 5000.0)) as $index => $name) {
            $smear = 997.1 + $index * 1.3;
            $words[$name] = [
                ['start' => $smear, 'end' => 1001.6, 'word' => " Let's", 'aligned_end' => 1001.6],
                ['start' => 1001.6, 'end' => 1002.0, 'word' => ' pray', 'aligned_end' => 1002.0],
                ['start' => 1002.0, 'end' => 1002.1, 'word' => '.', 'aligned_end' => 1002.1],
                ...array_map(static fn (array $word): array => $word + ['aligned_end' => $word['end']], $this->spoken(1004.6, ['May', 'God', 'himself'])),
            ];
        }

        $onset = new SongEndSpeechOnset;

        $this->assertEqualsWithDelta(1000.8, $onset->onsetFrom($words, self::CUT, $timeline), 0.001);
        $this->assertEqualsWithDelta(1000.5, $onset->cutFrom($words, self::CUT, $timeline), 0.001);
    }

    #[Test]
    public function the_cut_windows_ask_for_aligned_word_timing(): void
    {
        $this->assertTrue(array_all((new SongEndSpeechOnset)->windows(self::CUT, 5000.0),
            static fn (array $window): bool => $window['word_timing'] === SongEndSpeechOnset::WORD_TIMING));
    }

    #[Test]
    public function the_local_provider_stamps_windows_with_dtw(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'local']);

        $this->assertTrue(array_all((new SongEndSpeechOnset)->windows(self::CUT, 5000.0),
            static fn (array $window): bool => $window['word_timing'] === 'dtw'));
    }

    #[Test]
    public function the_openai_provider_stamps_windows_with_its_own_word_timing(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'openai']);

        $this->assertTrue(array_all((new SongEndSpeechOnset)->windows(self::CUT, 5000.0),
            static fn (array $window): bool => $window['word_timing'] === 'whisper-1-word-end'
                && $window['window_rule'] === SongEndSpeechOnset::RULE));
    }

    /**
     * Every window's decode of the words it covers, as a decoder that hears them cleanly would give.
     *
     * @param  list<array{start: float, end: float, word: string}>  $heard
     * @return array<string, list<array{start: float, end: float, word: string}>>
     */
    private function decodes(array $heard): array
    {
        $decodes = [];
        foreach ((new SongEndSpeechOnset)->windows(self::CUT, 5000.0) as $name => $window) {
            $decodes[$name] = array_values(array_filter($heard, static fn (array $word): bool => $word['start'] >= $window['start'] && $word['end'] <= $window['end']));
        }

        return $decodes;
    }

    /**
     * Spoken words: short, close together.
     *
     * @param  list<string>  $text
     * @return list<array{start: float, end: float, word: string}>
     */
    private function spoken(float $from, array $text): array
    {
        return array_map(static fn (int $index, string $word): array => ['start' => round($from + $index * 0.35, 3), 'end' => round($from + $index * 0.35 + 0.25, 3), 'word' => " {$word}"],
            array_keys($text), $text);
    }

    /** @param list<array{0: float|int, 1: float|int, 2: float, 3: float, 4?: float}> $spans */
    private function timeline(array $spans): AudioTimeline
    {
        return AudioTimeline::fromJson(AudioTimelineFixture::json($spans, 5000.0));
    }
}
