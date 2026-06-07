<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\LivestreamSectionToServiceItemMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ColumnPromotionIntegrityTest extends TestCase
{
    use RefreshDatabase;

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
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => 'confirmed',
                    'matched_item_id' => $item1->id,
                    'expected_item_id' => $item2->id,
                    'song_match_strategy' => 'title_hint',
                ],
            ],
        ]);

        $metadata = $section->metadata?->toArray() ?? [];
        $alignment = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];

        $this->assertArrayNotHasKey('song_match_type', $alignment);
        $this->assertArrayNotHasKey('matched_item_id', $alignment);
        $this->assertArrayNotHasKey('expected_item_id', $alignment);
        $this->assertSame('title_hint', $alignment['song_match_strategy']);
    }

    #[Test]
    public function livestream_projection_metadata_does_not_duplicate_promoted_provenance_columns(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'projection-column-authority',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Projected Song',
            'confidence' => 0.9,
            'source_segment_ids' => [10, 11],
        ]);

        $payloads = app(LivestreamSectionToServiceItemMapper::class)->map(
            ServiceSection::query()->whereKey($section->id)->get(),
            $processingLog->processing_id,
        );

        $projection = $payloads[0]['metadata']['livestream_projection'];

        $this->assertSame($processingLog->processing_id, $payloads[0]['livestream_processing_id']);
        $this->assertSame($section->id, $payloads[0]['livestream_service_section_id']);
        $this->assertArrayNotHasKey('processing_id', $projection);
        $this->assertArrayNotHasKey('service_section_id', $projection);
        $this->assertSame([10, 11], $projection['source_segment_ids']);
    }
}
