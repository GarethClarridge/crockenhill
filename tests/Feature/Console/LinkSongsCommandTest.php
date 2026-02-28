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
}
