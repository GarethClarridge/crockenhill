<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonListingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function series_page_caches_sermons(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Genesis']);
        $slug = 'genesis';
        Cache::forget("sermons_series_$slug");

        $this->get("/christ/sermons/series/$slug");
        $this->assertTrue(Cache::has("sermons_series_$slug"));
    }

    #[Test]
    public function service_page_caches_sermons(): void
    {
        Cache::forget('sermons_service_morning');

        $this->get('/christ/sermons/morning');
        $this->assertTrue(Cache::has('sermons_service_morning'));
    }

    #[Test]
    public function series_cache_is_invalidated_when_sermon_in_series_is_updated(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Genesis']);
        $slug = 'genesis';

        $this->get("/christ/sermons/series/$slug");
        $this->assertTrue(Cache::has("sermons_series_$slug"));

        $sermon->update(['title' => 'Updated Genesis Sermon']);

        $this->assertFalse(Cache::has("sermons_series_$slug"));
    }

    #[Test]
    public function service_cache_is_invalidated_when_sermon_in_service_is_deleted(): void
    {
        $sermon = Sermon::factory()->create(['service' => 'morning']);

        $this->get('/christ/sermons/morning');
        $this->assertTrue(Cache::has('sermons_service_morning'));

        $sermon->delete();

        $this->assertFalse(Cache::has('sermons_service_morning'));
    }
}
