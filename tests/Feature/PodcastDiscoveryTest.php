<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
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
            $this->assertHasPodcastDiscoveryLink($response, 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
            $this->assertHasPodcastDiscoveryLink($response, 'Sunday Evening Sermons', route('podcast.feed', 'evening'));
        }
    }

    #[Test]
    public function podcast_discovery_links_have_specialized_titles_on_service_pages(): void
    {
        // Morning service page
        $response = $this->get(route('sermons.service', 'morning'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Morning Services Podcast', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Evening Sermons', route('podcast.feed', 'evening'));

        // Evening service page
        $response = $this->get(route('sermons.service', 'evening'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Evening Services Podcast', route('podcast.feed', 'evening'));
    }

    #[Test]
    public function podcast_discovery_links_are_present_on_sermon_index(): void
    {
        $response = $this->get(route('sermons.index'));
        $response->assertOk();
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Morning Sermons', route('podcast.feed', 'morning'));
        $this->assertHasPodcastDiscoveryLink($response, 'Sunday Evening Sermons', route('podcast.feed', 'evening'));
    }

    /**
     * Asserts that the response contains a valid podcast RSS discovery link with the specified title and URL.
     * This checks for the correct attributes in the link tag in a spacing and attribute-order-independent way.
     */
    private function assertHasPodcastDiscoveryLink(Response|TestResponse $response, string $title, string $url): void
    {
        $html = (string) $response->getContent();

        $pattern = '/<link'
            .'(?=[^>]*\brel\s*=\s*["\']alternate["\'])'
            .'(?=[^>]*\btype\s*=\s*["\']application\/rss\+xml["\'])'
            .'(?=[^>]*\btitle\s*=\s*["\']'.preg_quote($title, '/').'["\'])'
            .'(?=[^>]*\bhref\s*=\s*["\']'.preg_quote($url, '/').'["\'])'
            .'[^>]*>/i';

        $this->assertMatchesRegularExpression(
            $pattern,
            $html,
            "Failed asserting that the response HTML has a valid <link> tag with rel=\"alternate\", type=\"application/rss+xml\", title=\"{$title}\", and href=\"{$url}\"."
        );
    }
}
