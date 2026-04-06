<?php

namespace Tests\Unit;

use App\Models\Sermon;
use App\Presenters\SermonItemListPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonItemListPresenterTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_generates_item_list_json_ld(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => '2024-01-01',
            'preacher' => 'John Doe',
        ]);

        $presenter = app(SermonItemListPresenter::class);
        $result = $presenter->toItemList(collect([$sermon]));

        $this->assertEquals('https://schema.org', $result['@context']);
        $this->assertEquals('ItemList', $result['@type']);
        $this->assertEquals(1, $result['numberOfItems']);

        $item = $result['itemListElement'][0]['item'];
        $this->assertEquals('Article', $item['@type']);
        $this->assertEquals('Test Sermon', $item['name']);
        $this->assertEquals('John Doe', $item['author']['name']);
        $this->assertEquals('2024-01-01T00:00:00+00:00', $item['datePublished']);
    }
}
