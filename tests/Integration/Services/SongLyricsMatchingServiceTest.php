<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Song;
use App\Services\Song\SongLyricsMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongLyricsMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SongLyricsMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SongLyricsMatchingService;
        Config::set('media-processing.song_matching.lyrics_threshold', 0.6);
    }

    // ---- Empty / blank input ----

    #[Test]
    public function it_returns_no_match_for_empty_transcript(): void
    {
        $result = $this->service->matchFromLyrics('');

        $this->assertNull($result['song_id']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertNull($result['matched_title']);
    }

    #[Test]
    public function it_returns_no_match_for_whitespace_only_transcript(): void
    {
        $result = $this->service->matchFromLyrics('   ');

        $this->assertNull($result['song_id']);
    }

    // ---- Canonical key lookup on first line ----

    #[Test]
    public function it_matches_exactly_by_canonical_key_on_first_line(): void
    {
        $song = Song::factory()->create([
            'title' => 'Amazing Grace',
            'canonical_key' => 'amazing grace',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics("Amazing Grace\nhow sweet the sound");

        $this->assertSame($song->id, $result['song_id']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame('Amazing Grace', $result['matched_title']);
    }

    #[Test]
    public function it_matches_an_oh_variant_to_an_o_catalogued_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'O Jesus I Have Promised',
            'canonical_key' => 'o jesus i have promised',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics("Oh Jesus I Have Promised\nto serve thee to the end");

        $this->assertSame($song->id, $result['song_id']);
        $this->assertSame(1.0, $result['confidence']);
    }

    #[Test]
    public function it_is_case_insensitive_for_canonical_key_lookup(): void
    {
        $song = Song::factory()->create([
            'title' => 'Great Is Thy Faithfulness',
            'canonical_key' => 'great is thy faithfulness',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics('GREAT IS THY FAITHFULNESS');

        $this->assertSame($song->id, $result['song_id']);
    }

    #[Test]
    public function it_returns_no_match_when_canonical_key_does_not_exist(): void
    {
        Song::factory()->create([
            'canonical_key' => 'some other song',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics("Unknown Song Title\nsome lyrics here");

        // No canonical match and no lyrics to fuzzy-match against.
        $this->assertNull($result['song_id']);
    }

    // ---- First-line-key shortcut ----

    #[Test]
    public function it_matches_a_first_line_key_opening_by_default(): void
    {
        $song = Song::factory()->create([
            'title' => 'His Mercy Is More',
            'canonical_key' => 'his mercy is more',
            'first_line_key' => 'what love could remember no wrongs we have done',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics('What love could remember no wrongs we have done');

        $this->assertSame($song->id, $result['song_id']);
        $this->assertSame(0.95, $result['confidence']);
    }

    #[Test]
    public function it_skips_the_first_line_key_shortcut_for_untrusted_probes(): void
    {
        // An OCR frame sampled mid-song whose first visible line happens to be
        // another song's opening. With the shortcut disallowed it must not be
        // returned as a 0.95 match — fuzzy matching gets the whole text instead.
        Song::factory()->create([
            'title' => 'His Mercy Is More',
            'canonical_key' => 'his mercy is more',
            'first_line_key' => 'what love could remember no wrongs we have done',
            'lyrics_plain' => null,
        ]);

        $result = $this->service->matchFromLyrics(
            'What love could remember no wrongs we have done',
            allowFirstLineKeyMatch: false
        );

        $this->assertNull($result['song_id']);
    }

    // ---- Fuzzy lyrics matching ----

    #[Test]
    public function it_matches_via_fuzzy_lyrics_when_first_line_has_no_canonical_key(): void
    {
        $song = Song::factory()->create([
            'title' => 'How Great Thou Art',
            'canonical_key' => 'how great thou art',
            'lyrics_plain' => "O Lord my God when I in awesome wonder\nConsider all the worlds thy hands have made",
        ]);

        // Transcript is slightly different from lyrics — should still match above threshold.
        $transcript = 'O Lord my God when I in awesome wonder consider all the world';
        $result = $this->service->matchFromLyrics($transcript);

        $this->assertSame($song->id, $result['song_id']);
        $this->assertGreaterThanOrEqual(0.6, $result['confidence']);
        $this->assertSame('How Great Thou Art', $result['matched_title']);
    }

    #[Test]
    public function it_returns_no_match_when_lyrics_score_is_below_threshold(): void
    {
        Song::factory()->create([
            'title' => 'Unrelated Song',
            'canonical_key' => 'unrelated song',
            'lyrics_plain' => 'Completely different lyrics about something else entirely unrelated',
        ]);

        $result = $this->service->matchFromLyrics('xyz abc qrs completely different content here');

        $this->assertNull($result['song_id']);
    }

    #[Test]
    public function it_ignores_songs_without_lyrics_plain_in_fuzzy_matching(): void
    {
        Song::factory()->create([
            'title' => 'Song Without Lyrics',
            'canonical_key' => 'song without lyrics',
            'lyrics_plain' => null,
        ]);

        // Even if the transcript happened to be similar, no lyrics to compare against.
        $result = $this->service->matchFromLyrics('Song Without Lyrics opening verse here');

        $this->assertNull($result['song_id']);
    }

    #[Test]
    public function it_picks_the_highest_scoring_song_when_multiple_candidates_exist(): void
    {
        Song::factory()->create([
            'title' => 'Weak Match Song',
            'canonical_key' => 'weak match song',
            'lyrics_plain' => 'Barely related lyrics that do not really match at all',
        ]);

        $bestSong = Song::factory()->create([
            'title' => 'Strong Match Song',
            'canonical_key' => 'strong match song',
            'lyrics_plain' => 'The Lord is my shepherd I shall not want he leads me beside still waters',
        ]);

        $transcript = 'The Lord is my shepherd I shall not want he leads me beside still waters';
        $result = $this->service->matchFromLyrics($transcript);

        $this->assertSame($bestSong->id, $result['song_id']);
    }

    #[Test]
    public function it_matches_lyrics_sampled_from_a_later_verse(): void
    {
        // Real-world case: the OCR frame captures verse 2 of "My Jesus My Saviour",
        // which starts well past the first 200 characters of lyrics_plain.
        $song = Song::factory()->create([
            'title' => 'My Jesus My Saviour',
            'canonical_key' => 'my jesus my saviour',
            'lyrics_plain' => "My Jesus, my Saviour,\nLord, there is none like You.\nAll of my days I want to praise\nThe wonders of Your mighty love.\n\nMy comfort, my shelter,\nTower of refuge and strength,\nLet every breath, all that I am,\nNever cease to worship You.\n\nShout to the Lord all the earth, let us sing\nPower and majesty, praise to the King",
        ]);

        $verseTwoOcr = "My comfort, my shelter,\nTower of refuge and strength,\nLet every breath, all that I am,\nNever cease to worship You.";

        $result = $this->service->matchFromLyrics($verseTwoOcr);

        $this->assertSame($song->id, $result['song_id']);
        $this->assertGreaterThanOrEqual(0.6, $result['confidence']);
    }

    #[Test]
    public function it_does_not_match_a_later_verse_against_an_unrelated_song(): void
    {
        Song::factory()->create([
            'title' => 'Unrelated Hymn',
            'canonical_key' => 'unrelated hymn',
            'lyrics_plain' => "Guide me O thou great Jehovah\nPilgrim through this barren land\nI am weak but thou art mighty\nHold me with thy powerful hand\nBread of heaven, bread of heaven\nFeed me till I want no more",
        ]);

        $result = $this->service->matchFromLyrics("My comfort, my shelter,\nTower of refuge and strength");

        $this->assertNull($result['song_id']);
    }

    // ---- Configurable threshold ----

    #[Test]
    public function it_respects_a_higher_threshold_and_rejects_low_confidence_matches(): void
    {
        Config::set('media-processing.song_matching.lyrics_threshold', 0.95);

        Song::factory()->create([
            'title' => 'Partial Match',
            'canonical_key' => 'partial match',
            'lyrics_plain' => 'The Lord is my shepherd I shall not want he leads me',
        ]);

        // Transcript shares words but is not verbatim, so it cannot reach 0.95 similarity.
        $result = $this->service->matchFromLyrics('The Lord he is my shepherd and so I shall never be in want');

        $this->assertNull($result['song_id']);
    }

    // ---- Confidence value is returned ----

    #[Test]
    public function it_returns_a_confidence_value_between_zero_and_one_for_fuzzy_matches(): void
    {
        Song::factory()->create([
            'title' => 'To God Be the Glory',
            'canonical_key' => 'to god be the glory',
            'lyrics_plain' => 'To God be the glory great things he hath taught us great his mercy and great his grace',
        ]);

        $result = $this->service->matchFromLyrics('To God be the glory great things he hath taught us');

        if ($result['song_id'] !== null) {
            $this->assertGreaterThan(0.0, $result['confidence']);
            $this->assertLessThanOrEqual(1.0, $result['confidence']);
        }
    }
}
