<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_title_and_type_via_model_setters(): void
    {
        $item = new ChurchServiceItem([
            'title' => '  Trimmed Title  ',
            'type' => '  song  ',
        ]);

        $this->assertEquals('Trimmed Title', $item->title);
        $this->assertEquals('song', $item->type);
    }

    #[Test]
    public function it_trims_and_nullifies_optional_strings_via_model_setters(): void
    {
        $item = new ChurchServiceItem([
            'source_title' => '  Source Title  ',
            'openlp_search_title' => '    ',
        ]);

        $this->assertEquals('Source Title', $item->source_title);
        $this->assertNull($item->openlp_search_title);
    }

    #[Test]
    public function database_enforces_trimmed_and_non_empty_title(): void
    {
        $service = ChurchService::factory()->create();

        // Valid
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'title' => 'Valid Title',
        ]);

        // Invalid: Untrimmed
        $this->expectException(QueryException::class);
        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'song',
            'title' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_enforces_non_empty_title(): void
    {
        $service = ChurchService::factory()->create();

        // Invalid: Empty
        $this->expectException(QueryException::class);
        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'song',
            'title' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_enforces_trimmed_and_non_empty_type(): void
    {
        $service = ChurchService::factory()->create();

        // Invalid: Untrimmed type
        $this->expectException(QueryException::class);
        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => ' song ',
            'title' => 'Valid Title',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_enforces_trimmed_source_title_if_not_null(): void
    {
        $service = ChurchService::factory()->create();

        // Invalid: Untrimmed source_title
        $this->expectException(QueryException::class);
        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'song',
            'title' => 'Valid Title',
            'source_title' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_enforces_trimmed_openlp_search_title_if_not_null(): void
    {
        $service = ChurchService::factory()->create();

        // Invalid: Untrimmed openlp_search_title
        $this->expectException(QueryException::class);
        DB::table('church_service_items')->insert([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'song',
            'title' => 'Valid Title',
            'openlp_search_title' => ' Untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function validation_rules_pass_for_valid_data(): void
    {
        $service = ChurchService::factory()->create();

        $data = [
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'song',
            'title' => 'Valid Title',
        ];

        $validator = Validator::make($data, ChurchServiceItem::validationRules());
        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function validation_rules_fail_for_missing_required_fields(): void
    {
        $data = [
            'position' => 1,
        ];

        $validator = Validator::make($data, ChurchServiceItem::validationRules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('church_service_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_fail_for_invalid_foreign_keys(): void
    {
        $data = [
            'church_service_id' => 999999,
            'position' => 1,
            'type' => 'song',
            'title' => 'Valid Title',
            'song_id' => 999999,
        ];

        $validator = Validator::make($data, ChurchServiceItem::validationRules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('church_service_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('song_id', $validator->errors()->toArray());
    }
}
