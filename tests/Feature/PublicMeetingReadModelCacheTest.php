<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CalendarEventStatus;
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

    #[Test]
    public function past_events_within_two_years_are_shown(): void
    {
        $page = Page::factory()->create([
            'slug' => 'past-events-page',
            'area' => 'community',
            'heading' => 'Past Events',
            'admin' => 'no',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'past-events-meeting',
            'page_id' => $page->id,
        ]);

        // Create a confirmed past event 6 months ago
        CalendarEvent::factory()->create([
            'meeting_slug' => 'past-events-meeting',
            'title' => 'Recent Past Event',
            'start_datetime' => now()->subMonths(6)->setHour(10),
            'end_datetime' => now()->subMonths(6)->setHour(11),
            'status' => CalendarEventStatus::Confirmed,
        ]);

        $this->get('/community/past-events-meeting')
            ->assertOk()
            ->assertSee('Recent Past Event');
    }

    #[Test]
    public function past_events_older_than_two_years_are_excluded(): void
    {
        $page = Page::factory()->create([
            'slug' => 'old-events-page',
            'area' => 'community',
            'heading' => 'Old Events',
            'admin' => 'no',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'old-events-meeting',
            'page_id' => $page->id,
        ]);

        // Create a confirmed past event 3 years ago
        CalendarEvent::factory()->create([
            'meeting_slug' => 'old-events-meeting',
            'title' => 'Very Old Event',
            'start_datetime' => now()->subYears(3)->subDay()->setHour(10),
            'end_datetime' => now()->subYears(3)->subDay()->setHour(11),
            'status' => CalendarEventStatus::Confirmed,
        ]);

        $this->get('/community/old-events-meeting')
            ->assertOk()
            ->assertDontSee('Very Old Event');
    }
}
