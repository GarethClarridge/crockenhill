<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchServiceSongLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceSongLinkerTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceSongLinker $linker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linker = app(ChurchServiceSongLinker::class);
    }

    #[Test]
    public function it_links_song_items_using_canonical_key_with_at_suffix_preserved(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'who am i that the highest king@who you say i am',
            'title' => 'Who You Say I Am',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => '  Who Am I That The Highest King@Who You Say I Am  ',
            'song_id' => null,
        ]);

        $metrics = $this->linker->linkAll();

        $item->refresh();

        $this->assertSame($song->id, $item->song_id);
        $this->assertSame(1, $metrics['matched']);
        $this->assertSame(1, $metrics['updated']);
    }

    #[Test]
    public function it_clears_stale_song_links_when_items_do_not_match_any_song(): void
    {
        $staleSong = Song::factory()->create([
            'canonical_key' => 'stale key@',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'non matching key@',
            'song_id' => $staleSong->id,
        ]);

        $metrics = $this->linker->linkAll();

        $item->refresh();

        $this->assertNull($item->song_id);
        $this->assertSame(1, $metrics['unmatched']);
        $this->assertSame(1, $metrics['cleared']);
    }

    #[Test]
    public function dry_run_reports_changes_without_persisting(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'song one@',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'song one@',
            'song_id' => null,
        ]);

        $metrics = $this->linker->linkAll(dryRun: true);

        $item->refresh();

        $this->assertNull($item->song_id);
        $this->assertSame($song->id, Song::query()->firstOrFail()->id);
        $this->assertSame(1, $metrics['updated']);
    }

    #[Test]
    public function link_for_service_only_updates_items_for_that_service(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'target key@',
        ]);

        $targetService = ChurchService::factory()->create();
        $otherService = ChurchService::factory()->create();

        $targetItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $targetService->id,
            'type' => 'songs',
            'openlp_search_title' => 'target key@',
        ]);

        $otherItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $otherService->id,
            'type' => 'songs',
            'openlp_search_title' => 'target key@',
        ]);

        $metrics = $this->linker->linkForService($targetService);

        $targetItem->refresh();
        $otherItem->refresh();

        $this->assertSame($song->id, $targetItem->song_id);
        $this->assertNull($otherItem->song_id);
        $this->assertSame(1, $metrics['processed']);
    }
}
