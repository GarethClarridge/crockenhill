<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SongIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_rejects_empty_song_title_at_database_level(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        try {
            DB::table('songs')->insert([
                'canonical_key' => 'test-key',
                'title' => '', // Invalid: empty string
                'lyrics_xml' => '<song></song>',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('songs_title_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for song title check constraint.');
    }

    /** @test */
    public function it_rejects_empty_song_canonical_key_at_database_level(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        try {
            DB::table('songs')->insert([
                'canonical_key' => '', // Invalid: empty string
                'title' => 'Test Song',
                'lyrics_xml' => '<song></song>',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('songs_canonical_key_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for song canonical key check constraint.');
    }

    /** @test */
    public function it_rejects_empty_song_lyrics_xml_at_database_level(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        try {
            DB::table('songs')->insert([
                'canonical_key' => 'test-key-2',
                'title' => 'Test Song 2',
                'lyrics_xml' => '', // Invalid: empty string
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('songs_lyrics_xml_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for song lyrics XML check constraint.');
    }

    /** @test */
    public function it_rejects_empty_author_display_name_at_database_level(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        try {
            DB::table('song_authors')->insert([
                'display_name' => '', // Invalid: empty string
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_authors_display_name_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for author display name check constraint.');
    }

    /** @test */
    public function it_rejects_empty_book_name_at_database_level(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        try {
            DB::table('song_books')->insert([
                'source_book_id' => 9999,
                'name' => '', // Invalid: empty string
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_books_name_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for book name check constraint.');
    }

    /** @test */
    public function it_allows_valid_song_data(): void
    {
        $song = Song::factory()->create([
            'title' => 'Valid Title',
            'canonical_key' => 'valid-key',
            'lyrics_xml' => '<song>Valid Lyrics</song>',
        ]);

        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'title' => 'Valid Title',
        ]);
    }

    /** @test */
    public function it_allows_valid_author_data(): void
    {
        $author = SongAuthor::factory()->create([
            'display_name' => 'Valid Author',
        ]);

        $this->assertDatabaseHas('song_authors', [
            'id' => $author->id,
            'display_name' => 'Valid Author',
        ]);
    }

    /** @test */
    public function it_allows_valid_book_data(): void
    {
        $book = SongBook::factory()->create([
            'name' => 'Valid Book',
        ]);

        $this->assertDatabaseHas('song_books', [
            'id' => $book->id,
            'name' => 'Valid Book',
        ]);
    }
}
