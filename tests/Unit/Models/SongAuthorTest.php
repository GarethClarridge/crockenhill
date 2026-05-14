<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Song;
use App\Models\SongAuthor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongAuthorTest extends TestCase
{
    use DatabaseTransactions;

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

    #[Test]
    public function it_trims_names_automatically(): void
    {
        $author = new SongAuthor([
            'display_name' => '  John Newton  ',
            'first_name' => '  John  ',
            'last_name' => '  Newton  ',
        ]);

        $this->assertEquals('John Newton', $author->display_name);
        $this->assertEquals('John', $author->first_name);
        $this->assertEquals('Newton', $author->last_name);
    }

    #[Test]
    public function it_converts_empty_names_to_null(): void
    {
        $author = new SongAuthor([
            'display_name' => 'John Newton',
            'first_name' => '   ',
            'last_name' => '',
        ]);

        $this->assertNull($author->first_name);
        $this->assertNull($author->last_name);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = SongAuthor::validationRules();

        $this->assertTrue(Validator::make(['display_name' => 'John Newton'], $rules)->passes());
        $this->assertFalse(Validator::make(['display_name' => ''], $rules)->passes());
        $this->assertFalse(Validator::make([], $rules)->passes());
    }

    #[Test]
    public function it_enforces_database_integrity_via_check_constraints(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database CHECK constraints are only verified on MySQL.');
        }

        // Test display_name cannot be empty (handled by CHECK constraint display_name != '')
        try {
            DB::table('song_authors')->insert([
                'display_name' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException was not thrown for empty display_name.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_authors_display_name_format_check', $e->getMessage());
        }

        // Test display_name must be trimmed
        try {
            DB::table('song_authors')->insert([
                'display_name' => ' untrimmed ',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException was not thrown for untrimmed display_name.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_authors_display_name_format_check', $e->getMessage());
        }

        // Test first_name must be trimmed if not null
        try {
            DB::table('song_authors')->insert([
                'display_name' => 'Valid Name',
                'first_name' => ' untrimmed ',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException was not thrown for untrimmed first_name.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_authors_first_name_format_check', $e->getMessage());
        }

        // Test first_name cannot be empty (must be NULL instead)
        try {
            DB::table('song_authors')->insert([
                'display_name' => 'Valid Name',
                'first_name' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException was not thrown for empty first_name.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('song_authors_first_name_format_check', $e->getMessage());
        }
    }
}
