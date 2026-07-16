<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\CalendarEvents\EditCalendarEvent;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminCalendarEventTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Mock Google Calendar sync to prevent API calls in tests
        $this->mock(GoogleCalendarSyncService::class, function ($mock) {
            $mock->shouldReceive('syncCategorizationToGoogle')->andReturn(false);
        });
    }

    #[Test]
    public function admin_can_still_categorize_after_authorization_is_added(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Prayer Meeting']);
        $meeting = Meeting::factory()->create(['slug' => 'prayer-meeting-admin-test', 'page_id' => $page->id]);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(3)->addHour(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->call('categorize', $event->id, $meeting->slug)
            ->assertDispatched('notify', type: 'success', message: 'Event categorised');

        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'meeting_slug' => $meeting->slug,
        ]);
    }

    #[Test]
    public function admin_can_still_save_calendar_event_after_authorization_is_added(): void
    {
        $this->actingAs($this->admin);

        $event = CalendarEvent::factory()->create([
            'title' => 'Original Title',
            'start_datetime' => now()->addDays(2)->setTime(9, 0),
            'end_datetime' => now()->addDays(2)->setTime(10, 0),
        ]);

        Livewire::test(EditCalendarEvent::class, ['calendarEvent' => $event])
            ->set('title', 'Updated Title')
            ->set('startDatetime', now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('endDatetime', now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Calendar event updated');

        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'Updated Title']);
    }

    #[Test]
    public function it_renders_calendar_events_list(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListCalendarEvents::class)
            ->assertStatus(200)
            ->assertSee('Calendar events');
    }

    #[Test]
    public function it_can_search_and_filter_events_by_meeting(): void
    {
        $this->actingAs($this->admin);

        $morningPage = Page::factory()->create(['heading' => 'Morning Service']);
        $youthPage = Page::factory()->create(['heading' => 'Youth Group']);

        $morningMeeting = Meeting::factory()->create([
            'slug' => 'morning-service',
            'page_id' => $morningPage->id,
        ]);
        $youthMeeting = Meeting::factory()->create([
            'slug' => 'youth-group',
            'page_id' => $youthPage->id,
        ]);

        $futureStart = now()->addDays(7)->startOfHour();
        $futureEnd = (clone $futureStart)->addHour();

        CalendarEvent::factory()->create([
            'meeting_slug' => $morningMeeting->slug,
            'title' => 'Morning Prayer Gathering',
            'description' => 'Prayer and worship',
            'start_datetime' => $futureStart,
            'end_datetime' => $futureEnd,
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => $youthMeeting->slug,
            'title' => 'Youth Fellowship Night',
            'description' => 'Youth discussion',
            'start_datetime' => $futureStart->copy()->addDay(),
            'end_datetime' => $futureEnd->copy()->addDay(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->set('search', 'Morning Prayer')
            ->assertSee('Morning Prayer Gathering')
            ->assertDontSee('Youth Fellowship Night')
            ->set('search', '')
            ->set('meetingFilter', $youthMeeting->slug)
            ->assertSee('Youth Fellowship Night')
            ->assertDontSee('Morning Prayer Gathering');
    }

    #[Test]
    public function it_can_filter_uncategorized_events_only(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Sunday Service']);
        $meeting = Meeting::factory()->create([
            'slug' => 'sunday-service',
            'page_id' => $page->id,
        ]);

        $futureStart = now()->addDays(10)->startOfHour();

        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Needs Categorization',
            'start_datetime' => $futureStart,
            'end_datetime' => $futureStart->copy()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Already Categorized',
            'start_datetime' => $futureStart->copy()->addDay(),
            'end_datetime' => $futureStart->copy()->addDay()->addHour(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->set('uncategorizedOnly', true)
            ->assertSee('Needs Categorization')
            ->assertDontSee('Already Categorized');
    }

    #[Test]
    public function it_can_toggle_upcoming_filter_to_include_past_events(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Prayer Meeting']);
        $meeting = Meeting::factory()->create([
            'slug' => 'prayer-meeting-toggle-test',
            'page_id' => $page->id,
        ]);

        $pastStart = now()->subDays(5)->startOfHour();
        $futureStart = now()->addDays(5)->startOfHour();

        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Past Prayer Night',
            'start_datetime' => $pastStart,
            'end_datetime' => $pastStart->copy()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Upcoming Prayer Night',
            'start_datetime' => $futureStart,
            'end_datetime' => $futureStart->copy()->addHour(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->assertSee('Upcoming Prayer Night')
            ->assertDontSee('Past Prayer Night')
            ->set('upcomingOnly', false)
            ->assertSee('Past Prayer Night');
    }

    #[Test]
    public function it_can_categorize_an_event_manually(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Bible Study']);
        $meeting = Meeting::factory()->create([
            'slug' => 'bible-study-admin-test',
            'page_id' => $page->id,
        ]);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Unsorted Event',
            'is_categorized_automatically' => true,
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(3)->addHour(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->call('categorize', $event->id, $meeting->slug)
            ->assertDispatched('notify', type: 'success', message: 'Event categorised');

        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'meeting_slug' => $meeting->slug,
            'is_categorized_automatically' => false,
        ]);
    }

    #[Test]
    public function edit_calendar_event_mounts_existing_values(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Sunday Worship']);
        $meeting = Meeting::factory()->create([
            'slug' => 'sunday-worship',
            'page_id' => $page->id,
        ]);

        $start = now()->addDays(4)->setTime(10, 30);
        $end = now()->addDays(4)->setTime(12, 0);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Sunday Worship Event',
            'description' => 'Morning service',
            'speaker' => 'Pastor Smith',
            'location' => 'Main Sanctuary',
            'start_datetime' => $start,
            'end_datetime' => $end,
        ]);

        Livewire::test(EditCalendarEvent::class, ['calendarEvent' => $event])
            ->assertSet('title', 'Sunday Worship Event')
            ->assertSet('description', 'Morning service')
            ->assertSet('speaker', 'Pastor Smith')
            ->assertSet('location', 'Main Sanctuary')
            ->assertSet('startDatetime', $start->format('Y-m-d\TH:i'))
            ->assertSet('endDatetime', $end->format('Y-m-d\TH:i'))
            ->assertSet('meetingSlug', $meeting->slug);
    }

    #[Test]
    public function admin_can_update_calendar_event_from_edit_component(): void
    {
        $this->actingAs($this->admin);

        $oldPage = Page::factory()->create(['heading' => 'Old Meeting']);
        $newPage = Page::factory()->create(['heading' => 'New Meeting']);

        $oldMeeting = Meeting::factory()->create([
            'slug' => 'old-meeting',
            'page_id' => $oldPage->id,
        ]);
        $newMeeting = Meeting::factory()->create([
            'slug' => 'new-meeting',
            'page_id' => $newPage->id,
        ]);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => $oldMeeting->slug,
            'title' => 'Old Event Title',
            'description' => 'Old description',
            'speaker' => 'Old speaker',
            'location' => 'Old location',
            'start_datetime' => now()->addDays(2)->setTime(9, 0),
            'end_datetime' => now()->addDays(2)->setTime(10, 0),
            'is_categorized_automatically' => true,
        ]);

        Livewire::test(EditCalendarEvent::class, ['calendarEvent' => $event])
            ->set('title', 'Updated Event Title')
            ->set('description', 'Updated description')
            ->set('speaker', 'Updated speaker')
            ->set('location', 'Updated location')
            ->set('startDatetime', '2026-08-15T18:00')
            ->set('endDatetime', '2026-08-15T19:30')
            ->set('meetingSlug', $newMeeting->slug)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Calendar event updated');

        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'title' => 'Updated Event Title',
            'description' => 'Updated description',
            'speaker' => 'Updated speaker',
            'location' => 'Updated location',
            'meeting_slug' => $newMeeting->slug,
            'is_categorized_automatically' => false,
        ]);
    }

    #[Test]
    public function edit_calendar_event_validates_required_and_relational_fields(): void
    {
        $this->actingAs($this->admin);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Validation Event',
            'start_datetime' => now()->addDays(2)->setTime(9, 0),
            'end_datetime' => now()->addDays(2)->setTime(10, 0),
        ]);

        Livewire::test(EditCalendarEvent::class, ['calendarEvent' => $event])
            ->set('title', '')
            ->set('startDatetime', '2026-07-20T19:00')
            ->set('endDatetime', '2026-07-20T18:00')
            ->set('meetingSlug', 'missing-meeting')
            ->set('status', '')
            ->call('save')
            ->assertHasErrors([
                'title' => ['required'],
                'endDatetime' => ['after_or_equal'],
                'meetingSlug' => ['exists'],
                'status' => ['required'],
            ]);
    }
}
