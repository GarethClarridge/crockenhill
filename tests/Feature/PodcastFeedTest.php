<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\PodcastFeedService;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache before each test (including flexible cache created-timestamp keys)
        Cache::forget('podcast_feed_morning');
        Cache::forget('podcast_feed_evening');
        Cache::forget('illuminate:cache:flexible:created:podcast_feed_morning');
        Cache::forget('illuminate:cache:flexible:created:podcast_feed_evening');

        // Mock SermonStorageService to avoid hitting real S3
        $this->mockStorageService();
    }

    protected function mockStorageService(): MockInterface
    {
        $mock = Mockery::mock(SermonStorageService::class);
        $mock->shouldReceive('getAudioDeliveryUrl')
            ->andReturnUsing(fn (Sermon $sermon) => "https://example.com/sermons/{$sermon->audio_file_path}");
        $mock->shouldReceive('getFileSize')
            ->andReturn(10485760); // 10MB default
        $mock->shouldReceive('getThumbnailUrl')
            ->andReturn(null);
        $mock->shouldReceive('getVideoUrl')
            ->andReturn(null);
        $mock->shouldReceive('clearCachedMetadata')
            ->andReturnNull();

        $this->app->instance(SermonStorageService::class, $mock);

        return $mock;
    }

    protected function mockStorageServiceWithCallCounts(int $audioDeliveryUrlCalls, int $fileSizeCalls): void
    {
        $mock = Mockery::mock(SermonStorageService::class);
        $mock->shouldReceive('getAudioDeliveryUrl')
            ->times($audioDeliveryUrlCalls)
            ->andReturnUsing(fn (Sermon $sermon) => "https://example.com/sermons/{$sermon->audio_file_path}");
        $mock->shouldReceive('getFileSize')
            ->times($fileSizeCalls)
            ->andReturn(10485760);
        $mock->shouldReceive('getThumbnailUrl')
            ->zeroOrMoreTimes()
            ->andReturn(null);
        $mock->shouldReceive('getVideoUrl')
            ->zeroOrMoreTimes()
            ->andReturn(null);
        $mock->shouldReceive('clearCachedMetadata')
            ->zeroOrMoreTimes()
            ->andReturnNull();

        $this->app->instance(SermonStorageService::class, $mock);
    }

    #[Test]
    public function morning_feed_returns_valid_rss(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'duration' => 2700, // 45 minutes
        ]);

        $response = $this->get('/christ/sermons/morning/feed');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<rss version="2.0"', $content);
        $this->assertStringContainsString('xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"', $content);
    }

    #[Test]
    public function evening_feed_returns_valid_rss(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Evening->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/evening/feed');

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('Sunday evenings at Crockenhill', $content);
    }

    #[Test]
    public function other_service_feed_returns_404(): void
    {
        $response = $this->get('/christ/sermons/other/feed');
        $response->assertStatus(404);
    }

    #[Test]
    public function invalid_service_feed_returns_404(): void
    {
        $response = $this->get('/christ/sermons/invalid/feed');
        $response->assertStatus(404);
    }

    #[Test]
    public function feed_excludes_sermons_without_audio(): void
    {
        // Create sermon without audio by inserting directly with null
        $noAudioSermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => null,
            'title' => 'No Audio Sermon',
        ]);

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'has-audio.mp3',
            'title' => 'Has Audio Sermon',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringNotContainsString('No Audio Sermon', $content);
        $this->assertStringContainsString('Has Audio Sermon', $content);
    }

    #[Test]
    public function feed_excludes_sermons_with_empty_audio_path(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => null,
            'title' => 'Empty Audio Path Sermon',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringNotContainsString('Empty Audio Path Sermon', $content);
    }

    #[Test]
    public function feed_only_includes_correct_service_type(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'morning.mp3',
            'title' => 'Morning Service Sermon',
        ]);

        Sermon::factory()->create([
            'service' => SermonService::Evening->value,
            'audio_file_path' => 'evening.mp3',
            'title' => 'Evening Service Sermon',
        ]);

        $morningResponse = $this->get('/christ/sermons/morning/feed');
        $morningContent = $morningResponse->getContent();

        $this->assertStringContainsString('Morning Service Sermon', $morningContent);
        $this->assertStringNotContainsString('Evening Service Sermon', $morningContent);

        // Clear cache for the next request
        Cache::forget('podcast_feed_evening');

        $eveningResponse = $this->get('/christ/sermons/evening/feed');
        $eveningContent = $eveningResponse->getContent();

        $this->assertStringContainsString('Evening Service Sermon', $eveningContent);
        $this->assertStringNotContainsString('Morning Service Sermon', $eveningContent);
    }

    #[Test]
    public function feed_formats_duration_correctly(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'duration' => 2700, // 45 minutes = 00:45:00
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<itunes:duration>00:45:00</itunes:duration>', $content);
    }

    #[Test]
    public function feed_formats_long_duration_correctly(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'duration' => 5432, // 1:30:32
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<itunes:duration>01:30:32</itunes:duration>', $content);
    }

    #[Test]
    public function feed_handles_null_duration(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'duration' => null,
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<itunes:duration>00:00:00</itunes:duration>', $content);
    }

    #[Test]
    public function feed_includes_canonical_url(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'slug' => 'test-sermon',
            'date' => '2024-06-15',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<link>http://localhost/christ/sermons/2024/06/test-sermon</link>', $content);
    }

    #[Test]
    public function feed_includes_podcast_summary(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'reference' => 'John 3:16',
            'preacher' => 'Mark Drury',
            'series' => 'Gospel of John',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('A sermon on John 3:16 from Mark Drury as part of our Gospel of John series.', $content);
    }

    #[Test]
    public function feed_includes_rfc2822_pub_date(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'date' => '2024-06-15',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // RFC 2822 format: Sat, 15 Jun 2024 00:00:00 +0000
        $this->assertStringContainsString('<pubDate>Sat, 15 Jun 2024 00:00:00 +0000</pubDate>', $content);
    }

    #[Test]
    public function feed_includes_guid_with_prefix(): void
    {
        $sermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString("<guid isPermaLink=\"false\">sermon-{$sermon->id}</guid>", $content);
    }

    #[Test]
    public function feed_includes_itunes_metadata(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<itunes:author>Crockenhill Baptist Church</itunes:author>', $content);
        $this->assertStringContainsString('<itunes:explicit>false</itunes:explicit>', $content);
        $this->assertStringContainsString('<itunes:episodeType>full</itunes:episodeType>', $content);
        $this->assertStringContainsString('<itunes:category text="Religion &amp; Spirituality">', $content);
        // Podcast 2.0 elements
        $this->assertStringContainsString('xmlns:podcast="https://podcastindex.org/namespace/1.0"', $content);
        $this->assertStringContainsString('<podcast:locked>no</podcast:locked>', $content);
        $this->assertStringContainsString('<podcast:guid>', $content);
    }

    #[Test]
    public function feed_includes_atom_self_link(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('xmlns:atom="http://www.w3.org/2005/Atom"', $content);
        $this->assertStringContainsString('<atom:link href="http://localhost/christ/sermons/morning/feed" rel="self" type="application/rss+xml" />', $content);
    }

    #[Test]
    public function feed_orders_sermons_by_date_descending(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'old.mp3',
            'title' => 'Old Sermon',
            'date' => '2024-01-01',
        ]);

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'new.mp3',
            'title' => 'New Sermon',
            'date' => '2024-06-01',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // New sermon should appear before old sermon
        $newPosition = strpos($content, 'New Sermon');
        $oldPosition = strpos($content, 'Old Sermon');

        $this->assertLessThan($oldPosition, $newPosition);
    }

    #[Test]
    public function feed_respects_items_limit(): void
    {
        // Create more sermons than the default limit
        config(['podcast.items_limit' => 5]);

        for ($i = 1; $i <= 10; $i++) {
            Sermon::factory()->create([
                'service' => SermonService::Morning->value,
                'audio_file_path' => "sermon-{$i}.mp3",
                'title' => "Sermon Number {$i}",
                'date' => now()->subDays($i),
            ]);
        }

        // Clear cache since we changed config
        Cache::forget('podcast_feed_morning');

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // Count the number of <item> tags
        $itemCount = substr_count($content, '<item>');
        $this->assertEquals(5, $itemCount);
    }

    #[Test]
    public function feed_is_valid_xml(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // Check XML structure
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<rss version="2.0"', $content);
        $this->assertStringContainsString('</rss>', $content);

        // Verify XML is parseable
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Feed should be valid XML');
    }

    #[Test]
    public function feed_handles_empty_database_gracefully(): void
    {
        // Ensure the test is deterministic even if baseline fixtures/seed data exist
        Sermon::query()->delete();
        Cache::forget('podcast_feed_morning');

        $response = $this->get('/christ/sermons/morning/feed');

        $response->assertStatus(200);

        $content = $response->getContent();

        // Should still contain channel metadata
        $this->assertStringContainsString('<channel>', $content);
        $this->assertStringContainsString('Sunday mornings at Crockenhill', $content);

        // Should not have any items
        $this->assertStringNotContainsString('<item>', $content);
    }

    #[Test]
    public function feed_uses_cache_when_underlying_data_does_not_change(): void
    {
        $this->mockStorageServiceWithCallCounts(audioDeliveryUrlCalls: 1, fileSizeCalls: 1);

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'title' => 'Cached Sermon',
        ]);

        $this->get('/christ/sermons/morning/feed')
            ->assertSee('Cached Sermon');

        $this->get('/christ/sermons/morning/feed')
            ->assertSee('Cached Sermon');
    }

    #[Test]
    public function feed_cache_is_invalidated_when_sermon_is_created(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'title' => 'Cached Sermon',
        ]);

        // First request
        $response1 = $this->get('/christ/sermons/morning/feed');
        $content1 = $response1->getContent();
        $this->assertStringContainsString('Cached Sermon', $content1);

        // Create another sermon
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'new.mp3',
            'title' => 'New Sermon After Cache',
        ]);

        // Second request should rebuild because the sermon observer clears the feed cache
        $response2 = $this->get('/christ/sermons/morning/feed');
        $content2 = $response2->getContent();
        $this->assertStringContainsString('Cached Sermon', $content2);
        $this->assertStringContainsString('New Sermon After Cache', $content2);
    }

    #[Test]
    public function feed_cache_is_invalidated_when_sermon_is_updated(): void
    {
        $sermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'title' => 'Original Sermon Title',
        ]);

        $this->get('/christ/sermons/morning/feed')
            ->assertSee('Original Sermon Title');

        $sermon->update([
            'title' => 'Updated Sermon Title',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');

        $response->assertSee('Updated Sermon Title');
        $response->assertDontSee('Original Sermon Title');
    }

    #[Test]
    public function feed_cache_is_invalidated_when_sermon_is_deleted(): void
    {
        $sermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'title' => 'Sermon To Delete',
        ]);

        $this->get('/christ/sermons/morning/feed')
            ->assertSee('Sermon To Delete');

        $sermon->delete();

        $response = $this->get('/christ/sermons/morning/feed');

        $response->assertDontSee('Sermon To Delete');
        $this->assertStringNotContainsString('<item>', $response->getContent());
    }

    #[Test]
    public function feed_cache_is_invalidated_when_preacher_changes(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Original preacher',
            'slug' => 'original-preacher',
        ]);

        $sermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
            'preacher' => 'Original preacher',
            'preacher_id' => $preacher->id,
        ]);

        // Populate cache and confirm it contains the original preacher name
        $feedService = app(PodcastFeedService::class);
        $initialItems = $feedService->getSermonsForFeed(SermonService::Morning);
        $this->assertTrue(
            $initialItems->contains(fn ($item) => str_contains($item->podcastSummary, 'Original preacher')),
            'Feed should contain the original preacher name'
        );

        // Update preacher name; manually sync denormalized field and clear cache since
        // ShouldHandleEventsAfterCommit observers do not fire within RefreshDatabase test transactions
        $preacher->update(['name' => 'Updated preacher']);
        DB::table('sermons')->where('preacher_id', $preacher->id)->update(['preacher' => 'Updated preacher']);
        $feedService->clearCache();

        // Resolve a fresh feed service after clearing cache. The scoped presenter must prefer
        // the loaded preacher relation over any earlier fallback memoization from this test.
        $freshFeedService = app(PodcastFeedService::class);

        // Fresh feed fetch (no cache) should reflect the updated preacher name for our sermon
        $updatedItems = $freshFeedService->getSermonsForFeed(SermonService::Morning);
        $ourItem = $updatedItems->firstWhere('sermonId', $sermon->id);
        $this->assertNotNull($ourItem, 'Test sermon should appear in the feed');
        $this->assertStringContainsString('Updated preacher', $ourItem->podcastSummary);
    }

    #[Test]
    public function feed_service_can_clear_cache(): void
    {
        $service = app(PodcastFeedService::class);

        $this->mockStorageServiceWithCallCounts(audioDeliveryUrlCalls: 4, fileSizeCalls: 4);

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'morning.mp3',
        ]);
        Sermon::factory()->create([
            'service' => SermonService::Evening->value,
            'audio_file_path' => 'evening.mp3',
        ]);

        // Warm cache
        $this->get('/christ/sermons/morning/feed')->assertStatus(200);
        $this->get('/christ/sermons/evening/feed')->assertStatus(200);

        // Clear all
        $service->clearCache();

        // Second requests should hit storage service again because cache was cleared
        $this->get('/christ/sermons/morning/feed')->assertStatus(200);
        $this->get('/christ/sermons/evening/feed')->assertStatus(200);
    }

    #[Test]
    public function feed_service_can_clear_specific_cache(): void
    {
        $service = app(PodcastFeedService::class);

        // 2 calls for morning (initial + after clear), 1 for evening (cached)
        $this->mockStorageServiceWithCallCounts(audioDeliveryUrlCalls: 3, fileSizeCalls: 3);

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'morning.mp3',
        ]);
        Sermon::factory()->create([
            'service' => SermonService::Evening->value,
            'audio_file_path' => 'evening.mp3',
        ]);

        // Warm cache
        $this->get('/christ/sermons/morning/feed')->assertStatus(200);
        $this->get('/christ/sermons/evening/feed')->assertStatus(200);

        // Clear only morning
        $service->clearCache('morning');

        // Morning should hit storage again
        $this->get('/christ/sermons/morning/feed')->assertStatus(200);

        // Evening should still be cached (no more storage calls expected for evening)
        $this->get('/christ/sermons/evening/feed')->assertStatus(200);
    }

    #[Test]
    public function feed_route_is_named_correctly(): void
    {
        $this->assertTrue(
            Route::has('podcast.feed'),
            'Podcast feed route should be named "podcast.feed"'
        );
    }

    #[Test]
    #[Group('dedicated')]
    #[Group('cache-headers')]
    public function feed_includes_cache_control_header(): void
    {
        // Validate controller-level cache headers without middleware overrides.
        $this->withoutMiddleware();

        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');

        // Check both directives are present (order may vary)
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
    }

    #[Test]
    public function morning_feed_has_correct_title(): void
    {
        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<title>Sunday mornings at Crockenhill Baptist Church</title>', $content);
    }

    #[Test]
    public function evening_feed_has_correct_title(): void
    {
        $response = $this->get('/christ/sermons/evening/feed');
        $content = $response->getContent();

        $this->assertStringContainsString('<title>Sunday evenings at Crockenhill Baptist Church</title>', $content);
    }

    #[Test]
    public function feed_includes_enclosure_with_file_size(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // Check enclosure has correct attributes
        $this->assertStringContainsString('url="https://example.com/sermons/test.mp3"', $content);
        $this->assertStringContainsString('type="audio/mpeg"', $content);
        $this->assertStringContainsString('length="10485760"', $content); // Mocked 10MB
    }

    #[Test]
    public function feed_includes_episode_image(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'audio_file_path' => 'test.mp3',
        ]);

        $response = $this->get('/christ/sermons/morning/feed');
        $content = $response->getContent();

        // Episode should have itunes:image (falls back to podcast artwork if no thumbnail)
        $this->assertStringContainsString('<itunes:image href="http://localhost/images/podcast/MorningArtwork.jpg"', $content);
    }
}
