<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonScriptureFilterIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bible_book_cannot_be_empty_in_validation(): void
    {
        $sermon = Sermon::factory()->create();

        $rules = SermonScriptureFilter::validationRules();

        $validator = Validator::make([
            'sermon_id' => $sermon->id,
            'bible_book' => '',
            'bible_chapter' => 1,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('bible_book', $validator->errors()->toArray());
    }

    #[Test]
    public function bible_chapter_must_be_positive_in_validation(): void
    {
        $sermon = Sermon::factory()->create();

        $rules = SermonScriptureFilter::validationRules();

        $validator = Validator::make([
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 0,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('bible_chapter', $validator->errors()->toArray());
    }

    #[Test]
    public function bible_book_cannot_be_empty_in_database(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level check constraints are verified on MySQL.');
        }

        $sermon = Sermon::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermon_scripture_filters_bible_book_format_check');

        DB::table('sermon_scripture_filters')->insert([
            'sermon_id' => $sermon->id,
            'bible_book' => '',
            'bible_chapter' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function bible_book_cannot_be_only_whitespace_in_database(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level check constraints are verified on MySQL.');
        }

        $sermon = Sermon::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermon_scripture_filters_bible_book_format_check');

        DB::table('sermon_scripture_filters')->insert([
            'sermon_id' => $sermon->id,
            'bible_book' => ' ',
            'bible_chapter' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function bible_chapter_must_be_greater_than_zero_in_database(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level check constraints are verified on MySQL.');
        }

        $sermon = Sermon::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermon_scripture_filters_bible_chapter_check');

        DB::table('sermon_scripture_filters')->insert([
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
