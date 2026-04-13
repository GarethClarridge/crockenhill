<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Church\Songs\BrowseSongs;
use App\Models\Song;
use App\Models\User;
use App\Services\PublicSongCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongCatalogLyricSearchTest extends TestCase
{
    // RefreshDatabase is required here because InnoDB full-text indexes are only
    // queryable via MATCH AGAINST after rows are committed — DatabaseTransactions
    // wraps inserts in an uncommitted transaction that the FTS buffer never flushes.
    use RefreshDatabase;

    private PublicSongCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PublicSongCatalogService::class);
    }

    /**
     * Force InnoDB to flush its full-text insert buffer so newly committed rows
     * are immediately reachable via MATCH AGAINST queries.
     */
    private function flushFullTextIndex(): void
    {
        DB::statement('OPTIMIZE TABLE songs');
    }

    // ── lyric search ──────────────────────────────────────────────────────

    #[Test]
    public function search_matches_by_lyrics(): void
    {
        $unique = 'xyzzylyric'.uniqid();
        $song = Song::factory()->create(['lyrics_plain' => "First line\n{$unique}\nLast line"]);
        $this->flushFullTextIndex();

        $ids = $this->service->query('all', $unique)->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function lyric_match_does_not_include_songs_without_the_term(): void
    {
        $unique = 'xyzzylyric'.uniqid();
        Song::factory()->create(['lyrics_plain' => "First line\n{$unique}\nLast line"]);
        $other = Song::factory()->create(['lyrics_plain' => 'Completely different content']);
        $this->flushFullTextIndex();

        $ids = $this->service->query('all', $unique)->pluck('id');

        $this->assertFalse($ids->contains($other->id));
    }

    // ── two-bucket ordering ───────────────────────────────────────────────

    #[Test]
    public function title_matches_appear_before_lyric_only_matches(): void
    {
        $unique = 'xyzzyorder'.uniqid();

        $lyricSong = Song::factory()->create([
            'title' => 'Unrelated Title One',
            'lyrics_plain' => "First line\n{$unique}\nLast line",
        ]);

        $titleSong = Song::factory()->create([
            'title' => $unique,
            'lyrics_plain' => 'Some other lyrics without the term',
        ]);

        $this->flushFullTextIndex();

        $ids = $this->service->query('all', $unique)->pluck('id')->values();

        $titlePos = $ids->search($titleSong->id);
        $lyricPos = $ids->search($lyricSong->id);

        $this->assertNotFalse($titlePos, 'Title-match song should appear in results');
        $this->assertNotFalse($lyricPos, 'Lyric-match song should appear in results');
        $this->assertLessThan($lyricPos, $titlePos, 'Title match should rank before lyric-only match');
    }

    // ── Livewire snippet rendering ────────────────────────────────────────

    #[Test]
    public function lyric_match_shows_snippet_block(): void
    {
        $unique = 'xyzzysnippet'.uniqid();
        Song::factory()->create([
            'title' => 'Unrelated Title',
            'lyrics_plain' => "First line\nA line with {$unique} inside\nLast line",
        ]);
        $this->flushFullTextIndex();

        $this->actingAs(User::factory()->create());

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->set('search', $unique)
            ->assertSee('Matching lyric lines', false)
            ->assertSee($unique);
    }

    #[Test]
    public function mixed_title_and_lyric_match_still_shows_snippet_block(): void
    {
        $unique = 'xyzzymixed'.uniqid();
        // Token appears in both title and lyrics — snippets must still be shown.
        Song::factory()->create([
            'title' => "Song about {$unique}",
            'lyrics_plain' => "A verse mentioning {$unique} here\nAnother line",
        ]);
        $this->flushFullTextIndex();

        $this->actingAs(User::factory()->create());

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->set('search', $unique)
            ->assertSee('Matching lyric lines', false)
            ->assertSee($unique);
    }

    #[Test]
    public function title_only_match_shows_no_snippet_block(): void
    {
        $unique = 'xyzzytitleonly'.uniqid();
        Song::factory()->create([
            'title' => $unique,
            'lyrics_plain' => 'Completely unrelated lyrics with nothing matching',
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->set('search', $unique)
            ->assertSee($unique)
            ->assertDontSee('Matching lyric lines', false);
    }

    #[Test]
    public function highlighted_snippets_contain_mark_tags(): void
    {
        $unique = 'xyzzymark'.uniqid();
        Song::factory()->create([
            'title' => 'Some Other Title',
            'lyrics_plain' => "A line with {$unique} in it",
        ]);
        $this->flushFullTextIndex();

        $this->actingAs(User::factory()->create());

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->set('search', $unique)
            ->assertSee('<mark>', false);
    }
}
