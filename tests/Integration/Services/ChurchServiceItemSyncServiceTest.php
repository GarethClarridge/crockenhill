<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceCanonicalStateService;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ChurchServiceItemSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceItemSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChurchServiceItemSyncService;
    }

    #[Test]
    public function test_creates_items_for_new_service(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One', 'Song One', 'song one@'),
            $this->incomingItem(2, 'bibles', 'Luke 15:1-32', 'Luke 15 full title', null),
        ], ChurchServiceItemSource::OpenLp);

        $this->assertDatabaseCount('church_service_items', 2);

        $churchService->refresh()->load('items');
        $this->assertCount(2, $churchService->items);
        $this->assertSame(
            [ChurchServiceItemSource::OpenLp, ChurchServiceItemSource::OpenLp],
            $churchService->items->pluck('source')->all()
        );
    }

    #[Test]
    public function test_stable_match_by_search_title(): void
    {
        $churchService = ChurchService::factory()->create();

        $itemA = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song A',
            'source_title' => 'Song A',
            'openlp_search_title' => 'song a@',
        ]);

        $itemB = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Song B',
            'source_title' => 'Song B',
            'openlp_search_title' => 'song b@',
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song B (Updated)', 'Song B', 'song b@'),
            $this->incomingItem(2, 'songs', 'Song A (Updated)', 'Song A', 'song a@'),
        ], ChurchServiceItemSource::OpenLp);

        $itemA->refresh();
        $itemB->refresh();

        $this->assertSame(2, $itemA->position);
        $this->assertSame('Song A (Updated)', $itemA->title);
        $this->assertSame(1, $itemB->position);
        $this->assertSame('Song B (Updated)', $itemB->title);
    }

    #[Test]
    public function test_position_fallback_when_no_title_match(): void
    {
        $churchService = ChurchService::factory()->create();

        $existing = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Reading',
            'source_title' => null,
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Prayer', null, null),
        ], ChurchServiceItemSource::Manual);

        $existing->refresh();

        $this->assertSame('Prayer', $existing->title);
        $this->assertSame(1, $existing->position);
    }

    #[Test]
    public function test_preserves_explicit_section_type_from_incoming_payload(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->service->sync($churchService, [
            $this->incomingItem(
                position: 1,
                type: 'custom',
                title: 'Welcome from explicit payload',
                sourceTitle: 'Welcome from explicit payload',
                openLpSearchTitle: null,
                sectionType: ServiceSectionType::Welcome->value,
            ),
        ], ChurchServiceItemSource::Manual);

        /** @var ChurchServiceItem $item */
        $item = $churchService->fresh('items')?->items->sole();

        $this->assertSame(ServiceSectionType::Welcome, $item->section_type);
        $this->assertSame(ServiceSectionType::Welcome, $item->semanticSectionType());
    }

    #[Test]
    public function test_soft_deletes_unmatched_stale_items(): void
    {
        $churchService = ChurchService::factory()->create();

        $kept = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
            'source_title' => 'Song One',
            'openlp_search_title' => 'song one@',
        ]);

        $stale = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Song Two',
            'source_title' => 'Song Two',
            'openlp_search_title' => 'song two@',
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One', 'Song One', 'song one@'),
        ], ChurchServiceItemSource::OpenLp);

        $this->assertNull($kept->fresh()?->deleted_at);
        $this->assertSoftDeleted('church_service_items', ['id' => $stale->id]);
    }

    #[Test]
    public function test_updates_position_on_reorder(): void
    {
        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
        ]);

        $first = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Reading',
            'source_title' => 'Reading',
            'openlp_search_title' => null,
        ]);

        $second = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Prayer',
            'source_title' => 'Prayer',
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Prayer', 'Prayer', null),
            $this->incomingItem(2, 'custom', 'Reading', 'Reading', null),
        ], ChurchServiceItemSource::Manual);

        $this->assertSame(2, $first->fresh()?->position);
        $this->assertSame(1, $second->fresh()?->position);
    }

    #[Test]
    public function test_wraps_in_transaction(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->expectException(RuntimeException::class);

        try {
            $this->service->sync($churchService, [
                $this->incomingItem(1, 'songs', 'Song One', 'Song One', 'song one@'),
                [
                    'position' => 2,
                    // missing type should throw after first write attempt
                    'title' => 'Invalid Item',
                ],
            ], ChurchServiceItemSource::OpenLp);
        } finally {
            $this->assertDatabaseCount('church_service_items', 0);
        }
    }

    #[Test]
    public function test_restores_soft_deleted_item_on_rematch(): void
    {
        $churchService = ChurchService::factory()->create();

        $trashedItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
            'source_title' => 'Song One',
            'openlp_search_title' => 'song one@',
            'deleted_at' => now(),
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One (Restored)', 'Song One', 'song one@'),
        ], ChurchServiceItemSource::OpenLp);

        $refreshed = ChurchServiceItem::withTrashed()->findOrFail($trashedItem->id);

        $this->assertNull($refreshed->deleted_at);
        $this->assertSame('Song One (Restored)', $refreshed->title);
    }

    #[Test]
    public function test_stable_match_by_source_title(): void
    {
        $churchService = ChurchService::factory()->create();

        $existing = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Luke 15:1-32',
            'source_title' => 'Luke 15:1-32 (NIV)',
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(2, 'bibles', 'Luke 15:1-32 (Updated)', 'Luke 15:1-32 (NIV)', null),
        ], ChurchServiceItemSource::Manual);

        $existing->refresh();

        $this->assertSame($existing->id, $existing->id);
        $this->assertSame(2, $existing->position);
        $this->assertSame('Luke 15:1-32 (Updated)', $existing->title);
        $this->assertNull($existing->deleted_at);
    }

    #[Test]
    public function test_stable_match_wins_over_position_fallback(): void
    {
        $churchService = ChurchService::factory()->create();

        $itemAtPos1 = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song A',
            'source_title' => 'Song A',
            'openlp_search_title' => 'song a@',
        ]);

        $itemAtPos2 = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Song B',
            'source_title' => 'Song B',
            'openlp_search_title' => 'song b@',
        ]);

        // Incoming item at position 1 has search title matching itemAtPos2 (stable match)
        // Position fallback would match itemAtPos1 (same position+type)
        // Stable match should win — itemAtPos2 gets updated, not itemAtPos1
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song B (Moved)', 'Song B', 'song b@'),
            $this->incomingItem(2, 'songs', 'Song A (Moved)', 'Song A', 'song a@'),
        ], ChurchServiceItemSource::OpenLp);

        $itemAtPos1->refresh();
        $itemAtPos2->refresh();

        $this->assertSame(2, $itemAtPos1->position);
        $this->assertSame('Song A (Moved)', $itemAtPos1->title);
        $this->assertSame(1, $itemAtPos2->position);
        $this->assertSame('Song B (Moved)', $itemAtPos2->title);
    }

    #[Test]
    public function test_two_incoming_items_matching_same_existing(): void
    {
        $churchService = ChurchService::factory()->create();

        $existing = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Reading',
            'source_title' => 'Reading',
            'openlp_search_title' => null,
        ]);

        // Both incoming items match the same existing row by source_title
        // First match wins; second becomes a new row
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Reading (First)', 'Reading', null),
            $this->incomingItem(2, 'custom', 'Reading (Second)', 'Reading', null),
        ], ChurchServiceItemSource::Manual);

        $existing->refresh();

        $this->assertSame(1, $existing->position);
        $this->assertSame('Reading (First)', $existing->title);

        $allItems = ChurchServiceItem::where('church_service_id', $churchService->id)->orderBy('position')->get();
        $this->assertCount(2, $allItems);
        $this->assertSame('Reading (Second)', $allItems[1]->title);
        $this->assertNotSame($existing->id, $allItems[1]->id);
    }

    #[Test]
    public function test_handles_empty_incoming_items(): void
    {
        $churchService = ChurchService::factory()->create();

        $itemA = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
        ]);

        $itemB = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'bibles',
            'title' => 'Luke 15:1-32',
        ]);

        $this->service->sync($churchService, [], ChurchServiceItemSource::OpenLp);

        $this->assertSoftDeleted('church_service_items', ['id' => $itemA->id]);
        $this->assertSoftDeleted('church_service_items', ['id' => $itemB->id]);
    }

    #[Test]
    public function test_enforces_position_uniqueness_for_active_items(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One', 'Song One', 'song one@'),
            $this->incomingItem(1, 'bibles', 'Luke 15:1-32', 'Luke 15 full title', null),
        ], ChurchServiceItemSource::OpenLp);
    }

    #[Test]
    public function test_resequence_does_not_fire_constraint_when_items_must_cross_positions(): void
    {
        $churchService = ChurchService::factory()->create();

        // Create a test scenario where the two-phase resequencer is needed.
        // Start with items at positions 1 and 3 (gap at 2), then sync a manual item at position 2.
        // The manual item will be preserved and fill the gap.
        $itemA = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song A',
        ]);

        // No item at position 2 (gap).

        $itemC = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
            'position' => 3,
            'type' => 'songs',
            'title' => 'Song C',
        ]);

        // Sync with Email source (human-provided, different merge authority).
        // This adds a Manual item at position 2 which is preserved alongside OpenLp items.
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song A', 'Song A', 'song a@'),
            $this->incomingItem(2, 'custom', 'Prayer', 'Prayer', null),
            $this->incomingItem(3, 'songs', 'Song C', 'Song C', 'song c@'),
        ], ChurchServiceItemSource::Email);

        // After sync: A at 1 (matched, OpenLp→Email mixed sources),
        // new Manual at 2, C at 3 (matched).
        // The resequencer should not have fired any constraint violations.
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();

        // Assert the service has exactly 3 items with sequential positions 1, 2, 3.
        $this->assertCount(3, $items);
        $positions = $items->pluck('position')->all();
        $this->assertSame([1, 2, 3], $positions);
    }

    #[Test]
    public function test_openlp_enriches_email_song_items_without_removing_email_speech_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'source' => 'email',
        ]);

        $song = Song::factory()->create();

        $prayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Email->value,
            'title' => 'Opening Prayer',
            'source_title' => 'Opening Prayer',
            'metadata' => ['speaker' => 'Leader'],
        ]);

        $emailSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['email_note' => 'from_email'],
        ]);

        $result = $this->service->sync($churchService, [
            $this->incomingItem(
                1,
                'songs',
                'Amazing Grace',
                'Amazing Grace',
                'amazing grace@',
                ['authors' => 'John Newton'],
                $song->id,
            ),
        ], ChurchServiceItemSource::OpenLp);

        $prayer->refresh();
        $emailSong->refresh();

        $this->assertNull($prayer->deleted_at);
        $this->assertSame(ChurchServiceItemSource::Email, $prayer->source);
        $this->assertSame(1, $prayer->position);

        $this->assertSame(ChurchServiceItemSource::Email, $emailSong->source);
        $this->assertSame(2, $emailSong->position);
        $this->assertSame('amazing grace@', $emailSong->openlp_search_title);
        $this->assertSame($song->id, $emailSong->song_id);
        $this->assertSame('from_email', $emailSong->metadata['email_note']);
        $this->assertSame('John Newton', $emailSong->metadata['authors']);
        $this->assertDatabaseCount('church_service_items', 2);
        $this->assertSame([], $result['conflicts']);
    }

    #[Test]
    public function test_email_adds_speech_items_without_removing_openlp_song_metadata(): void
    {
        $churchService = ChurchService::factory()->create([
            'source' => 'openlp',
        ]);

        $song = Song::factory()->create();

        $openLpSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => 'amazing grace@',
            'song_id' => $song->id,
            'metadata' => ['authors' => 'John Newton'],
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(
                1,
                'custom',
                'Opening Prayer',
                'Opening Prayer',
                null,
                ['speaker' => 'Leader'],
            ),
        ], ChurchServiceItemSource::Email);

        $openLpSong->refresh();

        $this->assertNull($openLpSong->deleted_at);
        $this->assertSame(ChurchServiceItemSource::OpenLp, $openLpSong->source);
        $this->assertSame('amazing grace@', $openLpSong->openlp_search_title);
        $this->assertSame($song->id, $openLpSong->song_id);
        $this->assertSame('John Newton', $openLpSong->metadata['authors']);

        $prayer = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->where('type', 'custom')
            ->firstOrFail();

        $this->assertSame(ChurchServiceItemSource::Email, $prayer->source);
        $this->assertSame('Opening Prayer', $prayer->title);
        $this->assertSame(['speaker' => 'Leader'], $prayer->metadata);
        $this->assertCount(2, $churchService->items()->get());
    }

    #[Test]
    public function test_partial_openlp_merge_preserves_unmatched_human_song_and_reports_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create([
            'source' => 'email',
        ]);

        $matchedSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'title' => 'Opening Song',
            'source_title' => 'Opening Song',
        ]);

        $preservedSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Closing Song',
            'source_title' => 'Closing Song',
            'openlp_search_title' => null,
            'metadata' => null,
        ]);

        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Opening Song', 'Opening Song', 'opening song@'),
        ], ChurchServiceItemSource::OpenLp);

        $matchedSong->refresh();
        $preservedSong->refresh();

        $this->assertNull($matchedSong->deleted_at);
        $this->assertSame('opening song@', $matchedSong->openlp_search_title);
        $this->assertNull($preservedSong->deleted_at);
        $this->assertSame(ChurchServiceItemSource::Manual, $preservedSong->source);
        $this->assertSame('Closing Song', $preservedSong->title);
        $this->assertSame([
            [
                'type' => 'preserved_existing_song',
                'incoming_source' => ChurchServiceItemSource::OpenLp->value,
                'existing_item' => [
                    'id' => $preservedSong->id,
                    'position' => 2,
                    'type' => 'songs',
                    'section_type' => ServiceSectionType::Song->value,
                    'source' => ChurchServiceItemSource::Manual->value,
                    'title' => 'Closing Song',
                    'source_title' => 'Closing Song',
                    'openlp_search_title' => null,
                    'song_id' => null,
                    'metadata' => null,
                ],
            ],
        ], $result['conflicts']);
    }

    #[Test]
    public function test_livestream_source_creates_items_successfully(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Amazing Grace', 'Amazing Grace', null),
            $this->incomingItem(2, 'custom', 'Sermon', 'Sermon', null),
        ], ChurchServiceItemSource::Livestream);

        $items = $churchService->fresh('items')?->items;
        $this->assertCount(2, $items);
        $this->assertSame(
            [ChurchServiceItemSource::Livestream, ChurchServiceItemSource::Livestream],
            $items->pluck('source')->all()
        );
    }

    #[Test]
    public function test_livestream_source_normalises_from_string(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Prayer', 'Prayer', null),
        ], 'livestream');

        /** @var ChurchServiceItem $item */
        $item = $churchService->fresh('items')?->items->sole();
        $this->assertSame(ChurchServiceItemSource::Livestream, $item->source);
    }

    #[Test]
    public function test_livestream_does_not_share_merge_authority_with_openlp(): void
    {
        $churchService = ChurchService::factory()->create();

        $openlpItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Notices',
            'source_title' => 'Notices',
        ]);

        // Livestream syncs with no matching item — OPENLP item should be preserved
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Welcome', 'Welcome', null),
        ], ChurchServiceItemSource::Livestream);

        $openlpItem->refresh();
        $this->assertNull($openlpItem->deleted_at);
        $this->assertSame(ChurchServiceItemSource::OpenLp, $openlpItem->source);
        $this->assertDatabaseCount('church_service_items', 2);
    }

    #[Test]
    public function test_livestream_does_not_delete_unmatched_human_items(): void
    {
        $churchService = ChurchService::factory()->create();

        $humanItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Opening Prayer',
            'source_title' => 'Opening Prayer',
        ]);

        // Livestream syncs — should preserve human-authored items
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Welcome', 'Welcome', null),
        ], ChurchServiceItemSource::Livestream);

        $humanItem->refresh();
        $this->assertNull($humanItem->deleted_at);
        $this->assertSame(ChurchServiceItemSource::Manual, $humanItem->source);
    }

    #[Test]
    public function test_human_source_deletes_unmatched_livestream_non_song_items(): void
    {
        $churchService = ChurchService::factory()->create();

        $livestreamItem = ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Detected Prayer',
            'source_title' => 'Detected Prayer',
        ]);

        // Manual edit syncs — should soft-delete unmatched livestream non-song items
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Opening Prayer', 'Opening Prayer', null),
        ], ChurchServiceItemSource::Manual);

        $this->assertSoftDeleted('church_service_items', ['id' => $livestreamItem->id]);
    }

    #[Test]
    public function test_human_source_preserves_unmatched_livestream_songs(): void
    {
        $churchService = ChurchService::factory()->create();

        $livestreamSong = ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Detected Song',
            'source_title' => 'Detected Song',
        ]);

        // Manual edit syncs — unmatched livestream songs should be preserved (not replace_mode)
        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Opening Prayer', 'Opening Prayer', null),
        ], ChurchServiceItemSource::Manual);

        $livestreamSong->refresh();
        $this->assertNull($livestreamSong->deleted_at);
        $this->assertSame(ChurchServiceItemSource::Livestream, $livestreamSong->source);

        $this->assertCount(1, collect($result['conflicts'])->where('type', 'preserved_existing_song'));
    }

    #[Test]
    public function test_livestream_rerun_replaces_previous_livestream_items(): void
    {
        $churchService = ChurchService::factory()->create();

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Old Detected Prayer',
            'source_title' => 'Old Detected Prayer',
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Old Detected Song',
            'source_title' => 'Old Detected Song',
        ]);

        // Livestream re-sync — should replace all previous livestream items (same source = merge authority)
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'New Song', 'New Song', null),
        ], ChurchServiceItemSource::Livestream);

        $activeItems = $churchService->items()->get();
        $this->assertCount(1, $activeItems);
        $this->assertSame('New Song', $activeItems->first()->title);
        $this->assertSame(ChurchServiceItemSource::Livestream, $activeItems->first()->source);
    }

    #[Test]
    public function test_canonical_update_service_accepts_livestream_source(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);

        $item = ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Detected Welcome',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        $item->update(['title' => 'Detected Welcome (updated)']);

        $updateService = app(ChurchServiceCanonicalUpdateService::class);
        $result = $updateService->finalize($churchService, $before, ChurchServiceItemSource::Livestream);
        $result->refresh();

        $this->assertSame('livestream', $result->import_metadata['canonical_conflict_history'][0]['incoming_source']);

        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn ($event): bool => $event->churchServiceId === $result->id && $event->source === 'livestream'
        );
    }

    #[Test]
    public function test_detected_run_interleaves_its_own_items_between_order_of_service_anchors(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        // A realistic OpenLP export carries only the slide-backed items.
        foreach ([
            [1, 'songs', 'Opening Song'],
            [2, 'bibles', 'Joshua 1'],
            [3, 'songs', 'Closing Song'],
        ] as [$position, $type, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => $type,
                'source' => ChurchServiceItemSource::OpenLp->value,
                'title' => $title,
                'source_title' => $title,
            ]);
        }

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Welcome', null, null),
            $this->incomingItem(2, 'songs', 'Opening Song', null, null),
            $this->incomingItem(3, 'custom', 'Opening Prayer', null, null),
            $this->incomingItem(4, 'bibles', 'Joshua 1', null, null),
            $this->incomingItem(5, 'custom', 'The faithfulness of God', null, null),
            $this->incomingItem(6, 'songs', 'Closing Song', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertSame([
            'Welcome',
            'Opening Song',
            'Opening Prayer',
            'Joshua 1',
            'The faithfulness of God',
            'Closing Song',
        ], $churchService->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function test_detected_run_does_not_duplicate_an_order_of_service_bible_reading(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        $reading = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Joshua 1',
            'source_title' => 'Joshua 1',
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'bibles', 'Joshua 1', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertCount(1, $churchService->items()->get(), 'A detected reading must match the planned one, not duplicate it.');

        $reading->refresh();
        $this->assertSame(ChurchServiceItemSource::OpenLp, $reading->source);
        $this->assertSame('Joshua 1', $reading->title);
    }

    #[Test]
    public function test_detected_run_splices_order_of_service_items_it_missed_between_anchors(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'email']);

        foreach ([
            [1, 'songs', 'Opening Song'],
            [2, 'custom', 'Notices'],
            [3, 'songs', 'Closing Song'],
        ] as [$position, $type, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => $type,
                'source' => ChurchServiceItemSource::Email->value,
                'title' => $title,
                'source_title' => $title,
            ]);
        }

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Opening Song', null, null),
            $this->incomingItem(2, 'songs', 'Closing Song', null, null),
        ], ChurchServiceItemSource::Livestream);

        $titles = $churchService->items()->orderBy('position')->pluck('title')->all();

        $this->assertSame(['Opening Song', 'Notices', 'Closing Song'], $titles);
    }

    #[Test]
    public function test_missed_and_unexpected_song_together_report_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Planned Song',
            'source_title' => 'Planned Song',
        ]);

        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'A Completely Different Song', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertCount(
            1,
            collect($result['conflicts'])->where('type', 'song_substitution_suspected'),
            'A missed planned song alongside an unexpected detected song signals a misidentification.',
        );
    }

    #[Test]
    public function test_unexpected_detected_song_alone_reports_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Planned Song',
            'source_title' => 'Planned Song',
        ]);

        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Planned Song', null, null),
            $this->incomingItem(2, 'songs', 'An Unplanned Song', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertCount(
            1,
            collect($result['conflicts'])->where('type', 'unexpected_detected_song'),
            'A detected song with no counterpart in the plan is worth an eyeball.',
        );
    }

    #[Test]
    public function test_missed_song_alone_is_not_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        foreach ([[1, 'Opening Song'], [2, 'Middle Song'], [3, 'Closing Song']] as [$position, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => 'songs',
                'source' => ChurchServiceItemSource::OpenLp->value,
                'title' => $title,
                'source_title' => $title,
            ]);
        }

        // Two of three songs anchor, so the run has enough evidence to place the
        // one it missed — the miss alone is not reviewable.
        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Opening Song', null, null),
            $this->incomingItem(2, 'songs', 'Closing Song', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertSame([], $result['conflicts'], 'A lossy detected run that only misses songs is the expected case.');
    }

    #[Test]
    public function test_thin_anchor_coverage_reports_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        foreach ([[1, 'Song One'], [2, 'Song Two'], [3, 'Song Three'], [4, 'Song Four']] as [$position, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => 'songs',
                'source' => ChurchServiceItemSource::OpenLp->value,
                'title' => $title,
                'source_title' => $title,
            ]);
        }

        // Only one of four planned songs anchors — too thin to trust the detected sequence.
        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One', null, null),
            $this->incomingItem(2, 'custom', 'Sermon', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertCount(
            1,
            collect($result['conflicts'])->where('type', 'thin_anchor_coverage'),
            'Too few anchors means the detected ordering was applied without enough evidence.',
        );
    }

    #[Test]
    public function test_sufficient_anchor_coverage_reports_no_coverage_conflict(): void
    {
        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        foreach ([[1, 'Song One'], [2, 'Song Two']] as [$position, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => 'songs',
                'source' => ChurchServiceItemSource::OpenLp->value,
                'title' => $title,
                'source_title' => $title,
            ]);
        }

        $result = $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Song One', null, null),
            $this->incomingItem(2, 'songs', 'Song Two', null, null),
        ], ChurchServiceItemSource::Livestream);

        $this->assertSame([], $result['conflicts']);
    }

    #[Test]
    public function test_detected_run_anchors_on_song_id_when_titles_disagree(): void
    {
        $song = Song::factory()->create(['title' => 'Great Is Thy Faithfulness']);

        $churchService = ChurchService::factory()->create(['source' => 'openlp']);

        $planned = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Great Is Thy Faithfulness, O God My Father',
            'source_title' => 'Great Is Thy Faithfulness, O God My Father',
            'song_id' => $song->id,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'songs', 'Great is thy faithfulness', null, null, null, $song->id),
        ], ChurchServiceItemSource::Livestream);

        $this->assertCount(1, $churchService->items()->get(), 'A shared song_id is a stronger anchor than the title text.');
        $this->assertSame('Great Is Thy Faithfulness, O God My Father', $planned->refresh()->title);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>,section_type?:string}
     */
    private function incomingItem(
        int $position,
        string $type,
        string $title,
        ?string $sourceTitle,
        ?string $openLpSearchTitle,
        ?array $metadata = null,
        ?int $songId = null,
        ?string $sectionType = null,
    ): array {
        $item = [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $sourceTitle,
            'openlp_search_title' => $openLpSearchTitle,
            'song_id' => $songId,
            'metadata' => $metadata,
        ];

        if ($sectionType !== null) {
            $item['section_type'] = $sectionType;
        }

        return $item;
    }
}
