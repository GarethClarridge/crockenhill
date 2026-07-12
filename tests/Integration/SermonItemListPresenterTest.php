<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Sermon;
use App\Seo\SermonItemListPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonItemListPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function it_generates_item_list_json_ld_with_rich_metadata(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => '2024-01-01',
            'preacher' => 'John Doe',
            'audio_file_path' => 'sermons/test.mp3',
            'duration' => 1800,
        ]);

        Storage::disk('public')->put('sermons/test.mp3', 'audio');

        $presenter = app(SermonItemListPresenter::class);
        $result = $presenter->toItemList(collect([$sermon]));

        $this->assertEquals('https://schema.org', $result['@context']);
        $this->assertEquals('ItemList', $result['@type']);
        $this->assertEquals(1, $result['numberOfItems']);

        $item = $result['itemListElement'][0]['item'];
        $this->assertEquals('Article', $item['@type']);
        $this->assertStringEndsWith('#sermon', $item['@id']);
        $this->assertEquals('Test Sermon', $item['name']);
        $this->assertEquals('John Doe', $item['author']['name']);
        $this->assertEquals('2024-01-01T00:00:00+00:00', $item['datePublished']);

        // Check for rich audio metadata
        $this->assertArrayHasKey('audio', $item);
        $this->assertEquals('AudioObject', $item['audio']['@type']);
        $this->assertEquals('PT30M', $item['audio']['duration']);
        $this->assertEquals('audio/mpeg', $item['audio']['encodingFormat']);

        // Check for author enrichment
        $this->assertEquals('Preacher', $item['author']['jobTitle']);
        $this->assertArrayHasKey('worksFor', $item['author']);
        $this->assertEquals(config('church.name'), $item['author']['worksFor']['name']);

        $logoSize = getimagesize(public_path('images/Primary.png'));
        if ($logoSize === false) {
            $this->fail('Primary logo image dimensions could not be read.');
        }

        $this->assertArrayHasKey('publisher', $item);
        $this->assertArrayHasKey('logo', $item['publisher']);
        $this->assertSame($logoSize[0], $item['publisher']['logo']['width']);
        $this->assertSame($logoSize[1], $item['publisher']['logo']['height']);
    }
}
