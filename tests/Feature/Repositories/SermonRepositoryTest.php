<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

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
        $this->repository = new SermonRepository;
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

        // Create sermons for 7 distinct dates
        for ($i = 0; $i < 7; $i++) {
            Sermon::factory()->create([
                'date' => now()->subDays($i),
                'content_type' => \App\Enums\SermonContentType::Sermon,
            ]);
        }

        $result = $this->repository->getLatestSermons();

        // Should return 6 groups (dates)
        $this->assertCount(6, $result);
        $this->assertEquals(now()->format('Y-m-d'), $result->keys()->first());
    }

    #[Test]
    public function it_filters_out_childrens_talks_from_latest_sermons(): void
    {
        Sermon::query()->delete();
        Cache::forget('latest_sermons');

        Sermon::factory()->create([
            'title' => 'Main Sermon',
            'content_type' => \App\Enums\SermonContentType::Sermon,
            'date' => now(),
        ]);
        Sermon::factory()->create([
            'title' => "Children's Talk",
            'content_type' => \App\Enums\SermonContentType::ChildrensTalk,
            'date' => now(),
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
        Cache::forget('all_sermons');
        $this->repository->getAllSermons();
        $this->assertTrue(Cache::has('all_sermons'));
    }

    #[Test]
    public function it_returns_sermons_by_service(): void
    {
        Sermon::query()->delete();
        Sermon::factory()->create(['service' => 'morning', 'content_type' => \App\Enums\SermonContentType::Sermon]);
        Sermon::factory()->create(['service' => 'evening', 'content_type' => \App\Enums\SermonContentType::Sermon]);

        $result = $this->repository->getSermonsByService('morning');

        $this->assertCount(1, $result);
        $this->assertEquals('morning', $result->first()->service->value);
    }

    #[Test]
    public function it_caches_service_listing(): void
    {
        Cache::forget('sermons_service_morning');
        $this->repository->getSermonsByService('morning');
        $this->assertTrue(Cache::has('sermons_service_morning'));
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
}
