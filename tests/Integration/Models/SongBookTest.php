<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Song;
use App\Models\SongBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongBookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_be_created(): void
    {
        $songBook = SongBook::factory()->create([
            'source_book_id' => 1234,
            'name' => 'Mission Praise',
            'publisher' => 'Marshall Pickering',
        ]);

        $this->assertDatabaseHas('song_books', [
            'id' => $songBook->id,
            'source_book_id' => 1234,
            'name' => 'Mission Praise',
            'publisher' => 'Marshall Pickering',
        ]);
    }

    #[Test]
    public function it_has_songs_relationship(): void
    {
        $songBook = SongBook::factory()->create();
        $song = Song::factory()->create();

        $songBook->songs()->attach($song->id, ['entry' => '512']);

        $this->assertTrue($songBook->songs->contains($song));
        $this->assertSame('512', $songBook->songs->first()?->pivot->entry);
    }

    #[Test]
    public function it_has_expected_fillable_attributes(): void
    {
        $songBook = new SongBook;

        $this->assertEquals([
            'source_book_id',
            'name',
            'publisher',
        ], $songBook->getFillable());
    }

    #[Test]
    public function it_casts_source_book_id_to_integer(): void
    {
        $songBook = SongBook::factory()->create([
            'source_book_id' => '999',
        ]);

        $this->assertSame(999, $songBook->source_book_id);
    }
}
