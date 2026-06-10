<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Seo\SeriesItemListPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeriesItemListPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_item_list_json_ld_for_series_with_rich_metadata(): void
    {
        $series = collect(['Test Series']);

        $presenter = app(SeriesItemListPresenter::class);
        $result = $presenter->toItemList($series);

        $this->assertEquals('https://schema.org', $result['@context']);
        $this->assertEquals('ItemList', $result['@type']);
        $this->assertEquals(1, $result['numberOfItems']);

        $item = $result['itemListElement'][0]['item'];
        $this->assertEquals('CreativeWorkSeries', $item['@type']);
        $this->assertStringEndsWith('/christ/sermons/series/test-series#series', $item['@id']);
        $this->assertEquals('Test Series', $item['name']);

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
