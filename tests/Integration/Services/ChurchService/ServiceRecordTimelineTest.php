<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\ServiceRecordTimeline;
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
            'section_type' => ServiceSectionType::Welcome->value,
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
        $this->assertSame(ServiceSectionType::Welcome, $rows[0]['section_type']);
        $this->assertSame(0.0, $rows[0]['start_time']);
        $this->assertSame(120.0, $rows[0]['end_time']);
    }

    // -------------------------------------------------------------------------
    // mismatched rows
    // -------------------------------------------------------------------------

    #[Test]
    public function mismatched_section_emits_one_combined_row_with_both_sides(): void
    {
        // Production shape: markMismatch() does NOT set church_service_item_id.
        // Planned context comes from metadata['oos_alignment']['expected_item_*'] only.
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 2, 'type' => 'custom', 'title' => 'Prayer']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'start_time' => 10.0,
            'end_time' => 80.0,
            'expected_item_id' => $item->id,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'expected_type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Prayer',
                    'expected_section_type' => ServiceSectionType::Prayer->value,
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
        $this->assertSame(ServiceSectionType::Welcome, $rows[0]['section_type']);
        $this->assertSame(ServiceSectionType::Prayer, $rows[0]['expected_section_type']);
        $this->assertSame(1, $rows[0]['section_order']);
    }

    #[Test]
    public function mismatched_row_consumes_its_active_item_so_it_is_not_also_planned_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Sermon']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'expected_item_id' => $item->id,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Sermon',
                    'expected_section_type' => ServiceSectionType::Sermon->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertCount(1, $rows);
        $this->assertSame('mismatched', $rows[0]['row_type']);
    }

    #[Test]
    public function mismatched_section_with_deleted_expected_item_shows_historical_planned_context(): void
    {
        // P1 regression: expected item deleted after alignment — must still show as mismatched,
        // not silently collapse to unplanned.
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1, 'title' => 'Deleted Sermon']);
        $deletedId = $item->id;
        $item->delete();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 3,
            'expected_item_id' => $deletedId,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'oos_type_mismatch',
                    'expected_item_id' => $deletedId,
                    'expected_item_title' => 'Deleted Sermon',
                    'expected_section_type' => ServiceSectionType::Sermon->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        // Active items collection is empty because the item was deleted
        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertCount(1, $rows);
        $this->assertSame('mismatched', $rows[0]['row_type']);
        $this->assertSame($deletedId, $rows[0]['item_id']);
        $this->assertSame('Deleted Sermon', $rows[0]['planned_title']);
        $this->assertSame('metadata_only', $rows[0]['planned_item_state']);
        $this->assertSame('oos_type_mismatch', $rows[0]['mismatch_reason']);
    }

    #[Test]
    public function section_order_is_included_in_section_rows(): void
    {
        // P2 regression: section_order must be in the row so the blade can show
        // livestream position for unplanned and metadata-only mismatch rows.
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 4,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build(new EloquentCollection, $run);

        $this->assertSame(4, $rows[0]['section_order']);
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
            'section_type' => ServiceSectionType::Other->value,
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

    #[Test]
    public function linked_song_sections_prefer_the_detected_display_title_over_the_catalogue_title(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $song = Song::factory()->create(['title' => 'Jesus Shall Take The Highest Honour #305']);
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'Jesus Shall Take The Highest Honour',
            'song_id' => $song->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'metadata' => ['song_title' => 'Jesus Shall Take The Highest Honour'],
        ]);

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);
        $item->load('song');

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame('Jesus Shall Take The Highest Honour', $rows[0]['song_title']);
        $this->assertSame('Jesus Shall Take The Highest Honour #305', $song->title);
    }

    #[Test]
    public function detected_song_title_uses_the_sections_own_match_over_a_mismatched_plan_item(): void
    {
        // The section confidently detected "Great Is The Lord", but it was aligned
        // to a plan item whose linked song is a different song entirely. The detected
        // column must reflect what was heard, not the plan item's song.
        $run = MediaProcessingLog::factory()->livestream()->create();
        $plannedSong = Song::factory()->create(['title' => 'God is for us']);
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $plannedSong->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Great Is The Lord',
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);
        $item->load('song');

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame('Great Is The Lord', $rows[0]['song_title']);
        $this->assertSame('God is for us', $rows[0]['planned_title']);
    }

    #[Test]
    public function detected_song_title_falls_back_to_the_plan_item_song_when_the_section_is_unmatched(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $plannedSong = Song::factory()->create(['title' => 'God is for us']);
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $plannedSong->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => null,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);
        $item->load('song');

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame('God is for us', $rows[0]['song_title']);
    }

    #[Test]
    public function a_song_planned_once_but_detected_twice_flags_the_excess_occurrence(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $song = Song::factory()->create(['title' => 'God is for us']);
        $plannedItem = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $song->id,
        ]);

        foreach ([1, 2] as $order) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $run->id,
                'church_service_item_id' => null,
                'section_type' => ServiceSectionType::Song->value,
                'section_order' => $order,
                'title' => 'God is for us',
                'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
                'metadata' => ['song_id' => $song->id],
            ]);
        }

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$plannedItem]), $run);

        // First occurrence is within the planned count — not flagged.
        $this->assertNotSame('mismatched', $rows[0]['row_type']);
        $this->assertNull($rows[0]['mismatch_reason']);

        // Second occurrence exceeds the plan — flagged as a mismatch.
        $this->assertSame('mismatched', $rows[1]['row_type']);
        $this->assertSame('Sung 2 times in the recording but planned 1', $rows[1]['mismatch_reason']);
    }

    #[Test]
    public function a_song_sung_as_often_as_planned_is_not_flagged(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $song = Song::factory()->create(['title' => 'God is for us']);
        $plannedItems = [
            ChurchServiceItem::factory()->create(['position' => 1, 'type' => 'songs', 'title' => 'God is for us', 'song_id' => $song->id]),
            ChurchServiceItem::factory()->create(['position' => 2, 'type' => 'songs', 'title' => 'God is for us', 'song_id' => $song->id]),
        ];

        foreach ([1, 2] as $order) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $run->id,
                'church_service_item_id' => null,
                'section_type' => ServiceSectionType::Song->value,
                'section_order' => $order,
                'title' => 'God is for us',
                'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
                'metadata' => ['song_id' => $song->id],
            ]);
        }

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection($plannedItems), $run);

        $this->assertNull($rows[0]['mismatch_reason']);
        $this->assertNull($rows[1]['mismatch_reason']);
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
            'section_type' => ServiceSectionType::Prayer->value,
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
            'section_type' => ServiceSectionType::Other->value,
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
            'source' => ChurchServiceItemSource::Email,
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

        $this->assertSame(ChurchServiceItemSource::Email, $rows[0]['item_source']);
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
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $rows[0]['song_match_type']);
    }

    #[Test]
    public function song_match_type_from_promoted_column_is_included_in_row(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $rows[0]['song_match_type']);
    }

    #[Test]
    public function mismatched_section_uses_promoted_expected_item_id_when_json_id_is_absent(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 2, 'type' => 'custom', 'title' => 'Prayer']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'expected_item_id' => $item->id,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'expected_type_mismatch',
                    'expected_item_title' => 'Prayer',
                    'expected_section_type' => ServiceSectionType::Prayer->value,
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertCount(1, $rows);
        $this->assertSame('mismatched', $rows[0]['row_type']);
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertSame('Prayer', $rows[0]['planned_title']);
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
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'metadata' => [],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertSame(ServiceSectionPublicationStatus::Approved, $rows[0]['publication_status']);
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
    // presentation_inference read path
    // -------------------------------------------------------------------------

    #[Test]
    public function presentation_inference_is_read_from_nested_oos_alignment_path(): void
    {
        // Regression for TD-004: writers store presentation_inference under
        // metadata['oos_alignment']['presentation_inference'], but the reader
        // was incorrectly reading the top-level metadata['presentation_inference'].
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'metadata' => [
                'oos_alignment' => [
                    'presentation_inference' => [
                        'resolved_type' => 'other',
                        'suspected_type' => 'childrens_talk',
                        'evidence' => 'weak',
                        'reason' => 'no strong presenter signal',
                    ],
                ],
            ],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertCount(1, $rows);
        $inference = $rows[0]['presentation_inference'];
        $this->assertIsArray($inference);
        $this->assertSame('other', $inference['resolved_type']);
        $this->assertSame('childrens_talk', $inference['suspected_type']);
        $this->assertSame('weak', $inference['evidence']);
    }

    #[Test]
    public function presentation_inference_is_null_when_absent_from_metadata(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create(['position' => 1]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_order' => 1,
            'metadata' => ['oos_alignment' => []],
        ]);

        $run->load(['serviceSections.churchServiceItem', 'serviceSections.publishedSermon']);

        $rows = ServiceRecordTimeline::build($this->itemCollection([$item]), $run);

        $this->assertNull($rows[0]['presentation_inference']);
    }

    #[Test]
    public function song_identity_reconciliation_replaces_a_wrong_fk_using_normalized_title(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $duplicateCatalogueSong = Song::factory()->create(['title' => 'Great Is The Lord']);
        $plannedCatalogueSong = Song::factory()->create(['title' => 'Great Is The Lord #179']);
        $wrongSong = Song::factory()->create(['title' => 'God is for us']);
        $correctItem = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'Great Is The Lord #179',
            'song_id' => $plannedCatalogueSong->id,
        ]);
        $wrongItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $correctItem->church_service_id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $wrongSong->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $wrongItem->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Great Is The Lord',
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => $duplicateCatalogueSong->id],
        ]);

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);
        $rows = ServiceRecordTimeline::build($this->itemCollection([$correctItem->load('song'), $wrongItem->load('song')]), $run);

        $this->assertSame('matched', $rows[0]['row_type']);
        $this->assertSame($correctItem->id, $rows[0]['item_id']);
        $this->assertSame($wrongItem->id, $rows[1]['item_id']);
        $this->assertSame('planned_only', $rows[1]['row_type']);
    }

    #[Test]
    public function song_identity_reconciliation_uses_exact_song_id_before_title(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $song = Song::factory()->create(['title' => 'God is for us']);
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $song->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'A transcription typo',
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => $song->id],
        ]);

        $run->load(['serviceSections.churchServiceItem.song', 'serviceSections.publishedSermon']);
        $rows = ServiceRecordTimeline::build($this->itemCollection([$item->load('song')]), $run);

        $this->assertCount(1, $rows);
        $this->assertSame('matched', $rows[0]['row_type']);
        $this->assertSame($item->id, $rows[0]['item_id']);
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
