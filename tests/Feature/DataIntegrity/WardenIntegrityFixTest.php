<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WardenIntegrityFixTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_scripture_passage_referential_integrity(): void
    {
        $passage = ScripturePassage::factory()->create();
        $sermon = Sermon::factory()->create(['scripture_passage_id' => $passage->id]);

        $this->assertEquals($passage->id, $sermon->fresh()->scripture_passage_id);

        $passage->delete();

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    #[Test]
    public function it_prevents_untrimmed_titles_in_church_service_items(): void
    {
        $service = ChurchService::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('church_service_items_title_format_check');

        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => '  untrimmed title  ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_prevents_empty_titles_in_church_service_items(): void
    {
        $service = ChurchService::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('church_service_items_title_format_check');

        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_prevents_untrimmed_types_in_church_service_items(): void
    {
        $service = ChurchService::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('church_service_items_type_format_check');

        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => ' untrimmed type ',
            'title' => 'Valid Title',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_defers_position_uniqueness_to_the_database_constraint(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
        ]);

        $validator = Validator::make(
            ['church_service_id' => $service->id, 'position' => 1, 'title' => 'Test', 'type' => 'custom'],
            ChurchServiceItem::validationRules(churchServiceId: $service->id)
        );

        $this->assertFalse($validator->fails());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('church_service_items_active_position_unique');

        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'source' => null,
            'title' => 'Duplicate position',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
