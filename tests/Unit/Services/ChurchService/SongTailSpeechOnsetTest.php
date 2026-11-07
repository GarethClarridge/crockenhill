<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\SongTailSpeechOnset;
use App\Services\Media\Audio\AudioTimeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\AudioTimelineFixture;

/**
 * RA3 fix (b), measured 11-07 on 41 listening rulings and 74 sung tails (video plan §4.1d): where
 * speech starts inside a song section's tail, the transcript line opens 8–34 s early over music,
 * so the song ends 1.5 s before the first words that overlapping decodes agree on, in a window the
 * classifier hears as speech without music. Each shape below is taken from a ruled tail.
 */
class SongTailSpeechOnsetTest extends TestCase
{
    private const END = 1000.0;

    #[Test]
    public function the_tail_is_decoded_in_seven_overlapping_windows_and_one_wide_one(): void
    {
        $windows = (new SongTailSpeechOnset)->windows(self::END, 5000.0);

        $this->assertSame([978.0, 990.0], [$windows['slide-22']['start'], $windows['slide-22']['end']]);
        $this->assertSame([996.0, 1008.0], [$windows['slide-4']['start'], $windows['slide-4']['end']]);
        $this->assertCount(7, array_filter(array_keys($windows), static fn (string $name): bool => str_starts_with($name, 'slide-')));
        $this->assertSame([978.0, 1006.0], [$windows['before']['start'], $windows['before']['end']]);
        // Whisper splits audio over 30 s in two and drops or snaps words at the join.
        $this->assertTrue(array_all($windows, static fn (array $window): bool => $window['end'] - $window['start'] <= 28.0
            && $window['window_rule'] === SongTailSpeechOnset::RULE));
    }

    #[Test]
    public function windows_stay_inside_the_recording(): void
    {
        $windows = (new SongTailSpeechOnset)->windows(10.0, 14.0);

        $this->assertTrue(array_all($windows, static fn (array $window): bool => $window['start'] >= 0.0 && $window['end'] <= 14.0 && $window['end'] > $window['start']));
    }

    /** 1274 §3430: "…to God in prayer again. But let me mention a few things" at −4.2, found only by agreement. */
    #[Test]
    public function a_song_ends_before_the_speech_its_overlapping_decodes_agree_on(): void
    {
        $timeline = $this->timeline([[970, 990, 0.75, 0.01], [990, 1010, 0.01, 0.8]]);
        $speech = $this->spoken(995.8, ['to', 'God', 'in', 'prayer', 'again.', 'But', 'let', 'me', 'mention']);
        $words = $this->decodes([...$this->sung(982.0, 6), ...$speech]);

        $cut = (new SongTailSpeechOnset)->cutFrom($words, self::END, 988.0, $timeline);

        $this->assertEqualsWithDelta(995.8 - 1.5, $cut, 0.001);
    }

    /** 1092 §2819: "O Church of Christ, rejoice" sits in a window reading speech 0.54 and music 0.55. */
    #[Test]
    public function a_sung_line_the_classifier_hears_as_speech_and_music_does_not_end_the_song(): void
    {
        $timeline = $this->timeline([[970, 995, 0.47, 0.05], [995, 1000, 0.55, 0.54], [1000, 1010, 0.22, 0.62]]);
        $words = $this->decodes($this->spoken(995.2, ['O', 'Church', 'of', 'Christ', 'rejoice']));

        $this->assertNull((new SongTailSpeechOnset)->cutFrom($words, self::END, 999.3, $timeline));
    }

    /** Each window pins a lead-in word to its own start; those copies never agree. */
    #[Test]
    public function words_pinned_to_a_windows_start_are_not_speech(): void
    {
        $timeline = $this->timeline([[970, 1010, 0.01, 0.8]]);
        $words = $this->decodes([]);
        foreach (array_keys($words) as $name) {
            $start = (new SongTailSpeechOnset)->windows(self::END, 5000.0)[$name]['start'];
            $words[$name] = [['start' => $start, 'end' => $start + 0.2, 'word' => ' Thank'], ['start' => $start + 0.2, 'end' => $start + 0.4, 'word' => ' you'],
                ['start' => $start + 0.4, 'end' => $start + 0.6, 'word' => ' Lord.']];
        }

        $this->assertNull((new SongTailSpeechOnset)->cutFrom($words, self::END, 999.0, $timeline));
    }

