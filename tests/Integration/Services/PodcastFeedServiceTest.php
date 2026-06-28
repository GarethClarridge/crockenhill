<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\PodcastFeedItemReadModel;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Public\PodcastFeedService;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $this->storageService = $this->createStub(SermonStorageService::class);
        $this->service = new PodcastFeedService(
            $this->storageService,
            new SermonViewPresenter(
                app(SermonExposurePolicy::class),
                $this->storageService,
                app(SermonTranscriptReader::class),
            ),
            app(SermonRepository::class),
        );
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

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertCount(3, $sermons);
        $sermons->each(fn ($feedItem) => $this->assertInstanceOf(PodcastFeedItemReadModel::class, $feedItem));
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
            'audio_file_path' => null,
        ]);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertCount(2, $sermons);
    }

    #[Test]
    public function it_excludes_childrens_talks_from_feed_results(): void
    {
        Sermon::factory()->create([
            'service' => 'morning',
            'title' => 'Main Sermon',
            'audio_file_path' => 'sermons/sermon.mp3',
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'service' => 'morning',
            'title' => "Children's Talk",
            'audio_file_path' => 'sermons/childrens-talk.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertCount(1, $sermons);
        $this->assertSame('Main Sermon', $sermons->sole()->title);
    }

    #[Test]
    public function it_enriches_sermons_with_enclosure_url(): void
    {
        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://cdn.example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(5242880);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $sermon = $sermons->first();
        $this->assertEquals('https://cdn.example.com/sermon.mp3', $sermon->enclosureUrl);
        $this->assertEquals(5242880, $sermon->enclosureLength);
    }

    #[Test]
    public function it_uses_zero_for_file_size_when_null(): void
    {
        Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(null);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertEquals(0, $sermons->first()->enclosureLength);
    }

    #[Test]
    public function it_uses_guarded_audio_delivery_url_for_enclosure(): void
    {
        $sermon = Sermon::factory()->create([
            'service' => 'morning',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $expectedUrl = route('sermons.audio', $sermon);
        $this->storageService->method('getAudioDeliveryUrl')->willReturn($expectedUrl);
        $this->storageService->method('getFileSize')->willReturn(1024);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertSame($expectedUrl, $sermons->first()->enclosureUrl);
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
    public function it_returns_metadata_for_morning_service_type(): void
    {
        $metadata = $this->service->getFeedMetadata('morning');

        $this->assertArrayHasKey('title', $metadata);
        $this->assertNotEmpty($metadata['title']);
        $this->assertStringContainsString('morning', strtolower($metadata['title']));
        $this->assertArrayHasKey('podcast_guid', $metadata);
        $this->assertNotEmpty($metadata['podcast_guid']);
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

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        DB::enableQueryLog();

        // First call should hit the database.
        $this->service->getSermonsForFeed(SermonService::Morning);
        $queryCountAfterFirstCall = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queryCountAfterFirstCall);

        // Second call should hit the cache and NOT trigger any new database queries.
        $this->service->getSermonsForFeed(SermonService::Morning);
        $this->assertCount($queryCountAfterFirstCall, DB::getQueryLog());

        DB::disableQueryLog();
    }

    #[Test]
    public function clear_cache_removes_specific_service_type(): void
    {
        config(['podcast.cache' => ['enabled' => true, 'ttl' => 3600, 'stale_ttl' => 7200]]);
        Sermon::factory()->create(['service' => 'morning', 'audio_file_path' => 'test.mp3']);
        Sermon::factory()->create(['service' => 'evening', 'audio_file_path' => 'test.mp3']);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        DB::enableQueryLog();

        // Populate both caches
        $this->service->getSermonsForFeed(SermonService::Morning);
        $this->service->getSermonsForFeed(SermonService::Evening);
        $initialQueryCount = count(DB::getQueryLog());

        // Clear only morning
        $this->service->clearCache('morning');

        // Morning should hit DB again
        $this->service->getSermonsForFeed(SermonService::Morning);
        $this->assertCount($initialQueryCount + 1, DB::getQueryLog());

        // Evening should still be cached
        $this->service->getSermonsForFeed(SermonService::Evening);
        $this->assertCount($initialQueryCount + 1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    #[Test]
    public function clear_cache_removes_all_feeds_when_no_type_specified(): void
    {
        config(['podcast.cache' => ['enabled' => true, 'ttl' => 3600, 'stale_ttl' => 7200]]);
        Sermon::factory()->create(['service' => 'morning', 'audio_file_path' => 'test.mp3']);
        Sermon::factory()->create(['service' => 'evening', 'audio_file_path' => 'test.mp3']);

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        DB::enableQueryLog();

        // Populate both caches
        $this->service->getSermonsForFeed(SermonService::Morning);
        $this->service->getSermonsForFeed(SermonService::Evening);
        $initialQueryCount = count(DB::getQueryLog());

        // Clear all
        $this->service->clearCache();

        // Both should hit DB again
        $this->service->getSermonsForFeed(SermonService::Morning);
        $this->service->getSermonsForFeed(SermonService::Evening);
        $this->assertCount($initialQueryCount + 2, DB::getQueryLog());

        DB::disableQueryLog();
    }

    #[Test]
    public function it_returns_empty_collection_when_no_sermons_exist(): void
    {
        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(0);

        config(['podcast.cache.enabled' => false]);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

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

        $this->storageService->method('getAudioDeliveryUrl')->willReturn('https://example.com/sermon.mp3');
        $this->storageService->method('getFileSize')->willReturn(1024);

        $sermons = $this->service->getSermonsForFeed(SermonService::Morning);

        $this->assertCount(2, $sermons);
    }
}
