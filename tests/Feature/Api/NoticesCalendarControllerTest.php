<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CalendarEventStatus;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NoticesCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00'));
        Meeting::factory()->create(['slug' => 'coffee-cup']);
        Meeting::factory()->create(['slug' => 'baby-talk']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_a_script_that_other_origins_may_embed(): void
    {
        $response = $this->get('/api/notices/calendar');

        $response->assertOk();
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        // Only the resource policy is relaxed; the other security headers still apply.
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('max-age=900', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('window.CBC_CALENDAR = {', $response->getContent());
    }

    #[Test]
    public function it_lists_confirmed_upcoming_dates_by_meeting_in_wall_clock_time(): void
    {
        $this->event('coffee-cup', '2026-11-26 11:00', '2026-11-26 12:00');
        $this->event('coffee-cup', '2026-11-19 11:00', '2026-11-19 12:00');
        $this->event('baby-talk', '2026-11-18 09:30', '2026-11-18 11:30');

        $payload = $this->payload($this->get('/api/notices/calendar'));

        $this->assertSame([
            ['start' => '2026-11-19T11:00', 'end' => '2026-11-19T12:00'],
            ['start' => '2026-11-26T11:00', 'end' => '2026-11-26T12:00'],
        ], $payload['meetings']['coffee-cup']);
        $this->assertSame([
            ['start' => '2026-11-18T09:30', 'end' => '2026-11-18T11:30'],
        ], $payload['meetings']['baby-talk']);
        $this->assertSame('2026-11-17T22:00:00+00:00', $payload['generated']);
    }

    #[Test]
    public function it_keeps_events_from_earlier_today_and_drops_earlier_days(): void
    {
        $this->event('coffee-cup', '2026-11-17 11:00', '2026-11-17 12:00');
        $this->event('coffee-cup', '2026-11-16 11:00', '2026-11-16 12:00');

        $payload = $this->payload($this->get('/api/notices/calendar'));

        $this->assertSame(['2026-11-17T11:00'], array_column($payload['meetings']['coffee-cup'], 'start'));
    }

    #[Test]
    public function it_leaves_out_unconfirmed_uncategorised_and_far_future_events(): void
    {
        $this->event('coffee-cup', '2026-11-19 11:00', '2026-11-19 12:00', CalendarEventStatus::Cancelled);
        $this->event('coffee-cup', '2026-11-20 11:00', '2026-11-20 12:00', CalendarEventStatus::Tentative);
        $this->event(null, '2026-11-21 11:00', '2026-11-21 12:00');
        $this->event('baby-talk', '2027-06-01 09:30', '2027-06-01 11:30');

        $payload = $this->payload($this->get('/api/notices/calendar'));

        $this->assertSame([], $payload['meetings']);
    }

    #[Test]
    public function an_empty_calendar_is_still_an_object_of_meetings(): void
    {
        $this->assertStringContainsString('"meetings":{}', $this->get('/api/notices/calendar')->getContent());
    }

    private function event(?string $meetingSlug, string $start, string $end, CalendarEventStatus $status = CalendarEventStatus::Confirmed): void
    {
        CalendarEvent::factory()->create([
            'meeting_slug' => $meetingSlug,
            'start_datetime' => Carbon::parse($start),
            'end_datetime' => Carbon::parse($end),
            'status' => $status,
        ]);
    }

    /**
     * @return array{generated: string, meetings: array<string, list<array{start: string, end: string}>>}
     */
    private function payload(TestResponse $response): array
    {
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/^window\.CBC_CALENDAR = \{.*\};\n$/s', $content);

        /** @var array{generated: string, meetings: array<string, list<array{start: string, end: string}>>} $payload */
        $payload = json_decode(substr($content, strlen('window.CBC_CALENDAR = '), -2), true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }
}
