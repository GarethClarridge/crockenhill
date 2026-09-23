<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ChurchServiceTranscript;
use App\Models\Song;
use App\Services\Song\SongLyricIdentityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongLyricIdentityCheckTest extends TestCase
{
    use RefreshDatabase;

    private const AMAZING_GRACE = 'Amazing grace how sweet the sound that saved a wretch like me. I once was lost but now am found, was blind but now I see. Twas grace that taught my heart to fear and grace my fears relieved. How precious did that grace appear the hour I first believed.';

    private const BE_THOU_MY_VISION = 'Be thou my vision O Lord of my heart, naught be all else to me save that thou art. Thou my best thought by day or by night, waking or sleeping thy presence my light. Be thou my wisdom and thou my true word, I ever with thee and thou with me Lord.';

    private Song $boundSong;

    private Song $rivalSong;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundSong = Song::factory()->create(['title' => 'Be Thou My Vision', 'lyrics_plain' => self::BE_THOU_MY_VISION]);
        $this->rivalSong = Song::factory()->create(['title' => 'Amazing Grace', 'lyrics_plain' => self::AMAZING_GRACE]);
        Song::factory()->create(['title' => 'Crown Him With Many Crowns', 'lyrics_plain' => 'Crown him with many crowns, the Lamb upon his throne. Hark how the heavenly anthem drowns all music but its own.']);
    }

    #[Test]
    public function it_reports_a_contradiction_when_another_song_is_clearly_what_was_sung(): void
    {
        $result = (new SongLyricIdentityCheck)->assess($this->sungTranscript(self::AMAZING_GRACE), 100.0, 300.0, $this->boundSong->id);

        $this->assertSame(SongLyricIdentityCheck::CONTRADICTED, $result['verdict']);
        $this->assertSame($this->rivalSong->id, $result['rival_song_id']);
        $this->assertSame('Amazing Grace', $result['rival_title']);
        $this->assertGreaterThanOrEqual(4, $result['rival_score']['word_pairs']);
        $this->assertSame(0, $result['bound_score']['word_pairs']);
    }

    #[Test]
    public function it_reports_consistency_when_the_bound_song_is_what_was_sung(): void
    {
        $result = (new SongLyricIdentityCheck)->assess($this->sungTranscript(self::BE_THOU_MY_VISION), 100.0, 300.0, $this->boundSong->id);

        $this->assertSame(SongLyricIdentityCheck::CONSISTENT, $result['verdict']);
        $this->assertNull($result['rival_song_id']);
    }

    #[Test]
    public function it_does_not_judge_a_section_whose_transcript_heard_too_few_words(): void
    {
        $result = (new SongLyricIdentityCheck)->assess($this->sungTranscript('Amazing grace how sweet the sound'), 100.0, 300.0, $this->boundSong->id);

        $this->assertSame(SongLyricIdentityCheck::INSUFFICIENT_WORDS, $result['verdict']);
    }

    #[Test]
    public function a_rival_that_only_edges_the_bound_song_does_not_contradict_it(): void
    {
        // The bound song is sung in full and a couple of lines of another song
        // are heard too: the rival must win clearly, not merely appear.
        $sung = self::BE_THOU_MY_VISION.' Amazing grace how sweet the sound that saved a wretch like me.';

        $result = (new SongLyricIdentityCheck)->assess($this->sungTranscript($sung), 100.0, 300.0, $this->boundSong->id);

        $this->assertSame(SongLyricIdentityCheck::CONSISTENT, $result['verdict']);
    }

    #[Test]
    public function it_ignores_the_announcement_and_the_section_tail(): void
    {
        // The leader reads the other song's words over the introduction and
        // the next item starts in the last seconds: neither was sung here.
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 101.0, 'end' => 107.0, 'text' => self::AMAZING_GRACE],
            ['start' => 120.0, 'end' => 200.0, 'text' => self::BE_THOU_MY_VISION],
            ['start' => 297.0, 'end' => 300.0, 'text' => self::AMAZING_GRACE],
        ], 600.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        $result = (new SongLyricIdentityCheck)->assess($transcript, 100.0, 300.0, $this->boundSong->id);

        $this->assertSame(SongLyricIdentityCheck::CONSISTENT, $result['verdict']);
    }

    private function sungTranscript(string $sung): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 60.0, 'end' => 90.0, 'text' => 'Let us stand and sing together.'],
            ['start' => 120.0, 'end' => 280.0, 'text' => $sung],
        ], 600.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }
}
