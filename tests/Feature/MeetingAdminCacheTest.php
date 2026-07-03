<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\MeetingListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingAdminCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private MeetingListCache $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(MeetingListCache::class);
    }

    #[Test]
    public function admin_meeting_list_cache_is_populated_on_request(): void
    {
        Cache::forget(MeetingListCache::ADMIN_LIST_CACHE_KEY);
        $this->repository->clearInternalCaches();

        DB::enableQueryLog();

        // First call: executes queries
        $this->repository->forAdminList();
        $this->assertNotEmpty(DB::getQueryLog());

        DB::flushQueryLog();
        $this->repository->clearInternalCaches();

        // Second call: hits cache, no queries
        $this->repository->forAdminList();
        $this->assertEmpty(DB::getQueryLog());
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_created(): void
    {
        $this->repository->forAdminList();
        DB::enableQueryLog();

        Meeting::factory()->create([
            'slug' => 'new-meeting',
        ]);

        $this->repository->clearInternalCaches();
        DB::flushQueryLog();

        $this->repository->forAdminList();

        $this->assertNotEmpty(DB::getQueryLog());
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $this->repository->forAdminList();
        DB::enableQueryLog();

        $meeting->update(['who' => 'Updated Who']);

        $this->repository->clearInternalCaches();
        DB::flushQueryLog();

        $this->repository->forAdminList();

        $this->assertNotEmpty(DB::getQueryLog());
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $this->repository->forAdminList();
        DB::enableQueryLog();

        $meeting->delete();

        $this->repository->clearInternalCaches();
        DB::flushQueryLog();

        $this->repository->forAdminList();

        $this->assertNotEmpty(DB::getQueryLog());
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_linked_page_is_updated(): void
    {
        $page = Page::factory()->create([
            'slug' => 'meeting-page',
            'heading' => 'Original Heading',
        ]);

        Meeting::factory()->create([
            'slug' => 'linked-meeting',
            'page_id' => $page->id,
        ]);

        $this->repository->forAdminList();
        DB::enableQueryLog();

        $page->update(['heading' => 'New Heading']);

        $this->repository->clearInternalCaches();
        DB::flushQueryLog();

        $this->repository->forAdminList();

        $this->assertNotEmpty(DB::getQueryLog());
    }
}
