<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
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
    public function it_links_email_song_items_using_source_title_when_no_openlp_key_exists(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'before the throne of god above',
            'title' => 'Before the throne of God above',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::EMAIL->value,
            'source_title' => 'Before the throne of God above',
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $metrics = $this->linker->linkAll();

        $item->refresh();

        $this->assertSame($song->id, $item->song_id);
        $this->assertSame(1, $metrics['matched']);
        $this->assertSame(1, $metrics['updated']);
    }

    #[Test]
    public function it_preserves_manually_selected_song_links_using_metadata_canonical_key(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'blessed assurance@',
            'title' => 'Blessed Assurance',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Blessed Assurance',
            'source_title' => 'Blessed Assurance',
            'openlp_search_title' => null,
            'song_id' => $song->id,
            'metadata' => [
                'section_type' => 'song',
                'linked_song_canonical_key' => 'blessed assurance@',
            ],
        ]);

        $metrics = $this->linker->linkAll();

        $item->refresh();

        $this->assertSame($song->id, $item->song_id);
        $this->assertSame(1, $metrics['matched']);
        $this->assertSame(1, $metrics['unchanged']);
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
