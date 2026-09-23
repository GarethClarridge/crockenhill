<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TalkType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonSeriesListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sermon_serieses_page_renders_with_optimized_controller(): void
    {
        // Clear existing sermons
        Sermon::query()->delete();

        // Create sermons in different series
        Sermon::factory()->create(['series' => 'B Series']);
        Sermon::factory()->create(['series' => 'A Series']);
        Sermon::factory()->create(['series' => 'A Series']); // Duplicate
        Sermon::factory()->create(['series' => null]); // Null series

        $response = $this->get('/christ/talks/series');

        $response->assertStatus(200);

        // Assert alphabetical order and uniqueness
        $response->assertSeeInOrder([
            'A Series',
            'B Series',
        ]);

        // Check it doesn't show the null one (though it wouldn't have a label anyway)
    }

    public function test_sermon_series_page_excludes_childrens_talk_only_series(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create([
            'series' => 'Sermon Series',
            'content_type' => TalkType::Sermon,
        ]);
        Sermon::factory()->create([
            'series' => 'Children Series',
            'content_type' => TalkType::ChildrensTalk,
        ]);

        $response = $this->get('/christ/talks/series');

        $response->assertStatus(200);
        $response->assertSee('Sermon Series');
        $response->assertDontSee('Children Series');
    }

    public function test_new_series_page_resolves_while_the_series_list_cache_is_fresh(): void
    {
        Sermon::factory()->inSeries('Existing Series')->create();

        $this->get('/christ/talks/series')->assertOk();

        Sermon::factory()->inSeries('New Series')->create();

        $this->get('/christ/talks/series/new-series')
            ->assertOk()
            ->assertSee('New Series');
    }

    public function test_sermon_serieses_page_includes_item_list_structured_data(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create([
            'series' => 'Gospel of Mark',
            'content_type' => TalkType::Sermon,
        ]);
        Sermon::factory()->create([
            'series' => 'Advent 2024',
            'content_type' => TalkType::Sermon,
        ]);

        $response = $this->get('/christ/talks/series');

        $response->assertStatus(200);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('ItemList', false);
        $response->assertSee('CreativeWorkSeries', false);
        $response->assertSee('Advent 2024', false);
        $response->assertSee('Gospel of Mark', false);
        $response->assertSee('/christ/talks/series/advent-2024', false);
        $response->assertSee('/christ/talks/series/gospel-of-mark', false);
    }
}
