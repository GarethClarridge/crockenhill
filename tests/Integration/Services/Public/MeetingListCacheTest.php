<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Public;

use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\MeetingListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingListCacheTest extends TestCase
{
    use RefreshDatabase;

    private MeetingListCache $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MeetingListCache::class);
        Cache::flush();
        $this->service->clearInternalCaches();
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
    public function for_admin_list_uses_request_level_memoization(): void
    {
        Meeting::factory()->create(['slug' => 'memo-test']);

        $first = $this->service->forAdminList();
        $second = $this->service->forAdminList();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function for_admin_list_uses_flexible_caching(): void
    {
        Meeting::factory()->create(['slug' => 'cache-test']);

        $this->assertFalse(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));

        $this->service->forAdminList();

        $this->assertTrue(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function cache_is_invalidated_when_meeting_is_created(): void
    {
        $this->service->forAdminList();
        $this->assertTrue(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));

        Meeting::factory()->create(['slug' => 'new-meeting']);

        $this->assertFalse(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'update-test']);
        $this->service->forAdminList();
        $this->assertTrue(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));

        $meeting->update(['location' => 'New Location']);

        $this->assertFalse(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'delete-test']);
        $this->service->forAdminList();
        $this->assertTrue(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));

        $meeting->delete();

        $this->assertFalse(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function cache_is_invalidated_when_related_page_is_updated(): void
    {
        $page = Page::factory()->create(['heading' => 'Old Heading']);
        Meeting::factory()->create(['slug' => 'page-update-test', 'page_id' => $page->id]);

        $this->service->forAdminList();
        $this->assertTrue(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));

        $page->update(['heading' => 'New Heading']);

        $this->assertFalse(Cache::has(MeetingListCache::ADMIN_LIST_CACHE_KEY));
    }
}
