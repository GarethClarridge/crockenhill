<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Seo\SongItemListPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongItemListPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_item_list_json_ld_for_songs_with_id(): void
    {
        $song = Song::factory()->create([
            'title' => 'Test Song',
            'slug' => 'test-song',
        ]);

        $author = SongAuthor::factory()->create(['display_name' => 'Test Author']);
        $song->authors()->attach($author);

        $presenter = app(SongItemListPresenter::class);
        $result = $presenter->toItemList(collect([$song]));

        $this->assertEquals('https://schema.org', $result['@context']);
        $this->assertEquals('ItemList', $result['@type']);
        $this->assertEquals(1, $result['numberOfItems']);

        $item = $result['itemListElement'][0]['item'];
        $this->assertEquals('MusicComposition', $item['@type']);
        $this->assertStringEndsWith('/church/songs/test-song#song', $item['@id']);
        $this->assertEquals('Test Song', $item['name']);
        $this->assertEquals('Test Author', $item['author'][0]['name']);

        // Check for publisher logo metadata
        $this->assertArrayHasKey('publisher', $item);
        $this->assertArrayHasKey('logo', $item['publisher']);
        $this->assertEquals(444, $item['publisher']['logo']['width']);
        $this->assertEquals(481, $item["publisher"]["logo"]["height"]);
    }
}
