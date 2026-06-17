<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\PreacherListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherListCacheTest extends TestCase
{
    use RefreshDatabase;

    private PreacherListCache $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PreacherListCache::class);
    }

    #[Test]
    public function it_returns_active_preachers_for_admin_list_and_caches_the_result(): void
    {
        Cache::forget(PreacherListCache::ADMIN_LIST_CACHE_KEY);
        Preacher::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Zack', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Adam', 'is_active' => true]);
        Preacher::factory()->inactive()->create(['name' => 'Inactive']);

        // First call populates the cache
        DB::enableQueryLog();
        $this->repository->forAdminList();
        $this->assertNotEmpty(DB::getQueryLog());
        DB::flushQueryLog();

        // Second call (clearing internal memoization) should hit cache
        $this->repository->clearInternalCaches();
        $list = $this->repository->forAdminList();

        $this->assertCount(0, DB::getQueryLog());

        $this->assertCount(2, $list);
        $this->assertEquals(['Adam', 'Zack'], $list->values()->all());
        $this->assertEquals($preacherB->id, $list->keys()[0]);
        $this->assertEquals($preacherA->id, $list->keys()[1]);
    }

    #[Test]
    public function it_returns_active_preachers_with_sermon_counts_for_public_list_and_caches_the_result(): void
    {
        Cache::forget(PreacherListCache::PUBLIC_LIST_CACHE_KEY);
        Preacher::query()->delete();
        Sermon::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Preacher A', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Preacher B', 'is_active' => true]);

        Sermon::factory()->count(2)->create([
            'preacher_id' => $preacherA->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        // Children's talks should not count towards the sermon count.
        Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        // First call populates the cache
        DB::enableQueryLog();
        $this->repository->forPublicList();
        $this->assertNotEmpty(DB::getQueryLog());
        DB::flushQueryLog();

        // Second call (clearing internal memoization) should hit cache
        $this->repository->clearInternalCaches();
        $list = $this->repository->forPublicList();

        $this->assertCount(0, DB::getQueryLog());

        $this->assertCount(2, $list);
        $this->assertEquals('Preacher A', $list->first()->name);
        $this->assertEquals(2, $list->first()->sermons_count);
        $this->assertEquals('Preacher B', $list->last()->name);
        $this->assertEquals(1, $list->last()->sermons_count);
    }

    #[Test]
    public function it_deserializes_cached_preachers_as_proper_model_instances(): void
    {
        Cache::forget(PreacherListCache::PUBLIC_LIST_CACHE_KEY);
        Preacher::query()->delete();

        Preacher::factory()->create(['name' => 'Cache Test Preacher', 'is_active' => true]);

        // First call populates the cache
        $this->repository->forPublicList();

        // Second call reads from cache — models must rehydrate as Preacher, not __PHP_Incomplete_Class
        $cached = $this->repository->forPublicList();

        $this->assertInstanceOf(Preacher::class, $cached->first());
        $this->assertEquals('Cache Test Preacher', $cached->first()->name);
    }
}
