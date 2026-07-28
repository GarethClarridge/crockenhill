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
            $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
            $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Evening Sermons', route('podcast.feed', 'evening'));
        }
    }

    #[Test]
    public function podcast_discovery_links_have_specialized_titles_on_service_pages(): void
    {
        // Morning service page
        $response = $this->get(route('sermons.service', 'morning'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Morning Services Podcast', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Evening Sermons', route('podcast.feed', 'evening'));

        // Evening service page
        $response = $this->get(route('sermons.service', 'evening'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Evening Services Podcast', route('podcast.feed', 'evening'));
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_sermon_index(): void
    {
        $response = $this->get(route('sermons.index'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response->getContent(), 'Sunday Evening Sermons', route('podcast.feed', 'evening'));
    }

    private function assertHasPodcastDiscoveryLink(string $content, string $title, string $href): void
    {
        $escapedTitle = preg_quote($title, '/');
        $escapedHref = preg_quote($href, '/');

        // Lookahead to make sure all expected attributes exist within the same <link> tag
        $pattern = '/<link\b'
            .'(?=[^>]*?\brel\s*=\s*["\']alternate["\'])'
            .'(?=[^>]*?\btype\s*=\s*["\']application\/rss\+xml["\'])'
            .'(?=[^>]*?\btitle\s*=\s*["\']'.$escapedTitle.'["\'])'
            .'(?=[^>]*?\bhref\s*=\s*["\']'.$escapedHref.'["\'])'
            .'[^>]*>/i';

        $this->assertMatchesRegularExpression(
            $pattern,
            $content,
            sprintf('Page is missing podcast discovery link with title "%s" and href "%s"', $title, $href)
        );
    }
}
