<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportingStatePromotionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The promoted columns (`church_service_items.section_type`,
     * `service_sections.song_match_type|matched_item_id|expected_item_id`) are written
     * and read by {@see promotion_migration_backfills_columns_from_legacy_json_and_inference_paths}.
     * Indexes have no behavioural witness, so this guardrail retains only the index assertions.
     */
    #[Test]
    public function it_creates_promoted_reporting_indexes(): void
    {
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_section_type_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_song_match_type_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_reporting_song_match_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_matched_item_id_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_expected_item_id_index'));
    }
}
