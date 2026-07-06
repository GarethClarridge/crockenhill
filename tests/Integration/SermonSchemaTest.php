<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Sermon;
use App\Seo\SermonItemListPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        $this->travelTo(Carbon::parse('2024-05-20 12:00:00'));
    }

    #[Test]
    public function it_includes_enhanced_metadata_in_individual_sermon_schema(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Enhanced Schema Sermon',
            'slug' => 'enhanced-schema-sermon',
            'date' => '2024-05-19',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'preacher' => 'David Johnson',
        ]);

        $response = $this->get("/christ/sermons/2024/05/{$sermon->slug}");

        $response->assertStatus(200);
        $content = $response->getContent();

        // Verify keywords
        $this->assertStringContainsString('"keywords": "Test Series, David Johnson, John 3:16"', $content);

        // Verify isPartOf (Series)
        $this->assertStringContainsString('"isPartOf": {', $content);
        $this->assertStringContainsString('"@type": "CreativeWorkSeries"', $content);
        $this->assertStringContainsString('"name": "Test Series"', $content);
        $this->assertStringContainsString('/christ/sermons/series/test-series', $content);

        // Verify about (Scripture)
        $this->assertStringContainsString('"about": {', $content);
        $this->assertStringContainsString('"@type": "Thing"', $content);
        $this->assertStringContainsString('"name": "John 3:16"', $content);
    }

    #[Test]
    public function it_includes_enhanced_metadata_in_item_list_schema(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'List Item Sermon',
            'date' => '2024-05-19',
            'series' => 'List Series',
            'reference' => 'Genesis 1:1',
            'preacher' => 'David Johnson',
        ]);

        $presenter = app(SermonItemListPresenter::class);
        $result = $presenter->toItemList(collect([$sermon]));

        $item = $result['itemListElement'][0]['item'];

        $this->assertEquals('List Series, David Johnson, Genesis 1:1', $item['keywords']);

        $this->assertArrayHasKey('isPartOf', $item);
        $this->assertEquals('CreativeWorkSeries', $item['isPartOf']['@type']);
        $this->assertEquals('List Series', $item['isPartOf']['name']);
        $this->assertStringContainsString('/christ/sermons/series/list-series', $item['isPartOf']['url']);

        $this->assertArrayHasKey('about', $item);
        $this->assertEquals('Thing', $item['about']['@type']);
        $this->assertEquals('Genesis 1:1', $item['about']['name']);
    }

    #[Test]
    public function it_handles_sermons_without_series_or_reference(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Minimal Sermon',
            'series' => null,
            'reference' => null,
            'preacher' => 'David Johnson',
        ]);

        $presenter = app(SermonItemListPresenter::class);
        $result = $presenter->toItemList(collect([$sermon]));

        $item = $result['itemListElement'][0]['item'];

        $this->assertEquals('David Johnson', $item['keywords']);
        $this->assertArrayNotHasKey('isPartOf', $item);
        $this->assertArrayNotHasKey('about', $item);
    }

    #[Test]
    public function date_modified_handles_legacy_timestamps_safely(): void
    {
        $sermon = Sermon::factory()->make([
            'date' => Carbon::parse('2024-01-01'),
        ]);

        // Manually set an invalid date that might come from DB as '0000-00-00'
        // We use year 0 to simulate the legacy condition.
        $sermon->updated_at = Carbon::create(0, 1, 1, 0, 0, 0);

        $presenter = app(SermonItemListPresenter::class);
        $result = $presenter->toItemList(collect([$sermon]));

        $item = $result['itemListElement'][0]['item'];

        // Should fall back to datePublished if updated_at is invalid
        $this->assertEquals('2024-01-01T00:00:00+00:00', $item['datePublished']);
        $this->assertEquals('2024-01-01T00:00:00+00:00', $item['dateModified']);
    }
}
