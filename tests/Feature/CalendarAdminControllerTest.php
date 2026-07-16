<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CalendarCategorizationResult;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\GoogleCalendarSyncService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->adminUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
    }

    // --- uncategorizedEvents ---

    #[Test]
    public function it_requires_authentication_for_uncategorized_events(): void
    {
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_forbids_non_admin_user_for_uncategorized_events(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertForbidden();
    }

    #[Test]
    public function it_shows_uncategorized_events_page_for_authenticated_user(): void
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertStatus(200);
        $response->assertViewIs('admin.calendar.uncategorized');
        $response->assertViewHas('uncategorizedEvents');
        $response->assertViewHas('meetings');
    }

    #[Test]
    public function it_passes_uncategorized_events_to_view(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        CalendarEvent::factory()->count(2)->create(['meeting_slug' => null]);
        CalendarEvent::factory()->count(3)->create(['meeting_slug' => 'sunday-morning']);

        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertStatus(200);
        $uncategorizedEvents = $response->viewData('uncategorizedEvents');
        $this->assertCount(2, $uncategorizedEvents);
    }

    #[Test]
    public function it_passes_meetings_ordered_by_slug_to_view(): void
    {
        Meeting::factory()->create(['slug' => 'z-meeting']);
        Meeting::factory()->create(['slug' => 'a-meeting']);

        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertStatus(200);
        $meetings = $response->viewData('meetings');
        $meetingSlugs = $meetings->pluck('slug')->values()->all();
        $sortedMeetingSlugs = collect($meetingSlugs)->sort()->values()->all();

        $this->assertSame($sortedMeetingSlugs, $meetingSlugs);
    }

    // --- categorizeEvent ---

    #[Test]
    public function it_requires_authentication_for_categorize_event(): void
    {
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => 1,
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_forbids_non_admin_user_for_categorize_event(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => 1,
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function it_categorizes_event_and_redirects_back(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
        ]);

        $mockService = $this->createMock(CalendarService::class);
        $mockService->expects($this->once())
            ->method('manuallyCategorizeEvent')
            ->with($event->id, 'sunday-morning')
            ->willReturn(new CalendarCategorizationResult($event->fresh(), true));

        $this->app->instance(CalendarService::class, $mockService);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => $event->id,
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    #[Test]
    public function it_flashes_synced_message_when_google_sync_succeeds(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        $event = CalendarEvent::factory()->create(['meeting_slug' => null, 'title' => 'Sunday Service']);

        $mockService = $this->createMock(CalendarService::class);
        $mockService->method('manuallyCategorizeEvent')
            ->willReturn(new CalendarCategorizationResult($event->fresh(), true));

        $this->app->instance(CalendarService::class, $mockService);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => $event->id,
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('synced to Google Calendar', session('success'));
        $this->assertStringContainsString('categorised', session('success'));
    }

    #[Test]
    public function it_flashes_pending_sync_message_when_google_sync_fails(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        $event = CalendarEvent::factory()->create(['meeting_slug' => null, 'title' => 'Sunday Service']);

        $mockService = $this->createMock(CalendarService::class);
        $mockService->method('manuallyCategorizeEvent')
            ->willReturn(new CalendarCategorizationResult($event->fresh(), false));

        $this->app->instance(CalendarService::class, $mockService);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => $event->id,
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('Google sync failed', session('success'));
        $this->assertStringContainsString('will retry on next sync', session('success'));
        $this->assertStringContainsString('categorised', session('success'));
    }

    #[Test]
    public function it_validates_event_id_exists_in_database(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'sunday-morning']);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => 99999, // nonexistent
            'meeting_slug' => 'sunday-morning',
        ]);

        $response->assertSessionHasErrors('event_id');
    }

    #[Test]
    public function it_validates_event_id_is_bounded(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => 2147483648, // out of bounds
            'meeting_slug' => 'sunday-morning',
        ]);

        // Assert the max-bound message specifically so this proves the new max rule
        // fired rather than the pre-existing exists rule.
        $response->assertSessionHasErrors([
            'event_id' => 'The event id field must not be greater than 2147483647.',
        ]);
    }

    #[Test]
    public function it_validates_meeting_slug_exists_in_database(): void
    {
        Meeting::factory()->create(['slug' => 'some-slug']);
        $event = CalendarEvent::factory()->create(['meeting_slug' => 'some-slug']);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', [
            'event_id' => $event->id,
            'meeting_slug' => 'nonexistent-meeting',
        ]);

        $response->assertSessionHasErrors('meeting_slug');
    }

    #[Test]
    public function it_validates_required_fields_for_categorize(): void
    {
        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/categorize', []);

        $response->assertSessionHasErrors(['event_id', 'meeting_slug']);
    }

    // --- patternManagement ---

    #[Test]
    public function it_requires_authentication_for_pattern_management(): void
    {
        $response = $this->get('/admin/calendar/patterns');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_forbids_non_admin_user_for_pattern_management(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);
        $response = $this->get('/admin/calendar/patterns');

        $response->assertForbidden();
    }

    #[Test]
    public function it_shows_pattern_management_page(): void
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/calendar/patterns');

        $response->assertStatus(200);
        $response->assertViewIs('admin.calendar.patterns');
        $response->assertViewHas('patterns');
        $response->assertViewHas('meetings');
    }

    // --- syncCalendar ---

    #[Test]
    public function it_requires_authentication_for_sync_calendar(): void
    {
        $response = $this->post('/admin/calendar/sync');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_forbids_non_admin_user_for_sync_calendar(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);
        $response = $this->post('/admin/calendar/sync');

        $response->assertForbidden();
    }

    #[Test]
    public function it_handles_sync_failure_with_error_session_message(): void
    {
        $mockService = $this->createMock(GoogleCalendarSyncService::class);
        $mockService->expects($this->once())
            ->method('syncFromGoogleCalendar')
            ->willThrowException(new \Exception('Google API unavailable'));

        $this->app->instance(GoogleCalendarSyncService::class, $mockService);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/sync');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $errorMessage = session('error');
        $this->assertStringContainsString('Sync failed due to an internal error.', $errorMessage);
        $this->assertStringNotContainsString('Google API unavailable', $errorMessage);
    }

    #[Test]
    public function it_handles_successful_sync_with_success_session_message(): void
    {
        $mockService = $this->createMock(GoogleCalendarSyncService::class);
        $mockService->expects($this->once())
            ->method('syncFromGoogleCalendar')
            ->willReturn([
                'processed_events' => 5,
                'deleted_events' => 1,
                'uncategorized_events' => 2,
                'start_date' => '2026-01-01',
                'end_date' => '2028-01-01',
            ]);

        $this->app->instance(GoogleCalendarSyncService::class, $mockService);

        $this->actingAs($this->adminUser);
        $response = $this->post('/admin/calendar/sync');

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $successMessage = session('success');
        $this->assertStringContainsString('Processed: 5', $successMessage);
        $this->assertStringContainsString('Deleted: 1', $successMessage);
        $this->assertStringContainsString('Uncategorised: 2', $successMessage);
    }
}
