<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function podcast_discovery_links_are_present_globally(): void
    {
        $pages = [
            '/',
            '/christ',
            '/church',
            '/community',
            '/calendar',
        ];

        foreach ($pages as $page) {
            $response = $this->get($page);
            $response->assertOk();
            $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">', false);
            $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">', false);
        }
    }

    #[Test]
    public function podcast_discovery_links_have_specialized_titles_on_service_pages(): void
    {
        // Morning service page
        $response = $this->get(route('sermons.service', 'morning'));
        $response->assertOk();
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Services Podcast" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">', false);

        // Evening service page
        $response = $this->get(route('sermons.service', 'evening'));
        $response->assertOk();
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Services Podcast" href="'.route('podcast.feed', 'evening').'">', false);
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_sermon_index(): void
    {
        $response = $this->get(route('sermons.index'));
        $response->assertOk();
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">', false);
        $response->assertSee('<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">', false);
    }
}
