<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongCatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function database_rejects_song_with_empty_title(): void
    {
        $this->expectException(QueryException::class);

        Song::factory()->create([
            'title' => '',
        ]);
    }

    #[Test]
    public function database_rejects_song_with_empty_canonical_key(): void
    {
        $this->expectException(QueryException::class);

        Song::factory()->create([
            'canonical_key' => '',
        ]);
    }

    #[Test]
    public function database_rejects_song_with_empty_lyrics_xml(): void
    {
        $this->expectException(QueryException::class);

        Song::factory()->create([
            'lyrics_xml' => '',
        ]);
    }

    #[Test]
    public function database_rejects_song_author_with_empty_display_name(): void
    {
        $this->expectException(QueryException::class);

        SongAuthor::factory()->create([
            'display_name' => '',
        ]);
    }

    #[Test]
    public function database_rejects_song_book_with_empty_name(): void
    {
        $this->expectException(QueryException::class);

        SongBook::factory()->create([
            'name' => '',
        ]);
    }

    #[Test]
    public function database_accepts_valid_song_catalog_data(): void
    {
        $song = Song::factory()->create([
            'title' => 'Valid Title',
            'canonical_key' => 'valid-key',
            'lyrics_xml' => '<song>Valid Lyrics</song>',
        ]);

        $author = SongAuthor::factory()->create([
            'display_name' => 'Valid Author',
        ]);

        $book = SongBook::factory()->create([
            'name' => 'Valid Book',
        ]);

        $this->assertDatabaseHas('songs', ['id' => $song->id]);
        $this->assertDatabaseHas('song_authors', ['id' => $author->id]);
        $this->assertDatabaseHas('song_books', ['id' => $book->id]);
    }
}
