<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Crockenhill\Sermon;
use Crockenhill\Service;
use Database\Factories\SermonFactory;
use Database\Factories\ServiceFactory;
use Carbon\Carbon;

class RssFeedControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $morningService;
    protected $eveningService;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure consistent service names that the controller might expect
        $this->morningService = Service::factory()->create(['name' => 'Morning Service']);
        $this->eveningService = Service::factory()->create(['name' => 'Evening Service']);
    }

    private function createSermonForFeed(Service $service, Carbon $date, array $overrides = [])
    {
        return Sermon::factory()
            ->forService($service)
            ->withDate($date)
            ->create(array_merge([
                'title' => 'Test Sermon Title',
                'slug' => \Illuminate\Support\Str::slug('Test Sermon Title'),
                'preacher' => 'Rev. Test Preacher',
                'series' => 'Test Series',
                'description' => 'Full sermon description.',
                'points' => json_encode(['Point 1 for feed', 'Point 2 for feed']),
                'audio_url' => 'http://example.com/audio.mp3', // Ensure this is a full URL if used directly
                'video_url' => null, // For simplicity
            ], $overrides));
    }

    /** @test */
    public function morning_feed_is_valid_rss_and_contains_correct_sermons()
    {
        // Create sermons
        $recentMorningSermon = $this->createSermonForFeed($this->morningService, Carbon::now()->subDays(1), ['title' => 'Recent Morning Sermon']);
        $oldMorningSermon = $this->createSermonForFeed($this->morningService, Carbon::now()->subDays(30), ['title' => 'Old Morning Sermon']);
        $eveningSermon = $this->createSermonForFeed($this->eveningService, Carbon::now()->subDays(1), ['title' => 'Recent Evening Sermon']);

        $response = $this->get('/rss/morning'); // Adjust route if necessary

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8'); // Or application/xml

        $xml = new \SimpleXMLElement($response->getContent());

        $this->assertEquals('Crockenhill Baptist Church - Morning Sermons', (string) $xml->channel->title); // Example channel title
        $this->assertCount(1, $xml->channel->item); // Assuming feed shows recent items, not all
        // This count depends on the feed's logic (e.g. last 10, or only very recent ones)
        // For this test, let's assume it should only pick up $recentMorningSermon if it's very recent (e.g. last 7 days)
        // Adjusting expectation: if feed shows more, this needs to change.
        // If the feed is meant to show both morning sermons:
        // $this->assertCount(2, $xml->channel->item);
        // $titles = [];
        // foreach ($xml->channel->item as $item) { $titles[] = (string) $item->title; }
        // $this->assertContains('Recent Morning Sermon', $titles);
        // $this->assertContains('Old Morning Sermon', $titles);
        // $this->assertNotContains('Recent Evening Sermon', $titles);

        // For now, assuming a simple "last N items from this service" logic
        // Let's create more to test ordering and limit (if any)
        $veryRecentMorningSermon = $this->createSermonForFeed($this->morningService, Carbon::now(), ['title' => 'Very Recent Morning Sermon']);
        Sermon::factory()->count(15)->forService($this->morningService)->withDate(Carbon::now()->subDays(2))->create(); // Create more sermons

        $response = $this->get('/rss/morning');
        $xml = new \SimpleXMLElement($response->getContent());

        $itemTitles = array_map('strval', $xml->xpath('//item/title'));
        $this->assertContains('Very Recent Morning Sermon', $itemTitles);
        $this->assertContains('Recent Morning Sermon', $itemTitles); // This might not be there if limit is small
        $this->assertNotContains('Recent Evening Sermon', $itemTitles);

        if (count($itemTitles) > 0) {
             $this->assertEquals('Very Recent Morning Sermon', $itemTitles[0]); // Newest first
        }
    }

    /** @test */
    public function evening_feed_is_valid_rss_and_contains_correct_sermons()
    {
        $recentEveningSermon = $this->createSermonForFeed($this->eveningService, Carbon::now()->subDays(1), ['title' => 'Recent Evening Sermon']);
        $this->createSermonForFeed($this->morningService, Carbon::now()->subDays(1), ['title' => 'Recent Morning Sermon']);

        $response = $this->get('/rss/evening'); // Adjust route
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

        $xml = new \SimpleXMLElement($response->getContent());
        $this->assertEquals('Crockenhill Baptist Church - Evening Sermons', (string) $xml->channel->title); // Example

        $itemTitles = array_map('strval', $xml->xpath('//item/title'));
        $this->assertContains('Recent Evening Sermon', $itemTitles);
        $this->assertNotContains('Recent Morning Sermon', $itemTitles);
    }

    /** @test */
    public function feed_includes_correct_sermon_details()
    {
        $sermonDate = Carbon::create(2023, 3, 15, 10, 0, 0);
        $sermon = $this->createSermonForFeed($this->morningService, $sermonDate, [
            'title' => 'Specific Sermon Title',
            'slug' => 'specific-sermon-title',
            'preacher' => 'Dr. Specific',
            'series' => 'A Specific Series',
            'description' => 'Detailed description of the sermon.',
            'points' => json_encode(['Key point A', 'Key point B']),
            'audio_url' => 'http://example.com/specific_audio.mp3',
        ]);

        $response = $this->get('/rss/morning');
        $xml = new \SimpleXMLElement($response->getContent());

        $foundItem = null;
        foreach ($xml->channel->item as $item) {
            if ((string) $item->title === 'Specific Sermon Title') {
                $foundItem = $item;
                break;
            }
        }
        $this->assertNotNull($foundItem, "Sermon 'Specific Sermon Title' not found in feed.");

        $this->assertEquals('Specific Sermon Title', (string) $foundItem->title);
        $this->assertEquals(url("/sermons/{$sermon->id}"), (string) $foundItem->link); // Assuming route structure
        $this->assertEquals($sermonDate->toRfc2822String(), (string) $foundItem->pubDate);

        // Description might be complex, could be CDATA
        // For this test, let's assume description field is used if available, else points.
        // The actual logic is in the RssFeedController.
        $this->assertStringContainsString('Detailed description of the sermon.', (string) $foundItem->description);
        // Or if points are used:
        // $this->assertStringContainsString('Key point A', (string) $foundItem->description);

        $enclosure = $foundItem->enclosure;
        $this->assertNotNull($enclosure);
        $this->assertEquals('http://example.com/specific_audio.mp3', (string) $enclosure['url']);
        $this->assertEquals('audio/mpeg', (string) $enclosure['type']); // Assuming MP3
        // $this->assertNotEmpty((string) $enclosure['length']); // Optional but good
    }

    /** @test */
    public function feed_handles_empty_sermons_gracefully()
    {
        $response = $this->get('/rss/morning');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = new \SimpleXMLElement($response->getContent());
        $this->assertEquals('Crockenhill Baptist Church - Morning Sermons', (string) $xml->channel->title);
        $this->assertCount(0, $xml->channel->item);
    }

    /** @test */
    public function feed_limit_is_enforced()
    {
        // Create more sermons than a hypothetical limit (e.g., 10)
        $limit = 10; // Assuming a limit
        for ($i = 0; $i < $limit + 5; $i++) {
            $this->createSermonForFeed($this->morningService, Carbon::now()->subDays($i), ['title' => "Sermon {$i}"]);
        }

        $response = $this->get('/rss/morning');
        $xml = new \SimpleXMLElement($response->getContent());
        $this->assertLessThanOrEqual($limit, count($xml->channel->item));

        // Also check that the newest ones are present
        $itemTitles = array_map('strval', $xml->xpath('//item/title'));
        $this->assertEquals("Sermon 0", $itemTitles[0]); // Newest (day 0)
        $this->assertEquals("Sermon 1", $itemTitles[1]); // Second newest (day 1)

        $this->markTestIncomplete('Feed limit test needs to know the actual limit if one is implemented.');
    }
}
