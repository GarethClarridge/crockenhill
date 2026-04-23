<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ColumnPromotionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function church_services_table_has_pending_structure_merge_source_column(): void
    {
        $this->assertTrue(Schema::hasColumn('church_services', 'pending_structure_merge_source'));
    }

    #[Test]
    public function church_service_items_table_has_livestream_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('church_service_items', 'livestream_processing_id'));
        $this->assertTrue(Schema::hasColumn('church_service_items', 'livestream_service_section_id'));
    }

    #[Test]
    public function service_sections_table_has_promoted_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('service_sections', 'confidence'));
        $this->assertTrue(Schema::hasColumn('service_sections', 'song_match_type'));
        $this->assertTrue(Schema::hasColumn('service_sections', 'matched_item_id'));
        $this->assertTrue(Schema::hasColumn('service_sections', 'expected_item_id'));
    }

    #[Test]
    public function pending_merge_source_column_is_nullable_string(): void
    {
        $service = ChurchService::factory()->create([
            'pending_structure_merge_source' => null,
        ]);

        $this->assertNull($service->fresh()->pending_structure_merge_source);

        $service->update(['pending_structure_merge_source' => 'UPLOAD']);

        $this->assertSame('UPLOAD', $service->fresh()->pending_structure_merge_source);
    }

    #[Test]
    public function livestream_columns_are_nullable(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'livestream_processing_id' => null,
            'livestream_service_section_id' => null,
        ]);

        $this->assertNull($item->fresh()->livestream_processing_id);
        $this->assertNull($item->fresh()->livestream_service_section_id);
    }

    #[Test]
    public function livestream_service_section_id_has_foreign_key_to_service_sections(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create();

        $item->update(['livestream_service_section_id' => $section->id]);

        $this->assertDatabaseHas('church_service_items', [
            'id' => $item->id,
            'livestream_service_section_id' => $section->id,
        ]);

        $section->delete();

        $this->assertNull($item->fresh()->livestream_service_section_id);
    }

    #[Test]
    public function section_oos_alignment_does_not_contain_json_keys(): void
    {
        $item1 = ChurchServiceItem::factory()->create();
        $item2 = ChurchServiceItem::factory()->create();

        $section = ServiceSection::factory()->create([
            'song_match_type' => null,
            'matched_item_id' => $item1->id,
            'expected_item_id' => $item2->id,
        ]);

        $metadata = is_array($section->metadata) ? $section->metadata : [];

        $this->assertArrayNotHasKey('song_match_type', $metadata);
        $this->assertArrayNotHasKey('matched_item_id', $metadata);
        $this->assertArrayNotHasKey('expected_item_id', $metadata);
    }
}
