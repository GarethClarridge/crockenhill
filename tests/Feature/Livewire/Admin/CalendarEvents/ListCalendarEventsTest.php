<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\CalendarEvents;

use App\Data\CalendarCategorizationResult;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\GoogleCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListCalendarEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_has_aria_label_on_categorize_select(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create([
            'title' => 'Specific Event',
            'meeting_slug' => null,
            'start_datetime' => now()->addDay(),
        ]);

        Livewire::test(ListCalendarEvents::class)
            ->assertSeeHtml('aria-label="Categorise event: Specific Event"');
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListCalendarEvents::class)
            ->assertStatus(200)
            ->assertSee('Calendar events');
    }

    #[Test]
    public function it_lists_calendar_events(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create(['title' => 'Event One', 'start_datetime' => now()->addDay()]);
        CalendarEvent::factory()->create(['title' => 'Event Two', 'start_datetime' => now()->addDays(2)]);

        Livewire::test(ListCalendarEvents::class)
            ->assertSee('Event One')
            ->assertSee('Event Two');
    }

    #[Test]
    public function it_filters_by_search_term(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create(['title' => 'Prayer Meeting', 'start_datetime' => now()->addDay()]);
        CalendarEvent::factory()->create(['title' => 'Bible Study', 'start_datetime' => now()->addDays(2)]);

        Livewire::test(ListCalendarEvents::class)
            ->set('search', 'Prayer')
            ->assertSee('Prayer Meeting')
            ->assertDontSee('Bible Study');
    }

    #[Test]
    public function it_filters_by_meeting(): void
    {
        $this->actingAs($this->admin);

        $meeting = Meeting::factory()->create(['slug' => 'sunday-service']);
        CalendarEvent::factory()->create(['title' => 'Sunday Service Event', 'meeting_slug' => 'sunday-service', 'start_datetime' => now()->addDay()]);
        CalendarEvent::factory()->create(['title' => 'Other Event', 'meeting_slug' => null, 'start_datetime' => now()->addDays(2)]);

        Livewire::test(ListCalendarEvents::class)
            ->set('meetingFilter', 'sunday-service')
            ->assertSee('Sunday Service Event')
            ->assertDontSee('Other Event');
    }

    #[Test]
    public function it_filters_uncategorized_only(): void
    {
        $this->actingAs($this->admin);

        Meeting::factory()->create(['slug' => 'existing-meeting']);
        CalendarEvent::factory()->create(['title' => 'Categorised Event', 'meeting_slug' => 'existing-meeting', 'start_datetime' => now()->addDay()]);
        CalendarEvent::factory()->create(['title' => 'Uncategorised Event', 'meeting_slug' => null, 'start_datetime' => now()->addDays(2)]);

        Livewire::test(ListCalendarEvents::class)
            ->set('uncategorizedOnly', true)
            ->assertSee('Uncategorised Event')
            ->assertDontSee('Categorised Event');
    }

    #[Test]
    public function it_filters_upcoming_only_by_default(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create(['title' => 'Upcoming Event', 'start_datetime' => now()->addDay()]);
        CalendarEvent::factory()->create(['title' => 'Past Event', 'start_datetime' => now()->subDay()]);

        Livewire::test(ListCalendarEvents::class)
            ->assertSee('Upcoming Event')
            ->assertDontSee('Past Event');
    }

    #[Test]
    public function it_can_show_past_events_when_upcoming_filter_is_off(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create(['title' => 'Past Event', 'start_datetime' => now()->subDay()]);

        Livewire::test(ListCalendarEvents::class)
            ->set('upcomingOnly', false)
            ->assertSee('Past Event');
    }

    #[Test]
    public function it_can_categorize_an_event(): void
    {
        $this->actingAs($this->admin);

        $event = CalendarEvent::factory()->create(['meeting_slug' => null]);
        $meeting = Meeting::factory()->create(['slug' => 'new-meeting']);

        $this->mock(CalendarService::class, function ($mock) use ($event) {
            $mock->shouldReceive('manuallyCategorizeEvent')
                ->once()
                ->with($event->id, 'new-meeting')
                ->andReturn(new CalendarCategorizationResult($event, false));
        });

        Livewire::test(ListCalendarEvents::class)
            ->call('categorize', $event->id, 'new-meeting')
            ->assertDispatched('notify', type: 'success', message: 'Event categorised');
    }

    #[Test]
    public function it_requires_admin_authorization_for_categorize_action(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $event = CalendarEvent::factory()->create(['meeting_slug' => null]);

        $this->actingAs($user);

        // While route middleware usually prevents access,
        // the action itself should also be protected by WithAdminAuthorization
        Livewire::test(ListCalendarEvents::class)
            ->call('categorize', $event->id, 'some-meeting')
            ->assertForbidden();
    }

    #[Test]
    public function it_can_sync_calendar_events(): void
    {
        $this->actingAs($this->admin);

        $this->mock(GoogleCalendarSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncFromGoogleCalendar')
                ->once()
                ->andReturn([
                    'processed_events' => 12,
                    'deleted_events' => 2,
                    'uncategorized_events' => 3,
                ]);
        });

        Livewire::test(ListCalendarEvents::class)
            ->call('syncCalendar')
            ->assertDispatched(
                'notify',
                type: 'success',
                message: 'Sync completed! Processed: 12, Deleted: 2, Uncategorised: 3'
            );
    }

    #[Test]
    public function it_reports_calendar_sync_failures_without_exposing_the_exception(): void
    {
        $this->actingAs($this->admin);

        $this->mock(GoogleCalendarSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncFromGoogleCalendar')
                ->once()
                ->andThrow(new \RuntimeException('Sensitive API details'));
        });

        Livewire::test(ListCalendarEvents::class)
            ->call('syncCalendar')
            ->assertDispatched('notify', type: 'error', message: 'Sync failed due to an internal error.')
            ->assertDontSee('Sensitive API details');
    }
}
