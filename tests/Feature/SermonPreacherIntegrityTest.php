<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPreacherIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preacher_attribute_is_trimmed_by_model_setter(): void
    {
        $sermon = Sermon::factory()->make([
            'preacher' => '  Mark Drury  ',
        ]);

        $this->assertEquals('Mark Drury', $sermon->preacher);

        $sermon->save();
        $this->assertEquals('Mark Drury', $sermon->refresh()->preacher);
    }

    #[Test]
    public function database_rejects_untrimmed_preacher_via_raw_query(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_preacher_format_check');

        DB::table('sermons')->insert([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => now()->toDateString(),
            'preacher' => '  Mark Drury  ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_preacher_via_raw_query(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_preacher_format_check');

        DB::table('sermons')->insert([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => now()->toDateString(),
            'preacher' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_accepts_valid_preacher_via_raw_query(): void
    {
        DB::table('sermons')->insert([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => now()->toDateString(),
            'preacher' => 'Mark Drury',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('sermons', [
            'preacher' => 'Mark Drury',
        ]);
    }
}
