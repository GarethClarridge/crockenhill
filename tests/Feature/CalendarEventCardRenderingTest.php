<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventCardRenderingTest extends TestCase
{
    use DatabaseTransactions;

    private CalendarEvent $event;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meeting = Meeting::factory()->create(['slug' => 'sunday-service']);
        $this->event = CalendarEvent::factory()->upcoming()->create([
            'title' => 'Sunday Morning Service',
            'description' => 'A wonderful time of worship and teaching.',
            'location' => 'Church Hall',
            'speaker' => 'Rev. John Smith',
            'meeting_slug' => 'sunday-service',
        ]);
    }

    #[Test]
    public function default_variant_renders_event_title_and_card_wrapper(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Sunday Morning Service', $rendered);
        $this->assertStringContainsString('rounded-lg shadow', $rendered);
    }

    #[Test]
    public function compact_variant_renders_inline_meta_row_without_card_chrome(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="compact" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Sunday Morning Service', $rendered);
        $this->assertStringContainsString('bg-gray-50', $rendered);
        // Uses the shared meta partial - description should appear
        $this->assertStringContainsString('A wonderful time', $rendered);
    }

    #[Test]
    public function list_variant_renders_without_outer_card_wrapper(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="list" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Sunday Morning Service', $rendered);
        // list variant has no wrapping div with card classes
        $this->assertStringNotContainsString('rounded-lg shadow', $rendered);
    }

    #[Test]
    public function admin_variant_renders_with_wider_card_class(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="admin" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Sunday Morning Service', $rendered);
        $this->assertStringContainsString('max-w-full', $rendered);
    }

    #[Test]
    public function location_and_speaker_appear_in_compact_variant(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="compact" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Church Hall', $rendered);
        $this->assertStringContainsString('Rev. John Smith', $rendered);
    }

    #[Test]
    public function location_and_speaker_appear_in_list_variant(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="list" />',
            ['event' => $this->event],
        );

        $this->assertStringContainsString('Church Hall', $rendered);
        $this->assertStringContainsString('Rev. John Smith', $rendered);
    }

    #[Test]
    public function meeting_badge_links_to_community_page_in_compact_variant(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" :meeting="$meeting" variant="compact" />',
            ['event' => $this->event, 'meeting' => $this->meeting],
        );

        $this->assertStringContainsString('/community/sunday-service', $rendered);
    }

    #[Test]
    public function meeting_badge_links_to_community_page_in_list_variant(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" :meeting="$meeting" variant="list" />',
            ['event' => $this->event, 'meeting' => $this->meeting],
        );

        $this->assertStringContainsString('/community/sunday-service', $rendered);
    }

    #[Test]
    public function uncategorized_event_shows_warning_badge_in_compact_variant(): void
    {
        $uncategorized = CalendarEvent::factory()->upcoming()->create([
            'title' => 'Mystery Event',
            'meeting_slug' => null,
        ]);

        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="compact" />',
            ['event' => $uncategorized],
        );

        $this->assertStringContainsString('Uncategorized', $rendered);
        $this->assertStringContainsString('bg-yellow-100', $rendered);
    }

    #[Test]
    public function description_is_hidden_when_show_description_is_false(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="compact" :showDescription="false" />',
            ['event' => $this->event],
        );

        $this->assertStringNotContainsString('A wonderful time', $rendered);
    }

    #[Test]
    public function date_is_hidden_when_show_date_is_false(): void
    {
        $rendered = Blade::render(
            '<x-calendar-event-card :event="$event" variant="compact" :showDate="false" />',
            ['event' => $this->event],
        );

        // The formatted date should not appear; verify by checking the calendar icon is absent from meta row
        // (time icon is always shown, so we check for the specific date string absence)
        $dateString = $this->event->start_datetime->format('M j, Y');
        $this->assertStringNotContainsString($dateString, $rendered);
    }

    // -------------------------------------------------------------------------
    // HTTP regression: calendar pages render event cards
    // -------------------------------------------------------------------------

    #[Test]
    public function calendar_index_renders_event_titles(): void
    {
        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('Sunday Morning Service');
    }
}
