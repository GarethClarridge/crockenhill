<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Public;

use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\MeetingListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingListCacheTest extends TestCase
{
    use RefreshDatabase;

    private MeetingListCache $service;

    protected function setUp(): void
    {
        parent::setUp();
        Meeting::query()->delete();
        $this->service = app(MeetingListCache::class);
        Cache::flush();
    }

    #[Test]
    public function for_admin_list_returns_collection_mapping_slugs_to_page_headings(): void
    {
        $page1 = Page::factory()->create(['heading' => 'Meeting One Heading']);
        Meeting::factory()->create(['slug' => 'meeting-one', 'page_id' => $page1->id]);

        $page2 = Page::factory()->create(['heading' => 'Meeting Two Heading']);
        Meeting::factory()->create(['slug' => 'meeting-two', 'page_id' => $page2->id]);

        $result = $this->service->forAdminList();

        $this->assertCount(2, $result);
        $this->assertEquals('Meeting One Heading', $result['meeting-one']);
        $this->assertEquals('Meeting Two Heading', $result['meeting-two']);
    }

    #[Test]
    public function for_admin_list_falls_back_to_slug_if_page_is_missing(): void
    {
        Meeting::factory()->create(['slug' => 'meeting-without-page', 'page_id' => null]);

        $result = $this->service->forAdminList();

        $this->assertEquals('meeting-without-page', $result['meeting-without-page']);
    }

    #[Test]
    public function for_admin_list_uses_flexible_caching(): void
    {
        Meeting::factory()->create(['slug' => 'cache-test']);

        DB::enableQueryLog();

        // 1. Initial call - should trigger DB query
        DB::flushQueryLog();
        $this->service->forAdminList();
        $this->assertNotEmpty(DB::getQueryLog(), 'Expected the first call to trigger a database query.');

        // 2. Second call should NOT trigger a DB query.
        DB::flushQueryLog();
        $this->service->forAdminList();
        $this->assertEmpty(DB::getQueryLog(), 'Expected the second call to be served from the cache without database queries.');
    }
}
