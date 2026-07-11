<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceSongLinker;
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
    public function it_links_song_items_using_normalized_canonical_keys(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'who am i that the highest king',
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
            'source' => ChurchServiceItemSource::Email->value,
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
            'canonical_key' => 'blessed assurance',
            'title' => 'Blessed Assurance',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Manual->value,
            'title' => 'Blessed Assurance',
            'source_title' => 'Blessed Assurance',
            'openlp_search_title' => null,
            'song_id' => $song->id,
            'metadata' => [
                'section_type' => 'song',
                'linked_song_canonical_key' => 'blessed assurance',
            ],
        ]);

        $metrics = $this->linker->linkAll();

        $item->refresh();

        $this->assertSame($song->id, $item->song_id);
        $this->assertSame(1, $metrics['matched']);
        $this->assertSame(1, $metrics['unchanged']);
    }

    #[Test]
    public function it_links_numbered_email_titles_via_praise_number(): void
    {
        // OoS emails prefix the Praise! number ("299 'How sweet…'"); the library holds the
        // same number in praise_number (backfilled from the "#299" title suffix).
        $song = Song::factory()->create([
            'canonical_key' => 'how sweet the name of jesus sounds 299',
            'title' => 'How Sweet The Name Of Jesus Sounds #299',
            'praise_number' => '299',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'source_title' => "299 'How sweet the name of Jesus sounds'",
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $this->linker->linkAll();

        $this->assertSame($song->id, $item->refresh()->song_id);
    }

    #[Test]
    public function it_normalises_letter_variant_praise_numbers(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'crown him with many crowns 046a',
            'title' => 'Crown Him With Many Crowns #046A',
            'praise_number' => '046A',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'source_title' => "46a 'Crown him'",
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $this->linker->linkAll();

        $this->assertSame($song->id, $item->refresh()->song_id);
    }

    #[Test]
    public function it_links_plain_email_titles_against_number_suffixed_canonical_keys(): void
    {
        // OpenLP-imported keys carry the number as a suffix; a plain email title must still
        // match once both sides are compared with their numbers stripped.
        $song = Song::factory()->create([
            'canonical_key' => 'all heaven declares 477',
            'title' => 'All Heaven Declares #477',
            'praise_number' => '477',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'source_title' => 'All heaven declares',
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $this->linker->linkAll();

        $this->assertSame($song->id, $item->refresh()->song_id);
    }

    #[Test]
    public function it_does_not_link_when_the_number_stripped_key_is_ambiguous(): void
    {
        Song::factory()->create([
            'canonical_key' => 'abide with me 45',
            'title' => 'Abide With Me #45',
            'praise_number' => '45',
        ]);
        Song::factory()->create([
            'canonical_key' => 'abide with me 900',
            'title' => 'Abide With Me #900',
            'praise_number' => '900',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'source_title' => 'Abide with me',
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $metrics = $this->linker->linkAll();

        $this->assertNull($item->refresh()->song_id);
        $this->assertSame(1, $metrics['unmatched']);
    }

    #[Test]
    public function a_title_starting_with_a_large_number_is_not_treated_as_a_praise_number(): void
    {
        Song::factory()->create([
            'canonical_key' => 'some other hymn 10',
            'title' => 'Some Other Hymn #10',
            'praise_number' => '10',
        ]);
        $song = Song::factory()->create([
            'canonical_key' => '10 000 reasons bless the lord 1195',
            'title' => '10,000 Reasons Bless The Lord #1195',
            'praise_number' => '1195',
        ]);

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'source_title' => '10,000 Reasons (Bless the Lord)',
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $this->linker->linkAll();

        $this->assertNotSame(
            Song::query()->where('praise_number', '10')->value('id'),
            $item->refresh()->song_id,
            'The 10 in "10,000" must not be read as hymn number 10.',
        );
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
            'canonical_key' => 'song one',
        ]);
        $songCountBeforeRun = Song::query()->count();

        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'song one@',
            'song_id' => null,
        ]);

        $metrics = $this->linker->linkAll(dryRun: true);

        $item->refresh();

        $this->assertNull($item->song_id);
        $this->assertTrue(Song::query()->whereKey($song->id)->exists());
        $this->assertSame($songCountBeforeRun, Song::query()->count());
        $this->assertSame(1, $metrics['updated']);
    }

    #[Test]
    public function link_for_service_only_updates_items_for_that_service(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'target key',
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
