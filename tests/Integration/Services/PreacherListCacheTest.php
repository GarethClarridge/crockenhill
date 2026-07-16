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
        Cache::flush();
    }

    #[Test]
    public function it_returns_active_preachers_for_admin_list_and_caches_the_result(): void
    {
        Preacher::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Zack', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Adam', 'is_active' => true]);
        Preacher::factory()->inactive()->create(['name' => 'Inactive']);

        DB::enableQueryLog();

        // 1. Initial call - should trigger DB query
        DB::flushQueryLog();
        $list = $this->repository->forAdminList();

        $this->assertNotEmpty(DB::getQueryLog(), 'Expected the first call to trigger a database query.');

        // 2. Second call should NOT trigger a DB query.
        DB::flushQueryLog();
        $this->repository->forAdminList();
        $this->assertEmpty(DB::getQueryLog(), 'Expected the second call to be served from the cache without database queries.');

        $this->assertCount(2, $list);
        $this->assertEquals(['Adam', 'Zack'], $list->values()->all());
        $this->assertEquals($preacherB->id, $list->keys()[0]);
        $this->assertEquals($preacherA->id, $list->keys()[1]);
    }

    #[Test]
    public function it_returns_active_preachers_with_sermon_counts_for_public_list_and_caches_the_result(): void
    {
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

        DB::enableQueryLog();

        // 1. Initial call - should trigger DB query
        DB::flushQueryLog();
        $list = $this->repository->forPublicList();

        $this->assertNotEmpty(DB::getQueryLog(), 'Expected the first call to trigger a database query.');

        // 2. Second call should NOT trigger a DB query.
        DB::flushQueryLog();
        $this->repository->forPublicList();
        $this->assertEmpty(DB::getQueryLog(), 'Expected the second call to be served from the cache without database queries.');

        $this->assertCount(2, $list);

        $first = $list->first();
        $last = $list->last();

        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertEquals('Preacher A', $first->name);
        $this->assertEquals(2, $first->sermons_count);
        $this->assertEquals('Preacher B', $last->name);
        $this->assertEquals(1, $last->sermons_count);
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
