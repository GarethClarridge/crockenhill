<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

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
    public function it_trims_names_automatically_via_model_attributes(): void
    {
        $author = new SongAuthor([
            'display_name' => '  John Doe  ',
            'first_name' => '  John  ',
            'last_name' => '  Doe  ',
        ]);

        $this->assertEquals('John Doe', $author->display_name);
        $this->assertEquals('John', $author->first_name);
        $this->assertEquals('Doe', $author->last_name);
    }

    #[Test]
    public function it_prevents_empty_display_name_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        // Using DB::table to bypass Eloquent attribute setters
        DB::table('song_authors')->insert([
            'display_name' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_prevents_untrimmed_display_name_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        // Using DB::table to bypass Eloquent attribute setters
        DB::table('song_authors')->insert([
            'display_name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_prevents_untrimmed_first_name_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        // Using DB::table to bypass Eloquent attribute setters
        DB::table('song_authors')->insert([
            'display_name' => 'Valid Name',
            'first_name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_prevents_untrimmed_last_name_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        // Using DB::table to bypass Eloquent attribute setters
        DB::table('song_authors')->insert([
            'display_name' => 'Valid Name',
            'last_name' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_validates_display_name_uniqueness(): void
    {
        SongAuthor::factory()->create(['display_name' => 'Unique Author']);

        $rules = SongAuthor::validationRules();
        $validator = Validator::make(['display_name' => 'Unique Author'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = SongAuthor::validationRules();
        $validator = Validator::make(['display_name' => ''], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_null_first_and_last_names(): void
    {
        $author = SongAuthor::create([
            'display_name' => 'Minimal Author',
            'first_name' => null,
            'last_name' => null,
        ]);

        $this->assertDatabaseHas('song_authors', [
            'id' => $author->id,
            'display_name' => 'Minimal Author',
            'first_name' => null,
            'last_name' => null,
        ]);
    }
}
