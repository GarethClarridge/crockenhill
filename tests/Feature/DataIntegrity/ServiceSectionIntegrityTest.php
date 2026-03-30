<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceSectionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_foreign_key_constraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Foreign key introspection not supported on SQLite in this test.');
        }

        $database = DB::getDatabaseName();

        $matchedFk = DB::table('information_schema.key_column_usage')
            ->where('table_schema', $database)
            ->where('table_name', 'service_sections')
            ->where('column_name', 'matched_item_id')
            ->where('referenced_table_name', 'church_service_items')
            ->exists();

        $expectedFk = DB::table('information_schema.key_column_usage')
            ->where('table_schema', $database)
            ->where('table_name', 'service_sections')
            ->where('column_name', 'expected_item_id')
            ->where('referenced_table_name', 'church_service_items')
            ->exists();

        $this->assertTrue($matchedFk, 'Foreign key constraint missing for matched_item_id');
        $this->assertTrue($expectedFk, 'Foreign key constraint missing for expected_item_id');
    }

    /** @test */
    public function it_enforces_foreign_key_on_matched_item_id(): void
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
    public function it_enforces_foreign_key_on_expected_item_id(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite foreign key enforcement varies by version/config.');
        }

        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['expected_item_id' => 999999]);
    }

    /** @test */
    public function it_nulls_out_matched_item_id_on_item_deletion(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'matched_item_id' => $item->id,
        ]);

        $this->assertEquals($item->id, $section->fresh()->matched_item_id);

        $item->forceDelete();

        $this->assertNull($section->fresh()->matched_item_id);
    }

    /** @test */
    public function it_nulls_out_expected_item_id_on_item_deletion(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'expected_item_id' => $item->id,
        ]);

        $this->assertEquals($item->id, $section->fresh()->expected_item_id);

        $item->forceDelete();

        $this->assertNull($section->fresh()->expected_item_id);
    }
}
