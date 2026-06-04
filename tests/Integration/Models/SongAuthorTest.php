<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Song;
use App\Models\SongAuthor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongAuthorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_be_created(): void
    {
        $author = SongAuthor::factory()->create([
            'display_name' => 'John Newton',
            'first_name' => 'John',
            'last_name' => 'Newton',
        ]);

        $this->assertDatabaseHas('song_authors', [
            'id' => $author->id,
            'display_name' => 'John Newton',
            'first_name' => 'John',
            'last_name' => 'Newton',
        ]);
    }

    #[Test]
    public function it_has_songs_relationship(): void
    {
        $author = SongAuthor::factory()->create();
        $song = Song::factory()->create();

        $author->songs()->attach($song->id, ['author_type' => 'lyricist']);

        $this->assertTrue($author->songs->contains($song));
        $this->assertEquals('lyricist', $author->songs->first()->pivot->author_type);
    }

    #[Test]
    public function it_has_expected_fillable_attributes(): void
    {
        $author = SongAuthor::factory()->make();

        $this->assertEquals([
            'display_name',
            'first_name',
            'last_name',
        ], $author->getFillable());
    }
}
