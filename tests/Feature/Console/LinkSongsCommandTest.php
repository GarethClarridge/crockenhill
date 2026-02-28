<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkSongsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_links_song_items_when_command_runs(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'song one@',
            'title' => 'Song One',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'Song One@',
            'song_id' => null,
        ]);

        $this->artisan('service-tracking:link-songs')
            ->assertExitCode(0)
            ->expectsOutputToContain('Song linking run completed.');

        $item->refresh();

        $this->assertSame($song->id, $item->song_id);
    }

    #[Test]
    public function it_backfills_multiple_historical_song_items_using_canonical_keys_with_at_suffixes(): void
    {
        $songOne = Song::factory()->create([
            'canonical_key' => 'who am i that the highest king@who you say i am',
        ]);
        $songTwo = Song::factory()->create([
            'canonical_key' => 'stand up stand up for jesus@',
        ]);

        $itemOne = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => ' Who Am I That The Highest King@Who You Say I Am ',
            'song_id' => null,
        ]);
        $itemTwo = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'Stand Up Stand Up For Jesus@',
            'song_id' => null,
        ]);

        $this->artisan('service-tracking:link-songs')
            ->assertExitCode(0);

        $itemOne->refresh();
        $itemTwo->refresh();

        $this->assertSame($songOne->id, $itemOne->song_id);
        $this->assertSame($songTwo->id, $itemTwo->song_id);
    }

    #[Test]
    public function dry_run_does_not_persist_song_links(): void
    {
        Song::factory()->create([
            'canonical_key' => 'song one@',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'song one@',
            'song_id' => null,
        ]);

        $this->artisan('service-tracking:link-songs', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run enabled. No database changes were written.');

        $item->refresh();

        $this->assertNull($item->song_id);
    }

    #[Test]
    public function it_clears_stale_song_links_when_no_match_exists(): void
    {
        $staleSong = Song::factory()->create([
            'canonical_key' => 'legacy key@',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'missing key@',
            'song_id' => $staleSong->id,
        ]);

        $this->artisan('service-tracking:link-songs')
            ->assertExitCode(0);

        $item->refresh();

        $this->assertNull($item->song_id);
    }
}
