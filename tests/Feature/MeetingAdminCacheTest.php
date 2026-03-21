<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingAdminCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function admin_meeting_list_cache_is_populated_on_request(): void
    {
        Cache::forget('admin_meeting_list');

        $this->assertFalse(Cache::has('admin_meeting_list'));

        Meeting::getForAdminList();

        $this->assertTrue(Cache::has('admin_meeting_list'));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_created(): void
    {
        Meeting::getForAdminList();
        $this->assertTrue(Cache::has('admin_meeting_list'));

        Meeting::factory()->create([
            'slug' => 'new-meeting',
        ]);

        $this->assertFalse(Cache::has('admin_meeting_list'));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        Meeting::getForAdminList();
        $this->assertTrue(Cache::has('admin_meeting_list'));

        $meeting->update(['who' => 'Updated Who']);

        $this->assertFalse(Cache::has('admin_meeting_list'));
    }

    #[Test]
    public function admin_meeting_list_cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        Meeting::getForAdminList();
        $this->assertTrue(Cache::has('admin_meeting_list'));

        $meeting->delete();

        $this->assertFalse(Cache::has('admin_meeting_list'));
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

        Meeting::getForAdminList();
        $this->assertTrue(Cache::has('admin_meeting_list'));

        $page->update(['heading' => 'New Heading']);

        $this->assertFalse(Cache::has('admin_meeting_list'));
    }
}
