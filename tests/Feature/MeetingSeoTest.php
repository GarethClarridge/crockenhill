<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MeetingFrequency;
use App\Enums\PageArea;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function meeting_page_contains_expected_seo_metadata()
    {
        $page = Page::factory()->create([
            'heading' => 'Buzz Club',
            'slug' => 'buzz-club',
            'description' => 'A fun club for kids.',
            'area' => PageArea::Community,
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'buzz-club',
            'day' => 'Friday',
            'start_time' => '18:00:00',
            'end_time' => '20:00:00',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
        ]);

        $response = $this->get('/community/buzz-club');

        $response->assertStatus(200);

        // Assert parts of the title
        $response->assertSee('Buzz Club', false);
        $response->assertSee('Friday', false);
        $response->assertSee('6:00pm', false);

        $response->assertSee('A fun club for kids.', false);
    }

    #[Test]
    public function meeting_page_contains_breadcrumb_json_ld()
    {
        $page = Page::factory()->create([
            'heading' => 'Test Meeting',
            'slug' => 'test-meeting',
            'area' => PageArea::Community,
        ]);

        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'test-meeting',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $response = $this->get('/community/test-meeting');

        $response->assertStatus(200);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Home"', false);
        $response->assertSee('"name": "Community"', false);
        $response->assertSee('"name": "Test Meeting"', false);

        // Verify the JSON-LD structure reflects the community area correctly
        $json = $response->getContent();
        $this->assertStringContainsString('"name": "Community"', $json);
        $this->assertStringContainsString('"item": "http://localhost/community"', $json);
    }

    #[Test]
    public function meeting_page_contains_event_json_ld_when_events_present()
    {
        $page = Page::factory()->create([
            'heading' => 'Test Meeting',
            'slug' => 'test-meeting',
            'area' => PageArea::Community,
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'test-meeting',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
        ]);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Upcoming Test Event',
            'description' => 'This is an upcoming event.',
            'start_datetime' => now()->addDays(1)->setTime(10, 0, 0),
            'status' => 'confirmed',
        ]);

        $response = $this->get('/community/test-meeting');

        $response->assertStatus(200);

        $response->assertSee('"@type": "Event"', false);
    }

    #[Test]
    public function meeting_page_contains_recurring_event_json_ld()
    {
        $page = Page::factory()->create([
            'heading' => 'Buzz Club',
            'slug' => 'buzz-club',
            'description' => 'A fun club for kids.',
            'area' => PageArea::Community,
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'buzz-club',
            'day' => 'Friday',
            'start_time' => '18:00:00',
            'end_time' => '19:30:00',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
        ]);

        $response = $this->get('/community/buzz-club');

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('"@type": "Event"', $content);
        $this->assertStringContainsString('"name": "Buzz Club"', $content);
        $this->assertStringContainsString('"@type": "Schedule"', $content);
        $this->assertStringContainsString('"repeatFrequency": "P1W"', $content);
        $this->assertStringContainsString('"byDay": "https://schema.org/Friday"', $content);
        $this->assertStringContainsString('"startTime": "18:00:00"', $content);
        $this->assertStringContainsString('"endTime": "19:30:00"', $content);
    }

    #[Test]
    public function meeting_page_does_not_contain_recurring_event_json_ld_for_non_recurring_meetings()
    {
        $page = Page::factory()->create([
            'heading' => 'One-off Event',
            'slug' => 'one-off',
            'area' => PageArea::Community,
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'one-off',
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $response = $this->get('/community/one-off');

        $response->assertStatus(200);

        $content = $response->getContent();
        // The recurring event block should not be present
        $this->assertStringNotContainsString('"@type": "Schedule"', $content);
    }

    #[Test]
    public function meeting_json_ld_includes_church_geo_coordinates_when_onsite()
    {
        $page = Page::factory()->create([
            'heading' => 'Onsite Meeting',
            'slug' => 'onsite-meeting',
            'area' => PageArea::Community,
        ]);

        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'onsite-meeting',
            'location' => null,
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
        ]);

        $response = $this->get('/community/onsite-meeting');

        $response->assertStatus(200);
        $event = $this->extractEventJsonLd($response->getContent());
        $this->assertSame(config('church.name'), $event['location']['name']);
        $this->assertArrayHasKey('geo', $event['location']);
        $this->assertSame(config('church.address.street'), $event['location']['address']['streetAddress']);
    }

    #[Test]
    public function meeting_json_ld_omits_church_geo_coordinates_for_offsite_location()
    {
        $page = Page::factory()->create([
            'heading' => 'Offsite Meeting',
            'slug' => 'offsite-meeting',
            'area' => PageArea::Community,
        ]);

        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'offsite-meeting',
            'location' => 'Village Hall',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
        ]);

        $response = $this->get('/community/offsite-meeting');

        $response->assertStatus(200);
        $event = $this->extractEventJsonLd($response->getContent());
        $this->assertSame('Village Hall', $event['location']['name']);
        $this->assertArrayNotHasKey('geo', $event['location']);
        $this->assertArrayNotHasKey('address', $event['location']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEventJsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $block) {
            $decoded = json_decode($block, true);
            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'Event') {
                return $decoded;
            }
        }

        $this->fail('No Event JSON-LD block found in response.');
    }
}
