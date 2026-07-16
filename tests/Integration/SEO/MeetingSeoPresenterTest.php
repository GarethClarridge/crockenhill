<?php

declare(strict_types=1);

namespace Tests\Integration\SEO;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Seo\MeetingSeoPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingSeoPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_null_for_an_empty_event_collection(): void
    {
        $result = app(MeetingSeoPresenter::class)->eventItemList(
            meeting: Meeting::factory()->create(),
            events: collect(),
            descriptionFallback: 'Meeting description',
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_presents_events_as_a_schema_item_list(): void
    {
        $meeting = Meeting::factory()->create(['location' => null]);
        $event = CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Sunday gathering',
            'description' => null,
            'location' => null,
            'start_datetime' => now()->addDay()->startOfHour(),
            'end_datetime' => now()->addDay()->startOfHour()->addHour(),
        ]);

        $result = app(MeetingSeoPresenter::class)->eventItemList(
            meeting: $meeting,
            events: collect([$event]),
            descriptionFallback: 'Meeting description',
            image: 'https://example.com/meeting.webp',
        );

        $this->assertSame('https://schema.org', $result['@context']);
        $this->assertSame('ItemList', $result['@type']);
        $this->assertSame('ListItem', $result['itemListElement'][0]['@type']);
        $this->assertSame('Sunday gathering', $result['itemListElement'][0]['item']['name']);
        $this->assertSame('Meeting description', $result['itemListElement'][0]['item']['description']);
        $this->assertSame(config('church.name'), $result['itemListElement'][0]['item']['location']['name']);
        $this->assertSame('https://example.com/meeting.webp', $result['itemListElement'][0]['item']['image']);
    }
}
