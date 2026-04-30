<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SermonRepository::class);
    }

    #[Test]
    public function it_returns_empty_array_when_no_series_exist(): void
    {
        Sermon::query()->delete();

        $result = $this->repository->getExistingSeries();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_returns_unique_series_names_sorted_alphabetically(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create(['series' => 'Romans Study']);
        Sermon::factory()->create(['series' => 'John Study']);
        Sermon::factory()->create(['series' => 'John Study']); // Duplicate
        Sermon::factory()->create(['series' => 'Acts Study']);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(3, $result);
        $this->assertEquals(['Acts Study', 'John Study', 'Romans Study'], $result);
    }

    #[Test]
    public function it_filters_out_null_and_empty_series_names(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create(['series' => 'Valid Series']);
        Sermon::factory()->create(['series' => null]);
        Sermon::factory()->create(['series' => '']);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(1, $result);
        $this->assertEquals(['Valid Series'], $result);
    }

    #[Test]
    public function it_returns_sermons_for_a_specific_preacher(): void
    {
        $preacher = Preacher::factory()->create();
        $otherPreacher = Preacher::factory()->create();

        $preacherSermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'preacher_id' => $otherPreacher->id,
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        $result = $this->repository->getSermonsByPreacher($preacher);

        $this->assertCount(1, $result);
        $this->assertEquals($preacherSermon->id, $result->first()->id);
    }

    #[Test]
    public function it_caches_preacher_sermon_listing(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'caching-preacher']);
        Sermon::factory()->create(['preacher_id' => $preacher->id]);

        // First call should hit the DB and cache
        $this->repository->getSermonsByPreacher($preacher);
        $this->assertTrue(Cache::has('sermons_preacher_caching-preacher'));

        // Manually update DB without clearing cache
        Sermon::query()->where('preacher_id', $preacher->id)->update(['title' => 'Updated Title']);

        // Second call should return cached data (original title)
        $result = $this->repository->getSermonsByPreacher($preacher);
        $this->assertNotEquals('Updated Title', $result->first()->title);
    }

    #[Test]
    public function it_invalidates_preacher_cache_when_preacher_listing_is_cleared(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'invalidation-preacher']);
        Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $this->repository->getSermonsByPreacher($preacher);
        $this->assertTrue(Cache::has('sermons_preacher_invalidation-preacher'));

        $this->repository->clearListingCaches($preacher);

        $this->assertFalse(Cache::has('sermons_preacher_invalidation-preacher'));
    }

    #[Test]
    public function it_invalidates_preacher_cache_when_sermon_is_modified(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'sermon-invalidation-preacher']);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $this->repository->getSermonsByPreacher($preacher);
        $this->assertTrue(Cache::has('sermons_preacher_sermon-invalidation-preacher'));

        $this->repository->clearListingCaches($sermon);

        $this->assertFalse(Cache::has('sermons_preacher_sermon-invalidation-preacher'));
    }

    #[Test]
    public function it_returns_latest_sermons_grouped_by_date(): void
    {
        Sermon::query()->delete();
        Cache::forget('latest_sermons');

        $today = \Illuminate\Support\Carbon::today();

        // Create sermons for 7 distinct dates
        for ($i = 0; $i < 7; $i++) {
            Sermon::factory()->create([
                'date' => $today->copy()->subDays($i),
                'content_type' => \App\Enums\SermonContentType::Sermon,
            ]);
        }

        $result = $this->repository->getLatestSermons();

        // Should return 6 groups (dates)
        $this->assertCount(6, $result);
        $this->assertEquals($today->format('Y-m-d'), $result->keys()->first());
    }

    #[Test]
    public function it_filters_out_childrens_talks_from_latest_sermons(): void
    {
        Sermon::query()->delete();
        Cache::forget('latest_sermons');

        $today = \Illuminate\Support\Carbon::today();

        Sermon::factory()->create([
            'title' => 'Main Sermon',
            'content_type' => \App\Enums\SermonContentType::Sermon,
            'date' => $today,
        ]);
        Sermon::factory()->create([
            'title' => "Children's Talk",
            'content_type' => \App\Enums\SermonContentType::ChildrensTalk,
            'date' => $today,
        ]);

        $result = $this->repository->getLatestSermons();

        $this->assertCount(1, $result);
        $this->assertCount(1, $result->first());
        $this->assertEquals('Main Sermon', $result->first()->first()->title);
    }

    #[Test]
    public function it_caches_latest_sermons(): void
    {
        Sermon::query()->delete();
        Cache::forget('latest_sermons');

        Sermon::factory()->create([
            'title' => 'Original Title',
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        // First call caches
        $this->repository->getLatestSermons();
        $this->assertTrue(Cache::has('latest_sermons'));

        // Modify DB
        Sermon::query()->update(['title' => 'Updated Title']);

        // Second call should return cached data
        $result = $this->repository->getLatestSermons();
        $this->assertEquals('Original Title', $result->first()->first()->title);
    }

    #[Test]
    public function it_returns_all_sermons_grouped_by_date(): void
    {
        Sermon::query()->delete();
        Cache::forget('all_sermons');

        Sermon::factory()->create(['date' => '2024-01-01', 'content_type' => \App\Enums\SermonContentType::Sermon]);
        Sermon::factory()->create(['date' => '2024-01-02', 'content_type' => \App\Enums\SermonContentType::Sermon]);

        $result = $this->repository->getAllSermons();

        $this->assertCount(2, $result);
        $this->assertTrue($result->has('2024-01-02'));
    }

    #[Test]
    public function it_caches_all_sermons(): void
    {
        Sermon::query()->delete();
        Cache::forget('all_sermons');

        Sermon::factory()->create([
            'title' => 'Original All Title',
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        // First call caches
        $this->repository->getAllSermons();
        $this->assertTrue(Cache::has('all_sermons'));

        // Modify DB
        Sermon::query()->update(['title' => 'Updated All Title']);

        // Second call should return cached data
        $result = $this->repository->getAllSermons();
        $this->assertEquals('Original All Title', $result->first()->first()->title);
    }

    #[Test]
    public function it_returns_sermons_by_service(): void
    {
        Sermon::query()->delete();
        Sermon::factory()->create(['service' => SermonService::Morning, 'content_type' => \App\Enums\SermonContentType::Sermon]);
        Sermon::factory()->create(['service' => SermonService::Evening, 'content_type' => \App\Enums\SermonContentType::Sermon]);

        $result = $this->repository->getSermonsByService(SermonService::Morning);

        $this->assertCount(1, $result);
        $this->assertEquals(SermonService::Morning, $result->first()->service);
    }

    #[Test]
    public function it_caches_service_listing(): void
    {
        Sermon::query()->delete();
        Cache::forget('sermons_service_morning');

        Sermon::factory()->create([
            'title' => 'Original Service Title',
            'service' => SermonService::Morning,
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        // First call caches
        $this->repository->getSermonsByService(SermonService::Morning);
        $this->assertTrue(Cache::has('sermons_service_morning'));

        // Modify DB
        Sermon::query()->update(['title' => 'Updated Service Title']);

        // Second call should return cached data
        $result = $this->repository->getSermonsByService(SermonService::Morning);
        $this->assertEquals('Original Service Title', $result->first()->title);
    }

    #[Test]
    public function it_caches_series_for_display_sorted_alphabetically(): void
    {
        Sermon::query()->delete();
        Cache::forget('sermon_series');

        Sermon::factory()->create(['series' => 'Z Series', 'content_type' => \App\Enums\SermonContentType::Sermon]);
        Sermon::factory()->create(['series' => 'A Series', 'content_type' => \App\Enums\SermonContentType::Sermon]);

        $result = $this->repository->getSeriesForDisplay();

        $this->assertEquals(['A Series', 'Z Series'], $result);
        $this->assertTrue(Cache::has('sermon_series'));
    }

    #[Test]
    public function it_generates_base_slug(): void
    {
        $slug = $this->repository->generateUniqueSlug('Test Sermon Title');

        $this->assertEquals('test-sermon-title', $slug);
    }

    #[Test]
    public function it_appends_counter_for_duplicate_slugs(): void
    {
        Sermon::factory()->create(['slug' => 'test-sermon']);

        $slug = $this->repository->generateUniqueSlug('Test Sermon');

        $this->assertEquals('test-sermon-1', $slug);
    }

    #[Test]
    public function it_generates_unique_slug_with_incrementing_counter(): void
    {
        Sermon::factory()->create(['slug' => 'test-sermon']);
        Sermon::factory()->create(['slug' => 'test-sermon-1']);
        Sermon::factory()->create(['slug' => 'test-sermon-2']);

        $slug = $this->repository->generateUniqueSlug('Test Sermon');

        $this->assertEquals('test-sermon-3', $slug);
    }

    #[Test]
    public function it_excludes_current_sermon_from_slug_uniqueness(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'test-sermon']);

        $slug = $this->repository->generateUniqueSlug('Test Sermon', $sermon->id);

        $this->assertEquals('test-sermon', $slug);
    }

    #[Test]
    public function it_returns_books_and_caches_result_with_new_key(): void
    {
        Cache::forget('sermon_scripture_books_all_all');

        $result = $this->repository->getScriptureBooks();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertTrue(Cache::has('sermon_scripture_books_all_all'));
    }

    #[Test]
    public function it_caches_scripture_books_and_clears_on_clear_listing_caches(): void
    {
        Cache::forget('sermon_scripture_books_all_all');

        $this->repository->getScriptureBooks();
        $this->assertTrue(Cache::has('sermon_scripture_books_all_all'));

        $this->repository->clearListingCaches();

        $this->assertFalse(Cache::has('sermon_scripture_books_all_all'));
    }

    #[Test]
    public function it_caches_scripture_chapters_and_clears_on_clear_listing_caches(): void
    {
        $book = 'John';
        $cacheKey = 'sermon_scripture_chapters_john_all_all';
        Cache::forget($cacheKey);

        $sermon = Sermon::factory()->create([
            'reference' => 'John 1',
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        $this->repository->getScriptureChapters($book);
        $this->assertTrue(Cache::has($cacheKey));

        $this->repository->clearListingCaches($sermon);

        $this->assertFalse(Cache::has($cacheKey));
    }

    #[Test]
    public function get_recent_sermons_for_json_ld_returns_collection_and_caches_result(): void
    {
        Cache::forget('sermons_jsonld_recent_100');

        $result = $this->repository->getRecentSermonsForJsonLd();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertTrue(Cache::has('sermons_jsonld_recent_100'));
    }

    #[Test]
    public function get_recent_sermons_for_json_ld_cache_is_cleared_with_listing_caches(): void
    {
        Cache::forget('sermons_jsonld_recent_100');

        $this->repository->getRecentSermonsForJsonLd();
        $this->assertTrue(Cache::has('sermons_jsonld_recent_100'));

        $this->repository->clearListingCaches();

        $this->assertFalse(Cache::has('sermons_jsonld_recent_100'));
    }

    #[Test]
    public function get_recent_sermons_for_json_ld_respects_limit(): void
    {
        Sermon::factory()->count(5)->create(['content_type' => 'sermon']);

        $result = $this->repository->getRecentSermonsForJsonLd(limit: 3);

        $this->assertLessThanOrEqual(3, $result->count());
    }
}
