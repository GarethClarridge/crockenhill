<?php

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodcastDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_has_podcast_discovery_links(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_christ_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_sermon_index_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_all_sermons_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ/sermons/all');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_preachers_index_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_individual_preacher_page_has_podcast_discovery_links(): void
    {
        $preacher = Preacher::factory()->create();

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_series_index_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ/sermons/series');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_individual_series_page_has_podcast_discovery_links(): void
    {
        Sermon::factory()->create(['series' => 'Living Hope']);

        $response = $this->get('/christ/sermons/series/living-hope');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_service_page_has_podcast_discovery_links(): void
    {
        $response = $this->get('/christ/sermons/morning');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    public function test_childrens_corner_index_has_podcast_discovery_links(): void
    {
        config(['sermons.childrens_talks.public' => true]);
        $response = $this->get('/christ/childrens-corner');

        $response->assertStatus(200);
        $this->assertPodcastDiscoveryLinksPresent($response);
    }

    private function assertPodcastDiscoveryLinksPresent($response): void
    {
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Services Podcast" href="http://localhost/christ/sermons/morning/feed">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Services Podcast" href="http://localhost/christ/sermons/evening/feed">', false);
    }
}
