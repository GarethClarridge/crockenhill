<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageDescriptionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_description_on_model_save(): void
    {
        $page = Page::factory()->create([
            'description' => '  untrimmed description  ',
        ]);

        $this->assertEquals('untrimmed description', $page->description);

        $this->assertEquals(
            'untrimmed description',
            DB::table('pages')->where('id', $page->id)->value('description')
        );
    }

    #[Test]
    public function it_handles_null_description_in_model(): void
    {
        $page = new Page;
        $page->description = null;

        $this->assertNull($page->description);
    }

    #[Test]
    public function it_handles_empty_string_description_in_model(): void
    {
        $page = new Page;
        $page->description = '   ';

        $this->assertNull($page->description);
    }

    #[Test]
    public function it_rejects_empty_description_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Check constraint 'pages_description_format_check' is violated");

        DB::table('pages')->insert([
            'slug' => 'test-empty-desc',
            'heading' => 'Test',
            'description' => '',
            'area' => 'christ',
            'body' => 'test',
            'navigation' => 0,
        ]);
    }

    #[Test]
    public function it_rejects_untrimmed_description_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Check constraint 'pages_description_format_check' is violated");

        DB::table('pages')->insert([
            'slug' => 'test-untrimmed-desc',
            'heading' => 'Test',
            'description' => ' untrimmed ',
            'area' => 'christ',
            'body' => 'test',
            'navigation' => 0,
        ]);
    }
}
