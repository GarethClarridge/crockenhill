<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\SongBook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongBookIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_name_and_publisher_automatically(): void
    {
        $book = SongBook::factory()->create([
            'name' => '  Baptist Hymnal  ',
            'publisher' => '  ABC Publishing  ',
        ]);

        $this->assertEquals('Baptist Hymnal', $book->name);
        $this->assertEquals('ABC Publishing', $book->publisher);

        $this->assertDatabaseHas('song_books', [
            'id' => $book->id,
            'name' => 'Baptist Hymnal',
            'publisher' => 'ABC Publishing',
        ]);
    }

    #[Test]
    public function it_converts_empty_publisher_to_null(): void
    {
        $book = SongBook::factory()->create([
            'publisher' => '   ',
        ]);

        $this->assertNull($book->publisher);
        $this->assertDatabaseHas('song_books', [
            'id' => $book->id,
            'publisher' => null,
        ]);
    }

    #[Test]
    public function database_rejects_empty_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_books')->insert([
            'source_book_id' => 999,
            'name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_books')->insert([
            'source_book_id' => 999,
            'name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_publisher(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_books')->insert([
            'source_book_id' => 999,
            'name' => 'Valid Name',
            'publisher' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_publisher(): void
    {
        $this->expectException(QueryException::class);

        DB::table('song_books')->insert([
            'source_book_id' => 999,
            'name' => 'Valid Name',
            'publisher' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function validation_rejects_empty_name(): void
    {
        $rules = SongBook::validationRules();
        $validator = Validator::make(['name' => ''], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_untrimmed_name(): void
    {
        $rules = SongBook::validationRules();
        $validator = Validator::make(['name' => ' Untrimmed '], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_whitespace_only_publisher(): void
    {
        $rules = SongBook::validationRules();
        $validator = Validator::make([
            'name' => 'Valid Name',
            'publisher' => '   ',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('publisher', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rejects_duplicate_source_book_id(): void
    {
        SongBook::factory()->create(['source_book_id' => 123]);

        $rules = SongBook::validationRules();
        $validator = Validator::make([
            'name' => 'New Book',
            'source_book_id' => 123,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('source_book_id', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_allows_existing_source_book_id_when_updating_same_book(): void
    {
        $book = SongBook::factory()->create(['source_book_id' => 123]);

        $rules = SongBook::validationRules($book);
        $validator = Validator::make([
            'name' => 'Existing Book',
            'source_book_id' => 123,
        ], $rules);

        $this->assertFalse($validator->fails());
    }
}
