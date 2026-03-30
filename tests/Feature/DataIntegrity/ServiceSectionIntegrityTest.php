<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceSectionIntegrityTest extends TestCase
{
    /** @test */
    public function it_enforces_foreign_key_on_matched_item_id()
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite foreign key enforcement varies by version/config.');
        }

        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['matched_item_id' => 999999]);
    }

    /** @test */
    public function it_nulls_out_matched_item_id_on_item_deletion()
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'matched_item_id' => $item->id,
        ]);

        $this->assertEquals($item->id, $section->fresh()->matched_item_id);

        $item->forceDelete();

        $this->assertNull($section->fresh()->matched_item_id);
    }
}
