<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TextIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_title_is_automatically_trimmed()
    {
        $sermon = Sermon::factory()->create([
            'title' => '  Trimmed Title  ',
        ]);

        $this->assertEquals('Trimmed Title', $sermon->fresh()->title);
    }

    #[Test]
    public function sermon_series_is_automatically_trimmed_and_empty_becomes_null()
    {
        $sermon = Sermon::factory()->create([
            'series' => '  Trimmed Series  ',
        ]);

        $this->assertEquals('Trimmed Series', $sermon->fresh()->series);

        $sermon->series = '   ';
        $sermon->save();
        $this->assertNull($sermon->fresh()->series);
    }

    #[Test]
    public function preacher_name_is_automatically_trimmed()
    {
        $preacher = Preacher::factory()->create([
            'name' => '  Trimmed Preacher  ',
        ]);

        $this->assertEquals('Trimmed Preacher', $preacher->fresh()->name);
    }

    #[Test]
    public function page_heading_is_automatically_trimmed()
    {
        $page = Page::factory()->create([
            'heading' => '  Trimmed Heading  ',
        ]);

        $this->assertEquals('Trimmed Heading', $page->fresh()->heading);
    }

    #[Test]
    public function empty_sermon_title_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        // We use DB::table to bypass model mutators
        DB::table('sermons')->insert(
            array_merge(
                Sermon::factory()->make()->toArray(),
                [
                    'title' => '',
                    'points' => null,
                    'thumbnail_metadata' => null,
                ]
            )
        );
    }

    #[Test]
    public function untrimmed_sermon_title_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        DB::table('sermons')->insert(
            array_merge(
                Sermon::factory()->make()->toArray(),
                [
                    'title' => ' Untrimmed ',
                    'points' => null,
                    'thumbnail_metadata' => null,
                ]
            )
        );
    }

    #[Test]
    public function empty_preacher_name_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        DB::table('preachers')->insert(
            array_merge(
                Preacher::factory()->make()->toArray(),
                ['name' => '']
            )
        );
    }

    #[Test]
    public function untrimmed_preacher_name_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        DB::table('preachers')->insert(
            array_merge(
                Preacher::factory()->make()->toArray(),
                ['name' => ' Untrimmed ']
            )
        );
    }
}
