<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use App\Repositories\MeetingListRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingAdminCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private MeetingListRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(MeetingListRepository::class);
    }

    #[Test]
    public function admin_meeting_list_cache_is_populated_on_request(): void
    {
        Cache::forget(MeetingListRepository::ADMIN_LIST_CACHE_KEY);

        $this->assertFalse(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));

        $this->repository->forAdminList();

        $this->assertTrue(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_created(): void
    {
        $this->repository->forAdminList();
        $this->assertTrue(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));

        Meeting::factory()->create([
            'slug' => 'new-meeting',
        ]);

        $this->assertFalse(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $this->repository->forAdminList();
        $this->assertTrue(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));

        $meeting->update(['who' => 'Updated Who']);

        $this->assertFalse(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $this->repository->forAdminList();
        $this->assertTrue(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));

        $meeting->delete();

        $this->assertFalse(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));
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
        $this->assertTrue(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));

        $page->update(['heading' => 'New Heading']);

        $this->assertFalse(Cache::has(MeetingListRepository::ADMIN_LIST_CACHE_KEY));
    }
}
