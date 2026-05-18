<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonBrowseSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sermons_archive_page_has_correct_seo_data(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $response->assertSee('<title>Sermons | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Explore the sermon archive at Crockenhill Baptist Church. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series.">', false);
        $response->assertSee('<link rel="canonical" href="http://localhost/christ/sermons">', false);
    }

    public function test_filtered_sermons_archive_has_dynamic_seo_data(): void
    {
        $response = $this->get('/christ/sermons?book=John&chapter=3');

        $response->assertStatus(200);
        $response->assertSee('<title>John 3 | Sermons | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Watch or listen to Bible-based sermons on John 3 from Crockenhill Baptist Church. Explore recent teaching from our morning and evening services.">', false);
        $response->assertSee('<link rel="canonical" href="http://localhost/christ/sermons?book=John&amp;chapter=3">', false);
    }

    public function test_paginated_unfiltered_archive_keeps_its_page_canonical_url(): void
    {
        $response = $this->get('/christ/sermons?page=2');

        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="http://localhost/christ/sermons?page=2">', false);
    }

    public function test_preacher_sermons_page_has_correct_seo_data(): void
    {
        $preacher = Preacher::query()->firstOrCreate(
            ['slug' => 'john-smith-browse-test'],
            ['name' => 'John Smith Browse Test', 'is_active' => true]
        );

        $response = $this->get('/christ/sermons/preachers/john-smith-browse-test');

        $response->assertStatus(200);
        $response->assertSee('<title>Sermons by John Smith Browse Test | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse all sermons preached by John Smith Browse Test at Crockenhill Baptist Church.">', false);
    }

    public function test_series_sermons_page_has_correct_seo_data(): void
    {
        Sermon::factory()->create(['series' => 'Living Hope', 'date' => '2024-01-01']);

        $response = $this->get('/christ/sermons/series/living-hope');

        $response->assertStatus(200);
        $response->assertSee('<title>Sermon Series: Living Hope | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse all sermons in the &quot;Living Hope&quot; series from Crockenhill Baptist Church.">', false);
    }

    public function test_serieses_index_page_has_correct_seo_data(): void
    {
        $response = $this->get('/christ/sermons/series');

        $response->assertStatus(200);
        $response->assertSee('<title>Sermon Series | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse sermon series from Crockenhill Baptist Church.">', false);
    }

    public function test_individual_sermon_page_has_improved_seo_data(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen']);
        $sermon = Sermon::factory()->create([
            'title' => 'The Glory of Christ',
            'preacher' => 'John Owen',
            'preacher_id' => $preacher->id,
            'slug' => 'the-glory-of-christ',
            'date' => '2024-03-20',
        ]);

        // Use the canonical date-based URL directly
        $canonicalUrl = url('/christ/sermons/2024/03/the-glory-of-christ');
        $response = $this->get($canonicalUrl);

        $response->assertStatus(200);

        // Improved title format
        $response->assertSee('<title>The Glory of Christ | John Owen | Crockenhill Baptist Church</title>', false);

        // Canonical link points to the date-based URL
        $response->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);

        // Meta tags title updated
        $response->assertSee('<meta property="og:title" content="The Glory of Christ | John Owen | Crockenhill Baptist Church">', false);

        // Redundant breadcrumb JSON-LD removed (should only see the one from x-breadcrumbs)
        $response->assertSee('"@type": "Article"', false);
    }
}
