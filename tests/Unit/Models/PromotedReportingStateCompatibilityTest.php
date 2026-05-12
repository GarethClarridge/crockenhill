<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromotedReportingStateCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function church_service_item_semantic_section_type_reads_columns_before_inference(): void
    {
        $columnBacked = ChurchServiceItem::factory()->make([
            'type' => 'custom',
            'section_type' => ServiceSectionType::WELCOME->value,
            'metadata' => null,
        ]);

        $legacyMetadata = ChurchServiceItem::factory()->make([
            'type' => 'custom',
            'section_type' => null,
            'metadata' => ['section_type' => ServiceSectionType::SERMON->value],
            'title' => 'Welcome',
        ]);

        $legacyInference = ChurchServiceItem::factory()->make([
            'type' => 'bibles',
            'section_type' => null,
            'metadata' => null,
            'title' => 'Psalm 121',
        ]);

        $this->assertSame(ServiceSectionType::WELCOME, $columnBacked->semanticSectionType());
        $this->assertSame(ServiceSectionType::WELCOME, $legacyMetadata->semanticSectionType());
        $this->assertSame(ServiceSectionType::BIBLE_READING, $legacyInference->semanticSectionType());
    }

    #[Test]
    public function service_section_promoted_fields_read_from_columns_only(): void
    {
        $columnBacked = ServiceSection::factory()->make([
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'matched_item_id' => 12,
            'expected_item_id' => 21,
            'metadata' => ['oos_alignment' => []],
        ]);

        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $columnBacked->song_match_type);
        $this->assertSame(12, $columnBacked->matched_item_id);
        $this->assertSame(21, $columnBacked->expected_item_id);
        $this->assertTrue($columnBacked->hasConfirmedSongMatch());
    }
}
