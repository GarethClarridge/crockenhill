<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDownloadCountIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_has_download_count_column_with_default_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('sermons', 'download_count'), 'The sermons table should have a download_count column.');

        $sermon = Sermon::factory()->create();
        $this->assertSame(0, $sermon->download_count, 'The default download_count should be 0.');
    }

    #[Test]
    public function sermon_can_save_positive_download_count(): void
    {
        $sermon = Sermon::factory()->create(['download_count' => 10]);
        $this->assertEquals(10, $sermon->download_count);

        $sermon->update(['download_count' => 20]);
        $this->assertEquals(20, $sermon->fresh()->download_count);
    }

    #[Test]
    public function database_enforces_non_negative_download_count(): void
    {
        $sermon = Sermon::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Raw DB update to bypass model/validation logic and test DB constraint
        \Illuminate\Support\Facades\DB::table('sermons')
            ->where('id', $sermon->id)
            ->update(['download_count' => -1]);
    }
}
