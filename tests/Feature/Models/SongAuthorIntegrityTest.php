<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\SongAuthor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongAuthorIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_trims_names_automatically(): void
    {
        $author = SongAuthor::factory()->create([
            'display_name' => '  John Doe  ',
            'first_name' => '  John  ',
            'last_name' => '  Doe  ',
        ]);

        $this->assertEquals('John Doe', $author->display_name);
        $this->assertEquals('John', $author->first_name);
        $this->assertEquals('Doe', $author->last_name);

        $this->assertDatabaseHas('song_authors', [
            'id' => $author->id,
            'display_name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    #[Test]
    public function it_handles_null_first_and_last_names(): void
    {
        $author = SongAuthor::factory()->create([
            'display_name' => 'John Doe',
            'first_name' => null,
            'last_name' => null,
        ]);

        $this->assertNull($author->first_name);
        $this->assertNull($author->last_name);

        $this->assertDatabaseHas('song_authors', [
            'id' => $author->id,
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    #[Test]
    public function database_rejects_empty_display_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_display_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_first_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => 'John Smith',
            'first_name' => ' John ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_first_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => 'John Smith',
            'first_name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_last_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => 'John Smith',
            'last_name' => ' Smith ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_last_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_authors')->insert([
            'display_name' => 'John Smith',
            'last_name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function validation_rejects_empty_display_name(): void
    {
        $rules = SongAuthor::validationRules();
        $validator = Validator::make(['display_name' => ''], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_untrimmed_display_name(): void
    {
        $rules = SongAuthor::validationRules();
        $validator = Validator::make(['display_name' => ' John Doe '], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_whitespace_only_first_name(): void
    {
        $rules = SongAuthor::validationRules();
        $validator = Validator::make([
            'display_name' => 'John Doe',
            'first_name' => '   ',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('first_name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_whitespace_only_last_name(): void
    {
        $rules = SongAuthor::validationRules();
        $validator = Validator::make([
            'display_name' => 'John Doe',
            'last_name' => '   ',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('last_name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_duplicate_display_name(): void
    {
        SongAuthor::factory()->create(['display_name' => 'Unique Author']);

        $rules = SongAuthor::validationRules();
        $validator = Validator::make(['display_name' => 'Unique Author'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_allows_existing_display_name_when_updating_same_author(): void
    {
        $author = SongAuthor::factory()->create(['display_name' => 'Existing Author']);

        $rules = SongAuthor::validationRules($author);
        $validator = Validator::make(['display_name' => 'Existing Author'], $rules);

        $this->assertFalse($validator->fails());
    }
}
