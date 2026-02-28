<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canonicalize_key_trims_lowers_and_collapses_whitespace_without_stripping_symbols(): void
    {
        $result = Song::canonicalizeKey('  Who   Am I@Who   You Say I Am  ');

        $this->assertSame('who am i@who you say i am', $result);
    }

    #[Test]
    public function song_has_expected_relationships(): void
    {
        $song = Song::factory()->create();
        $author = SongAuthor::factory()->create([
            'display_name' => 'John Writer',
        ]);
        $book = SongBook::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $song->authors()->attach($author->id, ['author_type' => 'words']);
        $song->books()->attach($book->id, ['entry' => '32']);

        $song->refresh();

        $this->assertCount(1, $song->authors);
        $this->assertSame('words', $song->authors->first()?->pivot->author_type);
        $this->assertCount(1, $song->books);
        $this->assertSame('32', $song->books->first()?->pivot->entry);
        $this->assertCount(1, $song->churchServiceItems);
        $this->assertSame($song->id, $item->song?->id);
    }
}
