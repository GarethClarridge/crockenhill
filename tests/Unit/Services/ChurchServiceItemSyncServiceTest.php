<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchServiceItemSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ], ChurchServiceItemSource::OPENLP);

        $this->assertDatabaseCount('church_service_items', 2);

        $churchService->refresh()->load('items');
        $this->assertCount(2, $churchService->items);
        $this->assertSame(
            [ChurchServiceItemSource::OPENLP, ChurchServiceItemSource::OPENLP],
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
        ], ChurchServiceItemSource::OPENLP);

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
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Reading',
            'source_title' => null,
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Prayer', null, null),
        ], ChurchServiceItemSource::MANUAL);

        $existing->refresh();

        $this->assertSame('Prayer', $existing->title);
        $this->assertSame(1, $existing->position);
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
        ], ChurchServiceItemSource::OPENLP);

        $this->assertNull($kept->fresh()?->deleted_at);
        $this->assertSoftDeleted('church_service_items', ['id' => $stale->id]);
    }

    #[Test]
    public function test_updates_position_on_reorder(): void
    {
        $churchService = ChurchService::factory()->create([
            'service' => SermonService::MORNING,
        ]);

        $first = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Reading',
            'source_title' => 'Reading',
            'openlp_search_title' => null,
        ]);

        $second = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Prayer',
            'source_title' => 'Prayer',
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Prayer', 'Prayer', null),
            $this->incomingItem(2, 'custom', 'Reading', 'Reading', null),
        ], ChurchServiceItemSource::MANUAL);

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
            ], ChurchServiceItemSource::OPENLP);
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
        ], ChurchServiceItemSource::OPENLP);

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
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Luke 15:1-32',
            'source_title' => 'Luke 15:1-32 (NIV)',
            'openlp_search_title' => null,
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(2, 'bibles', 'Luke 15:1-32 (Updated)', 'Luke 15:1-32 (NIV)', null),
        ], ChurchServiceItemSource::MANUAL);

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
        ], ChurchServiceItemSource::OPENLP);

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
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Reading',
            'source_title' => 'Reading',
            'openlp_search_title' => null,
        ]);

        // Both incoming items match the same existing row by source_title
        // First match wins; second becomes a new row
        $this->service->sync($churchService, [
            $this->incomingItem(1, 'custom', 'Reading (First)', 'Reading', null),
            $this->incomingItem(2, 'custom', 'Reading (Second)', 'Reading', null),
        ], ChurchServiceItemSource::MANUAL);

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

        $this->service->sync($churchService, [], ChurchServiceItemSource::OPENLP);

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
        ], ChurchServiceItemSource::OPENLP);
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
            'source' => ChurchServiceItemSource::EMAIL->value,
            'title' => 'Opening Prayer',
            'source_title' => 'Opening Prayer',
            'metadata' => ['speaker' => 'Leader'],
        ]);

        $emailSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::EMAIL->value,
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['email_note' => 'from_email'],
        ]);

        $this->service->sync($churchService, [
            $this->incomingItem(
                1,
                'songs',
                'Amazing Grace',
                'Amazing Grace',
                'amazing grace@',
                ['authors' => 'John Newton'],
                $song->id,
            ),
        ], ChurchServiceItemSource::OPENLP);

        $prayer->refresh();
        $emailSong->refresh();

        $this->assertNull($prayer->deleted_at);
        $this->assertSame(ChurchServiceItemSource::EMAIL, $prayer->source);
        $this->assertSame(1, $prayer->position);

        $this->assertSame(ChurchServiceItemSource::OPENLP, $emailSong->source);
        $this->assertSame(2, $emailSong->position);
        $this->assertSame('amazing grace@', $emailSong->openlp_search_title);
        $this->assertSame($song->id, $emailSong->song_id);
        $this->assertSame('from_email', $emailSong->metadata['email_note']);
        $this->assertSame('John Newton', $emailSong->metadata['authors']);
        $this->assertDatabaseCount('church_service_items', 2);
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
            'source' => ChurchServiceItemSource::OPENLP->value,
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
        ], ChurchServiceItemSource::EMAIL);

        $openLpSong->refresh();

        $this->assertNull($openLpSong->deleted_at);
        $this->assertSame(ChurchServiceItemSource::OPENLP, $openLpSong->source);
        $this->assertSame('amazing grace@', $openLpSong->openlp_search_title);
        $this->assertSame($song->id, $openLpSong->song_id);
        $this->assertSame('John Newton', $openLpSong->metadata['authors']);

        $prayer = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->where('type', 'custom')
            ->firstOrFail();

        $this->assertSame(ChurchServiceItemSource::EMAIL, $prayer->source);
        $this->assertSame('Opening Prayer', $prayer->title);
        $this->assertSame(['speaker' => 'Leader'], $prayer->metadata);
        $this->assertCount(2, $churchService->items()->get());
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}
     */
    private function incomingItem(
        int $position,
        string $type,
        string $title,
        ?string $sourceTitle,
        ?string $openLpSearchTitle,
        ?array $metadata = null,
        ?int $songId = null,
    ): array {
        return [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $sourceTitle,
            'openlp_search_title' => $openLpSearchTitle,
            'song_id' => $songId,
            'metadata' => $metadata,
        ];
    }
}
