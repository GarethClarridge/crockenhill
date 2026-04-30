<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherPublicCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preacher_public_list_is_cached_when_visiting_index(): void
    {
        Cache::forget('public_preacher_list');
        $this->assertFalse(Cache::has('public_preacher_list'));

        Preacher::factory()->create(['is_active' => true]);

        $this->get('/christ/sermons/preachers');

        $this->assertTrue(Cache::has('public_preacher_list'));
    }

    #[Test]
    public function preacher_public_list_cache_is_invalidated_when_preacher_is_created(): void
    {
        $this->get('/christ/sermons/preachers');
        $this->assertTrue(Cache::has('public_preacher_list'));

        Preacher::factory()->create(['is_active' => true]);

        $this->assertFalse(Cache::has('public_preacher_list'));
    }

    #[Test]
    public function preacher_public_list_cache_is_invalidated_when_preacher_is_updated(): void
    {
        $preacher = Preacher::factory()->create(['is_active' => true]);
        $this->get('/christ/sermons/preachers');
        $this->assertTrue(Cache::has('public_preacher_list'));

        $preacher->update(['name' => 'Updated Name']);

        $this->assertFalse(Cache::has('public_preacher_list'));
    }

    #[Test]
    public function preacher_public_list_cache_is_invalidated_when_preacher_is_deleted(): void
    {
        $preacher = Preacher::factory()->create(['is_active' => true]);
        $this->get('/christ/sermons/preachers');
        $this->assertTrue(Cache::has('public_preacher_list'));

        $preacher->delete();

        $this->assertFalse(Cache::has('public_preacher_list'));
    }

    #[Test]
    public function preacher_public_list_cache_is_invalidated_when_sermon_is_created(): void
    {
        Preacher::factory()->create(['is_active' => true]);
        $this->get('/christ/sermons/preachers');
        $this->assertTrue(Cache::has('public_preacher_list'));

        Sermon::factory()->create();

        $this->assertFalse(Cache::has('public_preacher_list'));
    }
}
