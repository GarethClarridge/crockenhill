<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicMeetingReadModelCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_meeting_read_model_cache_is_invalidated_when_calendar_events_change(): void
    {
        $page = Page::factory()->create([
            'slug' => 'meeting-cache-page',
            'area' => 'community',
            'heading' => 'Meeting cache page',
            'admin' => 'no',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'cached-meeting',
            'page_id' => $page->id,
        ]);

        $cacheKey = "public_meeting_view_{$meeting->id}";
        Cache::forget($cacheKey);

        $this->get('/community/cached-meeting')->assertOk();

        $this->assertTrue(Cache::has($cacheKey));

        CalendarEvent::factory()->forMeeting($meeting)->upcoming()->create([
            'title' => 'Fresh event',
        ]);

        $this->assertFalse(Cache::has($cacheKey));
        $this->get('/community/cached-meeting')->assertSee('Fresh event');
    }
}
