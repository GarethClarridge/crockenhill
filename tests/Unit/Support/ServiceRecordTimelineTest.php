<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceRecordTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceRecordTimelineTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // formatTimestamp
    // -------------------------------------------------------------------------

    #[Test]
    public function format_timestamp_converts_seconds_to_mm_ss(): void
    {
        $this->assertSame('0:00', ServiceRecordTimeline::formatTimestamp(0));
        $this->assertSame('0:30', ServiceRecordTimeline::formatTimestamp(30));
        $this->assertSame('1:00', ServiceRecordTimeline::formatTimestamp(60));
        $this->assertSame('1:05', ServiceRecordTimeline::formatTimestamp(65));
        $this->assertSame('10:00', ServiceRecordTimeline::formatTimestamp(600));
        $this->assertSame('61:01', ServiceRecordTimeline::formatTimestamp(3661));
    }

    #[Test]
    public function format_timestamp_rounds_fractional_seconds(): void
    {
        $this->assertSame('1:00', ServiceRecordTimeline::formatTimestamp(59.6));
        $this->assertSame('0:59', ServiceRecordTimeline::formatTimestamp(59.4));
    }

    // -------------------------------------------------------------------------
    // matched rows
    // -------------------------------------------------------------------------

    #[Test]
    public function matched_sections_are_linked_to_their_planned_items(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'type' => 'custom', 'title' => 'Welcome']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 120.0,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $items = $this->itemCollection([$item]);
        $rows = ServiceRecordTimeline::build($items, $run);

        $this->assertCount(1, $rows);
        $this->assertSame('matched', $rows[0]['row_type']);
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertSame('Welcome', $rows[0]['planned_title']);
        $this->assertSame(ServiceSectionType::WELCOME, $rows[0]['section_type']);
        $this->assertSame(0.0, $rows[0]['start_time']);
        $this->assertSame(120.0, $rows[0]['end_time']);
    }

    // -------------------------------------------------------------------------
    // mismatched rows
    // -------------------------------------------------------------------------

    #[Test]
    public function mismatched_section_emits_one_combined_row_with_both_sides(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 2, 'type' => 'custom', 'title' => 'Prayer']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'start_time' => 10.0,
            'end_time' => 80.0,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'expected_type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Prayer',
                    'expected_section_type' => ServiceSectionType::PRAYER->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $items = $this->itemCollection([$item]);
        $rows = ServiceRecordTimeline::build($items, $run);

        $this->assertCount(1, $rows);
        $this->assertSame('mismatched', $rows[0]['row_type']);
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertSame('Prayer', $rows[0]['planned_title']);
        $this->assertSame('expected_type_mismatch', $rows[0]['mismatch_reason']);
        $this->assertSame(ServiceSectionType::WELCOME, $rows[0]['section_type']);
        $this->assertSame(ServiceSectionType::PRAYER, $rows[0]['expected_section_type']);
    }

    #[Test]
    public function mismatched_row_consumes_its_active_item_so_it_is_not_also_planned_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Sermon']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Sermon',
                    'expected_section_type' => ServiceSectionType::SERMON->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertCount(1, $rows);
        $this->assertSame('mismatched', $rows[0]['row_type']);
    }

    // -------------------------------------------------------------------------
    // unplanned rows
    // -------------------------------------------------------------------------

    #[Test]
    public function sections_with_no_linked_item_appear_as_unplanned(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertCount(1, $rows);
        $this->assertSame('unplanned', $rows[0]['row_type']);
        $this->assertNull($rows[0]['item_id']);
    }

    // -------------------------------------------------------------------------
    // planned_only rows
    // -------------------------------------------------------------------------

    #[Test]
    public function planned_items_with_no_section_appear_as_planned_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Notices']);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertCount(1, $rows);
        $this->assertSame('planned_only', $rows[0]['row_type']);
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertSame('Notices', $rows[0]['planned_title']);
        $this->assertNull($rows[0]['section_id']);
    }

    // -------------------------------------------------------------------------
    // empty collection edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function empty_sections_collection_returns_all_planned_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item1 = ChurchServiceItem::factory()->create(['position' => 1]);
        $item2 = ChurchServiceItem::factory()->create(['position' => 2]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item1, $item2]), $run);

        $this->assertCount(2, $rows);
        $this->assertSame('planned_only', $rows[0]['row_type']);
        $this->assertSame('planned_only', $rows[1]['row_type']);
    }

    #[Test]
    public function empty_items_collection_returns_all_unplanned(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'metadata' => [],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('unplanned', $row['row_type']);
        }
    }

    // -------------------------------------------------------------------------
    // soft-deleted items
    // -------------------------------------------------------------------------

    #[Test]
    public function soft_deleted_linked_items_remain_visible_as_historical_planned_context(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Deleted Prayer']);
        $item->delete();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'metadata' => [],
        ]);

        $run->load(['serviceSections' => fn ($q) => $q->with(['churchServiceItem' => fn ($q2) => $q2->withTrashed(), 'publishedSermon'])]);

        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertCount(1, $rows);
        $this->assertSame('matched', $rows[0]['row_type']);
        $this->assertSame('soft_deleted', $rows[0]['planned_item_state']);
        $this->assertSame('Deleted Prayer', $rows[0]['planned_title']);
    }

    #[Test]
    public function soft_deleted_item_is_not_also_emitted_as_planned_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Deleted Item']);
        $item->delete();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'metadata' => [],
        ]);

        // Soft-deleted item is not in the active items collection
        $run->load(['serviceSections' => fn ($q) => $q->with(['churchServiceItem' => fn ($q2) => $q2->withTrashed(), 'publishedSermon'])]);

        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertCount(1, $rows);
        $this->assertNotSame('planned_only', $rows[0]['row_type']);
    }

    // -------------------------------------------------------------------------
    // item source and song data
    // -------------------------------------------------------------------------

    #[Test]
    public function item_source_is_included_in_row(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create([
            'source' => ChurchServiceItemSource::EMAIL,
            'position' => 1,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ChurchServiceItemSource::EMAIL, $rows[0]['item_source']);
    }

    #[Test]
    public function song_match_type_from_metadata_is_included_in_row(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $rows[0]['song_match_type']);
    }

    // -------------------------------------------------------------------------
    // publication status
    // -------------------------------------------------------------------------

    #[Test]
    public function publication_status_is_included_in_section_rows(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $rows[0]['publication_status']);
    }

    // -------------------------------------------------------------------------
    // ordering
    // -------------------------------------------------------------------------

    #[Test]
    public function sections_appear_before_planned_only_rows(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item1 = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Welcome']);
        $item2 = ChurchServiceItem::factory()->create(['position' => 2, 'title' => 'Undetected Prayer']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item1->id,
            'section_order' => 1,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item1, $item2]), $run);

        $this->assertCount(2, $rows);
        $this->assertSame('matched', $rows[0]['row_type']);
        $this->assertSame('planned_only', $rows[1]['row_type']);
        $this->assertSame('Undetected Prayer', $rows[1]['planned_title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<ChurchServiceItem>  $items
     * @return EloquentCollection<int, ChurchServiceItem>
     */
    private function itemCollection(array $items): EloquentCollection
    {
        return new EloquentCollection($items);
    }
}
