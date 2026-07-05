<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function calendar_index_shows_upcoming_confirmed_events(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Upcoming Event',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->subDays(1),
            'end_datetime' => now()->subDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Past Event',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
            'status' => 'tentative',
            'title' => 'Unconfirmed Event',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addMonths(7),
            'end_datetime' => now()->addMonths(7)->addHour(),
            'status' => 'confirmed',
            'title' => 'Far Future Event',
        ]);

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('Upcoming Event');
        $response->assertDontSee('Past Event');
        $response->assertDontSee('Unconfirmed Event');
        $response->assertDontSee('Far Future Event');

        // Check SEO tags
        $response->assertSee('<title>Church Calendar | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Upcoming events at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Church Calendar | Crockenhill Baptist Church">', false);
    }

    #[Test]
    public function events_for_meeting_shows_events_for_that_meeting_not_cancelled(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'specific-meeting']);
        Meeting::factory()->create(['slug' => 'other-meeting']);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Meeting Event 1',
            'status' => 'confirmed',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Tentative Meeting Event',
            'status' => 'tentative',
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Cancelled Event',
            'status' => 'cancelled',
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(3)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Event',
            'status' => 'confirmed',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
        ]);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        $response->assertSee('Meeting Event 1');
        $response->assertDontSee('Tentative Meeting Event');
        $response->assertDontSee('Cancelled Event');
        $response->assertDontSee('Other Meeting Event');
    }

    #[Test]
    public function events_for_meeting_renders_upcoming_events_and_only_the_recent_past_slice(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 12, 12, 0, 0));

        $meeting = Meeting::factory()->create(['slug' => 'specific-meeting']);
        Meeting::factory()->create(['slug' => 'other-meeting']);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Upcoming Meeting Event',
            'status' => 'confirmed',
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
        ]);

        for ($day = 1; $day <= 21; $day++) {
            CalendarEvent::factory()->create([
                'meeting_slug' => 'specific-meeting',
                'title' => "Past Meeting Event {$day}",
                'status' => 'confirmed',
                'start_datetime' => now()->subDays($day),
                'end_datetime' => now()->subDays($day)->addHour(),
            ]);
        }

        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Past Event',
            'status' => 'confirmed',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->subDay()->addHour(),
        ]);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        $response->assertSee('Upcoming Meeting Event');
        $response->assertSee('Past Meeting Event 1');
        $response->assertSee('Past Meeting Event 20');
        $response->assertDontSee('Past Meeting Event 21');
        $response->assertDontSee('Other Meeting Past Event');
        $response->assertSee('Showing 20 most recent past meetings');
    }

    #[Test]
    public function events_for_meeting_json_ld_only_offers_events_that_have_not_finished(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 12, 12, 0, 0));

        $meeting = Meeting::factory()->create(['slug' => 'offer-gating-meeting']);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'offer-gating-meeting',
            'title' => 'Future Meeting Event',
            'status' => 'confirmed',
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'offer-gating-meeting',
            'title' => 'Finished Meeting Event',
            'status' => 'confirmed',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->subDay()->addHour(),
        ]);

        $response = $this->get(route('meetings.events', $meeting));
        $response->assertStatus(200);

        $items = collect($this->extractItemListStructuredData($response->getContent())['itemListElement'])
            ->keyBy(fn (array $element): string => $element['item']['name']);

        $this->assertArrayHasKey('offers', $items['Future Meeting Event']['item']);
        $this->assertSame(
            'https://schema.org/InStock',
            $items['Future Meeting Event']['item']['offers']['availability'],
        );

        $this->assertArrayNotHasKey('offers', $items['Finished Meeting Event']['item']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractItemListStructuredData(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (($decoded['@type'] ?? null) === 'ItemList') {
                return $decoded;
            }
        }

        $this->fail('No ItemList JSON-LD block found in the response.');
    }

    #[Test]
    public function events_for_meeting_shows_seo_metadata_with_display_name(): void
    {
        // Without a related Page, the heading accessor falls back to Str::title(slug)
        $meeting = Meeting::factory()->create(['slug' => 'bible-study']);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        // Str::title(str_replace('-', ' ', 'bible-study')) = 'Bible Study'
        $response->assertSee('Bible Study - All Events', false);
        $response->assertSee('View all upcoming and past calendar events for Bible Study at Crockenhill Baptist Church.', false);
    }

    #[Test]
    public function uncategorized_calendar_shows_upcoming_uncategorized_events(): void
    {
        Meeting::factory()->create(['slug' => 'some-meeting']);

        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Uncategorised Upcoming',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->subDays(1),
            'end_datetime' => now()->subDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Uncategorised Past',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
            'status' => 'tentative',
            'title' => 'Uncategorised Tentative',
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'some-meeting',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Categorised Upcoming',
        ]);

        $response = $this->get(route('calendar.uncategorized'));

        $response->assertStatus(200);
        $response->assertSee('Uncategorised Upcoming');
        $response->assertDontSee('Uncategorised Past');
        $response->assertDontSee('Uncategorised Tentative');
        $response->assertDontSee('Categorised Upcoming');
    }

    #[Test]
    public function calendar_index_handles_no_events_gracefully(): void
    {
        CalendarEvent::query()->delete();

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('No upcoming events');
    }
}