    /** 945 §669: no decode agrees, but the wide decode hears "Let's pray together" over the outro. */
    #[Test]
    public function without_agreement_the_wide_decode_places_the_speech(): void
    {
        $timeline = $this->timeline([[970, 995, 0.69, 0.02], [995, 1000, 0.47, 0.65], [1000, 1010, 0.02, 0.06]]);
        $words = $this->decodes([]);
        $words['before'] = $this->spoken(996.5, ["Let's", 'pray', 'together.']);

        $cut = (new SongTailSpeechOnset)->cutFrom($words, self::END, 1002.2, $timeline);

        $this->assertEqualsWithDelta(996.0, $cut, 0.001);
    }

    /** 1297 §3795: a spoken "Amen" 1.4 s before the doxology belongs to the speech. */
    #[Test]
    public function the_wide_decode_takes_in_the_words_just_before_the_speech_the_classifier_hears(): void
    {
        $timeline = $this->timeline([[970, 985, 0.7, 0.02], [985, 990, 0.53, 0.69], [990, 1010, 0.01, 0.88]]);
        $words = $this->decodes([]);
        $words['before'] = [['start' => 987.0, 'end' => 987.3, 'word' => ' Amen.'],
            ...$this->spoken(988.7, ['Praise', 'and', 'glory', 'and', 'wisdom'])];

        $cut = (new SongTailSpeechOnset)->cutFrom($words, self::END, 1000.3, $timeline);

        $this->assertEqualsWithDelta(986.5, $cut, 0.001);
    }

    /** 1119 §1986: the tail is all speech, so the earlier planned cut stands. */
    #[Test]
    public function a_cut_is_never_moved_later_through_speech_alone(): void
    {
        $timeline = $this->timeline([[970, 985, 0.6, 0.03], [985, 1010, 0.01, 0.87]]);
        $words = $this->decodes($this->spoken(991.8, ["There's", 'a', 'picture', 'and', 'the', 'picture']));

        $this->assertNull((new SongTailSpeechOnset)->cutFrom($words, self::END, 987.4, $timeline));
    }

    /** 1032 §1435: the planned cut fell 4 s before the speech, inside the singing. */
    #[Test]
    public function a_cut_moves_later_to_keep_singing_before_the_speech(): void
    {
        $timeline = $this->timeline([[970, 995, 0.7, 0.02], [995, 1010, 0.01, 0.77]]);
        $words = $this->decodes([...$this->sung(985.0, 5), ...$this->spoken(1000.0, ['Now', 'are', 'there', 'any', 'little', 'ones'])]);

        $cut = (new SongTailSpeechOnset)->cutFrom($words, self::END, 994.0, $timeline);

        $this->assertEqualsWithDelta(998.5, $cut, 0.001);
    }

    #[Test]
    public function speech_starting_after_the_songs_end_is_not_this_rules(): void
    {
        $timeline = $this->timeline([[970, 1000, 0.7, 0.02], [1000, 1010, 0.01, 0.8]]);
        $words = $this->decodes($this->spoken(1001.0, ["Let's", 'pray', 'together']));

        $this->assertNull((new SongTailSpeechOnset)->cutFrom($words, self::END, 1000.0, $timeline));
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
        foreach ((new SongTailSpeechOnset)->windows(self::END, 5000.0) as $name => $window) {
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

    /**
     * Sung words: held, with breaths between.
     *
     * @return list<array{start: float, end: float, word: string}>
     */
    private function sung(float $from, int $count): array
    {
        return array_map(static fn (int $index): array => ['start' => $from + $index * 1.0, 'end' => $from + $index * 1.0 + 0.6, 'word' => ' Lord'], range(0, $count - 1));
    }

    /** @param list<array{0: float|int, 1: float|int, 2: float, 3: float, 4?: float}> $spans */
    private function timeline(array $spans): AudioTimeline
    {
        return AudioTimeline::fromJson(AudioTimelineFixture::json($spans, 5000.0));
    }
}
