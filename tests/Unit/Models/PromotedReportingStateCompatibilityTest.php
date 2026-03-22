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
    public function church_service_item_semantic_section_type_supports_column_and_legacy_fallbacks(): void
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
        ]);

        $legacyInference = ChurchServiceItem::factory()->make([
            'type' => 'bibles',
            'section_type' => null,
            'metadata' => null,
            'title' => 'Psalm 121',
        ]);

        $this->assertSame(ServiceSectionType::WELCOME, $columnBacked->semanticSectionType());
        $this->assertSame(ServiceSectionType::SERMON, $legacyMetadata->semanticSectionType());
        $this->assertSame(ServiceSectionType::BIBLE_READING, $legacyInference->semanticSectionType());
    }

    #[Test]
    public function service_section_promoted_readers_support_column_and_legacy_json_shapes(): void
    {
        $columnBacked = ServiceSection::factory()->make([
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'matched_item_id' => 12,
            'expected_item_id' => 21,
            'metadata' => ['oos_alignment' => []],
        ]);

        $legacyJson = ServiceSection::factory()->make([
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => ServiceSectionSongMatchType::INFERRED->value,
                    'matched_item_id' => 33,
                    'expected_item_id' => 44,
                ],
            ],
        ]);

        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $columnBacked->songMatchType());
        $this->assertSame(12, $columnBacked->matchedItemId());
        $this->assertSame(21, $columnBacked->expectedItemId());

        $this->assertSame(ServiceSectionSongMatchType::INFERRED, $legacyJson->songMatchType());
        $this->assertSame(33, $legacyJson->matchedItemId());
        $this->assertSame(44, $legacyJson->expectedItemId());
    }
}
