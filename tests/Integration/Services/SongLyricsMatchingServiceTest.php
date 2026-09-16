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

    // ---- Title hint resolution ----

    /**
     * §991 (run 974, song video 137): the detector heard "Rock Of Ages" and the
     * clip was published as "O Safe To The Rock That Is Higher Than I", which
     * quotes the phrase in its fourth line.
     *
     * Both songs' lyrics contain "rock of ages", so {@see bestWindowScore()}
     * returns 1.0 for each on bare containment and the first row scanned wins.
     * The song the hint actually names is catalogued as "rock of ages 705", so
     * the exact-key rung misses it over the trailing Praise! number and never
     * gets the chance to beat the quotation. A confidence of 1.0 then clears the
     * 0.75 write-back threshold, so the wrong song is recorded as Confirmed.
     */
    #[Test]
    public function it_prefers_the_song_a_hint_names_over_another_song_quoting_the_phrase(): void
    {
        $quotingSong = Song::factory()->create([
            'title' => 'O Safe To The Rock That Is Higher Than I #887',
            'canonical_key' => '887 o safe to the rock that is higher than i',
            'praise_number' => '887',
            'alternate_title' => null,
            'first_line_key' => 'o safe to the rock that is higher than i',
            'lyrics_plain' => "O safe to the Rock that is higher than I\nMy soul, in its conflicts and sorrows, would fly;\nThough sinful and weary, my vows I renew;\nO blessed Rock of ages, I'm hiding in you.",
        ]);

        $namedSong = Song::factory()->create([
            'title' => 'Rock Of Ages #705',
            'canonical_key' => 'rock of ages 705',
            'praise_number' => '705',
            'alternate_title' => '#705 Rock Of Ages',
            'first_line_key' => 'rock of ages, cleft for me,',
            'lyrics_plain' => "Rock of ages, cleft for me,\nHide me now, my refuge be;\nLet the water and the blood,\nFrom your wounded side which flowed,",
        ]);

        $result = $this->service->matchTitleHint('Rock Of Ages');

        $this->assertSame(
            $namedSong->id,
            $result['song_id'],
            'A song whose catalogued title is the hint must beat a song that merely quotes it.',
        );
        $this->assertNotSame($quotingSong->id, $result['song_id']);
        $this->assertSame('title_hint_catalogue_title', $result['match_source']);
    }

    /**
     * §519, §1288, §2782, §2929, §3024, §3191 and §3769 (7 sections): the hint
     * "God of Glory" is Praise! 244's own title, but "Almighty Lord Most High
     * Draw Near #823" closes on "the God of glory, grace and love" and is
     * scanned first. The same containment tie, reached through a hymn's own
     * title rather than through a quotation of another hymn's.
     */
    #[Test]
    public function it_prefers_a_hymns_own_numbered_title_to_a_later_verse_elsewhere(): void
    {
        $quotingSong = Song::factory()->create([
            'title' => 'Almighty Lord Most High Draw Near #823',
            'canonical_key' => 'almighty lord most high draw near 823',
            'praise_number' => '823',
            'alternate_title' => '#823 Almighty Lord Most High Draw Near',
            'first_line_key' => 'almighty lord most high, draw near,',
            'lyrics_plain' => "Almighty Lord most high, draw near,\nwhose awesome splendour none can bear;\n\nand sing through everlasting days\nthe God of glory, grace and love.",
        ]);

        $namedSong = Song::factory()->create([
            'title' => 'God Of Glory #244',
            'canonical_key' => 'god of glory 244',
            'praise_number' => '244',
            'alternate_title' => '#244 God Of Glory',
            'first_line_key' => 'god of glory, we exalt your name,',
            'lyrics_plain' => "God of glory, we exalt Your name,\nYou who reign in majesty.\nWe lift our hearts to You\nAnd we will worship, praise and magnify\nYour holy name.",
        ]);

        $result = $this->service->matchTitleHint('God of Glory');

        $this->assertSame($namedSong->id, $result['song_id']);
        $this->assertNotSame($quotingSong->id, $result['song_id']);
    }

    /**
     * The fallback this must not cost us. "The Servant King" is not a catalogued
     * title or alternate title anywhere — it is a line inside "From Heaven You
     * Came #396" ("This is our God, the Servant King"), which is the hymn the
     * congregation sang. §1284 (run 1011) resolves this way today and must keep
     * doing so: the deterministic rungs have nothing to say about it.
     */
    #[Test]
    public function it_still_matches_a_lyric_phrase_that_names_no_catalogue_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'From Heaven You Came #396',
            'canonical_key' => 'from heaven you came 396',
            'praise_number' => '396',
            'alternate_title' => '#396 From Heaven You Came',
            'first_line_key' => 'from heaven you came, helpless babe,',
            'lyrics_plain' => "From heaven You came, helpless babe,\nEntered our world, Your glory veiled;\nNot to be served but to serve,\nAnd give Your life that we might live.\n\nThis is our God, the Servant King,\nHe calls us now to follow Him,",
        ]);

        $result = $this->service->matchTitleHint('The Servant King');

        $this->assertSame($song->id, $result['song_id']);
    }

    /**
     * The same fallback for a refrain rather than a verse line: §928 (run 970)
     * heard "It Is Well with My Soul", the refrain of "When Peace Like A River
     * #804", which is how that hymn is catalogued.
     */
    #[Test]
    public function it_still_matches_a_refrain_that_names_no_catalogue_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'When Peace Like A River #804',
            'canonical_key' => 'when peace like a river 804',
            'praise_number' => '804',
            'alternate_title' => '#804 When Peace Like A River',
            'first_line_key' => 'when peace, like a river,',
            'lyrics_plain' => "When peace, like a river,\nattends all my way,\nWhen sorrows like sea-billows roll,\nWhatever my path,\nYou have taught me to say,\n'It is well, it is well with my soul.'",
        ]);

        $result = $this->service->matchTitleHint('It Is Well with My Soul');

        $this->assertSame($song->id, $result['song_id']);
    }

    /**
     * §1588 and §3580 heard "How Great Thou Art", a hymn catalogued under its
     * first line as "O Lord My God #190". Nothing deterministic connects the two
     * names, so the lyrics fallback is the only thing that resolves it.
     */
    #[Test]
    public function it_still_matches_a_hymn_catalogued_under_its_first_line(): void
    {
        $song = Song::factory()->create([
            'title' => 'O Lord My God #190',
            'canonical_key' => 'o lord my god 190',
            'praise_number' => '190',
            'alternate_title' => '#190 O Lord My God',
            'first_line_key' => 'o lord my god!',
            'lyrics_plain' => "O Lord my God!\nWhen I in awesome wonder\nConsider all the works\nThy hand hath made,\n\nThen sings my soul,\nmy Saviour God to Thee,\nHow great Thou art!\nHow great Thou art!",
        ]);

        $result = $this->service->matchTitleHint('How Great Thou Art');

        $this->assertSame($song->id, $result['song_id']);
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
