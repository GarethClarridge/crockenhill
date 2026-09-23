<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\SectionPublication\SongSectionWithoutSong;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A short song section with no song in it (song videos 74 → §622, 83 → §678).
 *
 * Measured 2026-09-16 (`sungpass-20260916-measure.json`, row d): 42 song sections run under
 * 60 s across 437 runs. 17 match nothing of their bound song. Most are an announcement ("let's
 * stand, shall we, and sing…") with the singing cut away; §678 and §3284 are the doxology sung
 * as a second copy of the hymn, a text no catalogue song holds.
 */
class SongSectionWithoutSongTest extends TestCase
{
    use RefreshDatabase;

    private const LORDS_MY_SHEPHERD = "The Lord's my shepherd, I'll not want; he makes me down to lie in pastures green; he leadeth me the quiet waters by. My soul he doth restore again, and me to walk doth make within the paths of righteousness, e'en for his own name's sake.";

    /** §622: the leader announces the song, and the section ends before anyone sings. */
    #[Test]
    public function it_flags_a_short_section_that_only_announces_its_song(): void
    {
        $observation = $this->observe("Well, let's endeavour, shall we, to sing a song. And the song is entitled The Lord's My Shepherd.", 19.9);

        $this->assertTrue($observation['risk']);
        $this->assertSame('announcement', $observation['basis']);
    }

    /** §678: the doxology after the hymn, which shares nothing with the bound hymn's words. */
    #[Test]
    public function it_flags_a_short_section_singing_something_else(): void
    {
        $observation = $this->observe('Praise him all creatures here below. Praise him above ye heavenly host.', 23.5);

        $this->assertTrue($observation['risk']);
        $this->assertSame('fragment', $observation['basis']);
    }

    /** §4310's shape: short, but the bound song's own lines are sung. */
    #[Test]
    public function it_leaves_a_short_section_singing_its_bound_song_alone(): void
    {
        $observation = $this->observe("Let's sing as we conclude. The Lord's my shepherd, I'll not want; he makes me down to lie in pastures green.", 16.4);

        $this->assertFalse($observation['risk']);
    }

    #[Test]
    public function it_does_not_judge_a_section_of_a_minute_or_more(): void
    {
        $this->assertNull($this->observe('Well, let us stand and sing.', 75.0));
    }

    /** No catalogue lyrics means no comparison, and silence here must not read as a finding. */
    #[Test]
    public function it_does_not_judge_a_song_without_catalogue_lyrics(): void
    {
        $this->assertNull($this->observe('Well, let us stand and sing.', 20.0, lyrics: null));
    }

    /**
     * @return array{basis: string, seconds: float, word_pairs: int, risk: bool, detail: string}|null
     */
    private function observe(string $text, float $seconds, ?string $lyrics = self::LORDS_MY_SHEPHERD): ?array
    {
        $song = Song::factory()->create(['title' => "The Lord's My Shepherd #23B", 'lyrics_plain' => $lyrics]);
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'metadata' => ['transcript_song_match' => ['song_id' => $song->id, 'title' => $song->title, 'confidence' => 1.0, 'match_source' => 'title_hint_catalogue_title']],
            'start_time' => 1000.0,
            'end_time' => 1000.0 + $seconds,
        ]);

        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 990.0, 'end' => 999.0, 'text' => 'Amen.'],
            ['start' => 1001.0, 'end' => 1000.0 + $seconds - 1.0, 'text' => $text],
        ], 2000.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        return (new SongSectionWithoutSong)->observe($section, $transcript);
    }
}
