<?php

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\PodcastFeedService;
use App\Services\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonStorageService $storageService;

    private PodcastFeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageService = $this->createMock(SermonStorageService::class);
        $this->service = new PodcastFeedService($this->storageService);
        Cache::flush();
    }

    #[Test]
    public function it_returns_sermons_for_morning_service(): void
    {
        Sermon::factory()->count(3)->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);
        Sermon::factory()->count(2)->create([
            'service' => 'evening',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed('morning');

        $this->assertCount(3, $sermons);
        $sermons->each(fn ($sermon) => $this->assertEquals('morning', $sermon->service->value));
    }

    #[Test]
    public function it_only_returns_sermons_with_audio_files(): void
    {
        Sermon::factory()->count(2)->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);
        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => '',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed('morning');

        $this->assertCount(2, $sermons);
    }

    #[Test]
    public function it_enriches_sermons_with_enclosure_url(): void
    {
        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://cdn.example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(5242880);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed('morning');

        $sermon = $sermons->first();
        $this->assertEquals('https://cdn.example.com/sermon.mp3', $sermon->enclosure_url);
        $this->assertEquals(5242880, $sermon->enclosure_length);
    }

    #[Test]
    public function it_uses_zero_for_file_size_when_null(): void
    {
        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(null);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed('morning');

        $this->assertEquals(0, $sermons->first()->enclosure_length);
    }

    #[Test]
    public function it_returns_morning_feed_metadata(): void
    {
        $metadata = $this->service->getFeedMetadata('morning');

        $this->assertArrayHasKey('title', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('link', $metadata);
        $this->assertArrayHasKey('image', $metadata);
        $this->assertArrayHasKey('feed_url', $metadata);
        $this->assertArrayHasKey('owner_name', $metadata);
        $this->assertArrayHasKey('owner_email', $metadata);
        $this->assertArrayHasKey('language', $metadata);
        $this->assertArrayHasKey('category', $metadata);
        $this->assertArrayHasKey('podcast_guid', $metadata);
    }

    #[Test]
    public function it_returns_evening_feed_metadata(): void
    {
        $metadata = $this->service->getFeedMetadata('evening');

        $this->assertStringContainsString('evening', strtolower($metadata['title']));
    }

    #[Test]
    public function morning_and_evening_feeds_have_different_guids(): void
    {
        $morning = $this->service->getFeedMetadata('morning');
        $evening = $this->service->getFeedMetadata('evening');

        $this->assertNotEquals($morning['podcast_guid'], $evening['podcast_guid']);
    }

    #[Test]
    public function it_falls_back_to_morning_metadata_for_unknown_service_type(): void
    {
        $metadata = $this->service->getFeedMetadata('unknown');

        $this->assertArrayHasKey('title', $metadata);
        $this->assertNotEmpty($metadata['title']);
    }

    #[Test]
    public function it_caches_sermon_feed_when_caching_enabled(): void
    {
        Cache::flush();
        config(['podcast.cache' => ['enabled' => true, 'ttl' => 3600, 'stale_ttl' => 7200]]);

        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        $this->assertFalse(Cache::has('podcast_feed_morning'));

        $this->service->getSermonsForFeed('morning');

        $this->assertTrue(Cache::has('podcast_feed_morning'));
    }

    #[Test]
    public function clear_cache_removes_specific_service_type(): void
    {
        Cache::put('podcast_feed_morning', 'test_data', 3600);
        Cache::put('podcast_feed_evening', 'test_data', 3600);

        $this->service->clearCache('morning');

        $this->assertFalse(Cache::has('podcast_feed_morning'));
        $this->assertTrue(Cache::has('podcast_feed_evening'));
    }

    #[Test]
    public function clear_cache_removes_all_feeds_when_no_type_specified(): void
    {
        Cache::put('podcast_feed_morning', 'test_data', 3600);
        Cache::put('podcast_feed_evening', 'test_data', 3600);

        $this->service->clearCache();

        $this->assertFalse(Cache::has('podcast_feed_morning'));
        $this->assertFalse(Cache::has('podcast_feed_evening'));
    }

    #[Test]
    public function it_returns_empty_collection_when_no_sermons_exist(): void
    {
        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(0);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed('morning');

        $this->assertCount(0, $sermons);
    }

    #[Test]
    public function it_respects_configured_items_limit(): void
    {
        config(['podcast.items_limit' => 2, 'podcast.cache.enabled' => false]);

        Sermon::factory()->count(5)->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getPublicUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        $sermons = $this->service->getSermonsForFeed('morning');

        $this->assertCount(2, $sermons);
    }
}
