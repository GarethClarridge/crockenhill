<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function podcast_discovery_links_are_present_on_the_homepage(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertDiscoveryLinks($response);
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_the_sermon_index(): void
    {
        $response = $this->get(route('sermons.index'));

        $response->assertStatus(200);
        $this->assertDiscoveryLinks($response);
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_individual_sermon_pages(): void
    {
        $sermon = Sermon::factory()->create([
            'date' => '2024-01-01',
            'slug' => 'test-sermon',
        ]);

        $response = $this->get(route('sermons.show.dated', [
            'year' => 2024,
            'month' => '01',
            'sermon' => 'test-sermon',
        ]));

        $response->assertStatus(200);
        $this->assertDiscoveryLinks($response);
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_static_pages(): void
    {
        $page = Page::factory()->create([
            'area' => 'church',
            'slug' => 'about-us',
        ]);

        $response = $this->get('/church/about-us');

        $response->assertStatus(200);
        $this->assertDiscoveryLinks($response);
    }

    #[Test]
    public function specialized_titles_are_used_on_service_filter_pages(): void
    {
        // Morning service filter
        $response = $this->get(route('sermons.service', 'morning'));
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Services Podcast" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">', false);

        // Evening service filter
        $response = $this->get(route('sermons.service', 'evening'));
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Services Podcast" href="'.route('podcast.feed', 'evening').'">', false);
    }

    private function assertDiscoveryLinks($response): void
    {
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">', false);
    }
}
