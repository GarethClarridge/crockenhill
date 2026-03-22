<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportingStatePromotionSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_promoted_reporting_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('church_service_items', 'section_type'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_section_type_index'));

        $this->assertTrue(Schema::hasColumns('service_sections', [
            'song_match_type',
            'matched_item_id',
            'expected_item_id',
        ]));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_song_match_type_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_reporting_song_match_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_matched_item_id_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_expected_item_id_index'));
    }

    #[Test]
    public function promotion_migration_backfills_columns_from_legacy_json_and_inference_paths(): void
    {
        $manualItem = ChurchServiceItem::factory()->create([
            'type' => 'custom',
            'section_type' => null,
            'title' => 'Welcome and Notices',
            'metadata' => ['section_type' => ServiceSectionType::WELCOME->value],
        ]);

        $inferredItem = ChurchServiceItem::factory()->create([
            'type' => 'bibles',
            'section_type' => null,
            'title' => 'Psalm 23',
            'metadata' => null,
        ]);

        $matchedSection = ServiceSection::factory()->create([
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
                    'matched_item_id' => $manualItem->id,
                ],
            ],
        ]);

        $expectedSection = ServiceSection::factory()->create([
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'metadata' => [
                'oos_alignment' => [
                    'expected_item_id' => $inferredItem->id,
                ],
            ],
        ]);

        $invalidSection = ServiceSection::factory()->create([
            'song_match_type' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => 'definitely_invalid',
                    'matched_item_id' => 'not-an-id',
                ],
            ],
        ]);

        Schema::table('service_sections', function (Blueprint $table): void {
            $table->dropIndex('service_sections_reporting_song_match_index');
            $table->dropIndex('service_sections_song_match_type_index');
            $table->dropIndex('service_sections_matched_item_id_index');
            $table->dropIndex('service_sections_expected_item_id_index');
            $table->dropColumn(['song_match_type', 'matched_item_id', 'expected_item_id']);
        });

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropIndex('church_service_items_section_type_index');
            $table->dropColumn('section_type');
        });

        $migration = require database_path('migrations/2026_03_22_120000_promote_service_reporting_state_to_columns.php');
        ob_start();
        $migration->up();
        $auditOutput = (string) ob_get_clean();

        $this->assertSame(
            ServiceSectionType::WELCOME->value,
            DB::table('church_service_items')->where('id', $manualItem->id)->value('section_type')
        );
        $this->assertSame(
            ServiceSectionType::BIBLE_READING->value,
            DB::table('church_service_items')->where('id', $inferredItem->id)->value('section_type')
        );

        $this->assertSame(
            ServiceSectionSongMatchType::CONFIRMED->value,
            DB::table('service_sections')->where('id', $matchedSection->id)->value('song_match_type')
        );
        $this->assertSame(
            $manualItem->id,
            DB::table('service_sections')->where('id', $matchedSection->id)->value('matched_item_id')
        );
        $this->assertSame(
            $inferredItem->id,
            DB::table('service_sections')->where('id', $expectedSection->id)->value('expected_item_id')
        );
        $this->assertNull(DB::table('service_sections')->where('id', $invalidSection->id)->value('song_match_type'));
        $this->assertNull(DB::table('service_sections')->where('id', $invalidSection->id)->value('matched_item_id'));
        $this->assertStringContainsString(
            sprintf(
                '[TD-017A] service_sections.invalid_song_match_type rows=1 sample_ids=%d',
                $invalidSection->id
            ),
            $auditOutput
        );
        $this->assertStringContainsString(
            sprintf(
                '[TD-017A] service_sections.invalid_matched_item_id rows=1 sample_ids=%d',
                $invalidSection->id
            ),
            $auditOutput
        );
    }
}
