<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\SongBook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Database\QueryException;

class SongBookIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function name_must_be_trimmed_and_non_empty_in_validation(): void
    {
        $invalidNames = [
            '',             // Empty
            ' ',            // Whitespace only
            ' Leading',     // Leading whitespace
            'Trailing ',    // Trailing whitespace
            " Newline\n",   // Newline
        ];

        foreach ($invalidNames as $name) {
            $validator = Validator::make(
                ['name' => $name, 'source_book_id' => 1],
                SongBook::validationRules()
            );

            $this->assertTrue($validator->fails(), "Validation should have failed for name: '{$name}'");
            $this->assertArrayHasKey('name', $validator->errors()->toArray());
        }
    }

    #[Test]
    public function publisher_must_be_trimmed_if_provided_in_validation(): void
    {
        $invalidPublishers = [
            ' ',            // Whitespace only
            ' Leading',     // Leading whitespace
            'Trailing ',    // Trailing whitespace
        ];

        foreach ($invalidPublishers as $publisher) {
            $data = ['name' => 'Valid Name', 'source_book_id' => 1, 'publisher' => $publisher];
            $validator = Validator::make(
                $data,
                SongBook::validationRules()
            );

            $this->assertTrue($validator->fails(), "Validation should have failed for publisher: '{$publisher}'");
            $this->assertArrayHasKey('publisher', $validator->errors()->toArray());
        }
    }

    #[Test]
    public function it_allows_valid_data_in_validation(): void
    {
        $validator = Validator::make(
            ['name' => 'Valid Name', 'source_book_id' => 1, 'publisher' => 'Valid Publisher'],
            SongBook::validationRules()
        );

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_allows_null_publisher_in_validation(): void
    {
        $validator = Validator::make(
            ['name' => 'Valid Name', 'source_book_id' => 1, 'publisher' => null],
            SongBook::validationRules()
        );

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function database_rejects_untrimmed_name(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_books_name_format_check');

        // Bypassing model attribute setters by using raw DB insertion
        \Illuminate\Support\Facades\DB::table('song_books')->insert([
            'source_book_id' => 1001,
            'name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_name(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_books_name_format_check');

        \Illuminate\Support\Facades\DB::table('song_books')->insert([
            'source_book_id' => 1002,
            'name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_publisher(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_books_publisher_format_check');

        \Illuminate\Support\Facades\DB::table('song_books')->insert([
            'source_book_id' => 1003,
            'name' => 'Valid Name',
            'publisher' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_allows_null_publisher(): void
    {
        \Illuminate\Support\Facades\DB::table('song_books')->insert([
            'source_book_id' => 1004,
            'name' => 'Valid Name',
            'publisher' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('song_books', [
            'source_book_id' => 1004,
            'name' => 'Valid Name',
            'publisher' => null,
        ]);
    }

    #[Test]
    public function model_attribute_setter_trims_name(): void
    {
        $songBook = new SongBook();
        $songBook->name = '  Trim Me  ';

        $this->assertSame('Trim Me', $songBook->name);
    }

    #[Test]
    public function model_attribute_setter_trims_publisher(): void
    {
        $songBook = new SongBook();
        $songBook->publisher = '  Trim Me  ';

        $this->assertSame('Trim Me', $songBook->publisher);
    }

    #[Test]
    public function model_attribute_setter_converts_empty_publisher_to_null(): void
    {
        $songBook = new SongBook();
        $songBook->publisher = '   ';

        $this->assertNull($songBook->publisher);
    }
}
