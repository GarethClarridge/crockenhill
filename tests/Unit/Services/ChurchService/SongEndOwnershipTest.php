<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\SongEndOutro;
use App\Services\ChurchService\SongTailLastLyric;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/** Saved levels, lyrics, decodes and classes measured in WP27; no media or database access. */
class SongEndOwnershipTest extends TestCase
{
    #[Test]
    public function word_ownership_never_revives_an_outro_rejected_under_e30(): void
    {
        $row = $this->fixture('1245/3050');
        $row['stems']['instruments_db'] = array_fill(0, count($row['stems']['instruments_db']), -25.0);

        $this->assertNull((new SongEndOutro)->endingFromTheVoiceTrack($row['stems'], $row['stems']['cut'], $row['ownership']));
    }

    #[Test]
    public function a_spoken_run_after_the_last_sung_word_is_left_out_on_the_voice_track_path(): void
    {
        $row = $this->fixture('1245/3050');
        $ending = (new SongEndOutro)->endingFromTheVoiceTrack($row['stems'], $row['stems']['cut'], $row['ownership']);

        $this->assertNotNull($ending);
        $this->assertSame(490.370, $ending['time']);
        $this->assertSame(0.1, $ending['fade_out']);
        $this->assertArrayNotHasKey('voice_removal', $ending);
    }

    #[Test]
    public function ownership_keeps_the_instrument_silence_when_neither_reading_removes_voice(): void
    {
        $row = $this->fixture('1304/3875');
        $reader = new SongEndOutro;
        $original = $reader->ending($row['stems'], $row['onset'], $row['stems']['cut']);
        $ending = $reader->ending($row['stems'], $row['onset'], $row['stems']['cut'], $row['ownership']);

        $this->assertSame($row['expected'], $original);
        $this->assertSame($original, $ending);
        $this->assertSame(1322.270, $ending['time']);
        $this->assertArrayNotHasKey('voice_removal', $ending);
    }

    #[Test]
    public function a_corroborated_final_lyric_keeps_the_original_e29_treatment(): void
    {
        $row = $this->fixture('1267/3342');
        $ending = (new SongEndOutro)->ending($row['stems'], $row['onset'], $row['stems']['cut'], $row['ownership']);

        $this->assertEquals($row['expected'], $ending);
        $this->assertSame(308.535, $ending['time']);
        $this->assertSame(1.7, $ending['fade_out']);
        $this->assertSame(306.835, $ending['voice_removal']['from']);
    }

    #[Test]
    #[TestWith(['1174/2336'])]
    #[TestWith(['1267/3353'])]
    #[TestWith(['1069/1696'])]
    #[TestWith(['964/94692'])]
    #[TestWith(['997/1199'])]
    #[TestWith(['1068/3704'])]
    public function other_ownership_treatments_stay_unchanged(string $key): void
    {
        $row = $this->fixture($key);
        $reader = new SongEndOutro;
        $ending = $row['voice_track']
            ? $reader->endingFromTheVoiceTrack($row['stems'], $row['stems']['cut'], $row['ownership'])
            : $reader->ending($row['stems'], $row['onset'], $row['stems']['cut'], $row['ownership']);

        $this->assertEquals($row['expected'], $ending);
    }

    #[Test]
    public function lyric_terms_normalise_only_the_for_ever_spelling_split(): void
    {
        $lyric = new SongTailLastLyric;

        $this->assertSame($lyric->terms('Who is forever near'), $lyric->terms("Who is for\never near"));
        $this->assertSame(['for', 'every', 'one', 'for', 'evermore', 'some', 'where'], $lyric->terms('for every one for evermore some where'));
    }

    #[Test]
    public function split_decoded_spelling_keeps_the_full_timed_span_and_the_same_lyric_evidence(): void
    {
        $lyric = new SongTailLastLyric;
        $row = $this->fixture('1267/3342');
        $context = $row['ownership'];
        $context['lyrics'] = str_replace('for ever', 'forever', $context['lyrics']);
        foreach ($context['decodes'] as &$decode) {
            $split = [];
            foreach ($decode['words'] as $word) {
                if ($lyric->term($word['word']) === 'forever') {
                    $middle = ($word['start'] + $word['end']) / 2;
                    $split[] = ['start' => $word['start'], 'end' => $middle, 'word' => 'for'];
                    $split[] = ['start' => $middle, 'end' => $word['end'], 'word' => 'ever'];
                } else {
                    $split[] = $word;
                }
            }
            $decode['words'] = $split;
        }
        unset($decode);

        $this->assertTrue($lyric->crossesOwnership($context, 303.070, 308.735, $row['stems']['start']));
        $this->assertSame([['start' => 1.0, 'end' => 3.0, 'word' => 'forever']], $lyric->normaliseWords([
            ['start' => 1.0, 'end' => 2.0, 'word' => ' For'], ['start' => 2.0, 'end' => 3.0, 'word' => ' ever'],
        ]));
        $unjoined = [['start' => 1.0, 'end' => 2.0, 'word' => 'some'], ['start' => 2.0, 'end' => 3.0, 'word' => 'where']];
        $this->assertSame($unjoined, $lyric->normaliseWords($unjoined));
    }

    #[Test]
    #[TestWith(['one_window'])]
    #[TestWith(['duplicate_window'])]
    #[TestWith(['no_music'])]
    #[TestWith(['next_line'])]
    #[TestWith(['no_lyric_match'])]
    public function the_lyric_guard_still_requires_independent_decodes_music_and_lyric_ownership(string $missing): void
    {
        $row = $this->fixture('1267/3342');
        $context = $row['ownership'];
        $lyric = new SongTailLastLyric;
        $this->assertTrue($lyric->crossesOwnership($context, 303.070, 308.735, $row['stems']['start']));
        if (in_array($missing, ['one_window', 'duplicate_window'], true)) {
            $decode = array_find($context['decodes'], static fn (array $decode): bool => abs($decode['start'] - 299.135) < 0.001);
            $context['decodes'] = $missing === 'one_window' ? [$decode] : [$decode, $decode];
        } elseif ($missing === 'no_music') {
            $context['timeline'] = array_map(static fn (array $window): array => [...$window, 'music' => 0.0], $context['timeline']);
        } elseif ($missing === 'next_line') {
            $context['next_line'] .= ' forever near';
        } else {
            $context['lyrics'] = 'Glory to God in the highest';
        }

        $this->assertFalse($lyric->crossesOwnership($context, 303.070, 308.735, $row['stems']['start']), $missing);
    }

    /** @return array<string, mixed> */
    private function fixture(string $key): array
    {
        $path = dirname(__DIR__, 3).'/Fixtures/ChurchService/song-end-ownership.json.gz';
        $rows = json_decode(gzdecode(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        return array_find($rows, static fn (array $row): bool => $row['key'] === $key);
    }
}
