<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Enums\TalkType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastFeedControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── happy path ────────────────────────────────────────────────────────

    #[Test]
    public function morning_feed_returns_rss_xml(): void
    {
        $response = $this->get('/christ/talks/morning/feed');

        $response->assertStatus(200);
        $this->assertStringContainsString('rss', strtolower((string) $response->headers->get('Content-Type')));
    }

    #[Test]
    public function evening_feed_returns_rss_xml(): void
    {
        $response = $this->get('/christ/talks/evening/feed');

        $response->assertStatus(200);
        $this->assertStringContainsString('rss', strtolower((string) $response->headers->get('Content-Type')));
    }

    #[Test]
    public function morning_feed_includes_morning_sermons(): void
    {
        $sermon = Sermon::factory()->withAudio()->create([
            'title' => 'Morning Message',
            'service' => SermonService::Morning,
            'content_type' => TalkType::Sermon,
        ]);

        $response = $this->get('/christ/talks/morning/feed');

        $response->assertStatus(200);
        $this->assertStringContainsString('Morning Message', (string) $response->getContent());
    }

    // ── 404 cases ─────────────────────────────────────────────────────────

    #[Test]
    public function feed_returns_404_for_invalid_service(): void
    {
        $response = $this->get('/christ/talks/other/feed');

        $response->assertStatus(404);
    }

    #[Test]
    public function feed_returns_404_for_unknown_service_name(): void
    {
        $response = $this->get('/christ/talks/unknown/feed');

        $response->assertStatus(404);
    }

    // ── content structure ─────────────────────────────────────────────────

    #[Test]
    public function feed_is_valid_xml(): void
    {
        $response = $this->get('/christ/talks/morning/feed');

        $response->assertStatus(200);

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml, 'Podcast feed should be valid XML');
    }
}
