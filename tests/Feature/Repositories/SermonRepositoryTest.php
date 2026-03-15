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
}
