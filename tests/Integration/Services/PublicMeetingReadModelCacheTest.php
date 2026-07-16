<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\PublicMeetingReadModel;
use App\Data\PublicPageReadModel;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use App\Presenters\MeetingShowPresenter;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class PublicMeetingReadModelCacheTest extends TestCase
{
    use RefreshDatabase;

    private PublicMeetingReadModelCache $service;

    /** @var MockObject&MeetingShowPresenter */
    private MeetingShowPresenter $meetingShowPresenter;

    /** @var MockObject&PublicPageReadModelCache */
    private PublicPageReadModelCache $publicPageReadModelCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetingShowPresenter = $this->createMock(MeetingShowPresenter::class);
        $this->publicPageReadModelCache = $this->createMock(PublicPageReadModelCache::class);

        $this->service = new PublicMeetingReadModelCache(
            $this->meetingShowPresenter,
            $this->publicPageReadModelCache
        );
    }

    #[Test]
    public function it_assembles_read_model_without_page(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting-slug',
            'page_id' => null,
        ]);

        $this->meetingShowPresenter->method('photos')->willReturn(collect());

        $result = $this->service->get($meeting);

        $this->assertInstanceOf(PublicMeetingReadModel::class, $result);
        $this->assertEquals('community', $result->area);
        $this->assertEquals('Test Meeting Slug', $result->heading); // Fallback heading
        $this->assertEquals('', $result->content);
        $this->assertEquals('Test Meeting Slug', $result->description);
        $this->assertEquals('Test Meeting Slug', $result->metaDescription);
        $this->assertNull($result->headingpicture);
        $this->assertEquals('test-meeting-slug', $result->slug);
    }

    #[Test]
    public function it_assembles_read_model_with_page(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Page Heading',
            'description' => 'Page Description',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'meeting-slug',
            'page_id' => $page->id,
        ]);

        $pageReadModel = new PublicPageReadModel(
            area: 'church',
            content: '<p>Page Content</p>',
            description: 'Page Description',
            heading: 'Page Heading',
            headingpicture: 'https://example.com/image.jpg',
            headingpictureMobile: 'https://example.com/mobile.jpg',
            headingpictureTablet: 'https://example.com/tablet.jpg',
            metaDescription: 'Page Meta Description',
            slug: $page->slug,
        );

        $this->publicPageReadModelCache->expects($this->once())->method('get')->with($this->callback(fn ($p) => $p->id === $page->id))->willReturn($pageReadModel);
        $this->meetingShowPresenter->method('photos')->willReturn(collect());

        $result = $this->service->get($meeting);

        $this->assertInstanceOf(PublicMeetingReadModel::class, $result);
        $this->assertEquals('community', $result->area); // Fixed in service
        $this->assertEquals('Page Heading', $result->heading);
        $this->assertEquals('<p>Page Content</p>', $result->content);
        $this->assertEquals('Page Description', $result->description);
        $this->assertEquals('Page Meta Description', $result->metaDescription);
        $this->assertEquals('Page Description', $result->pageDescription);
        $this->assertEquals('https://example.com/image.jpg', $result->headingpicture);
        $this->assertEquals('https://example.com/mobile.jpg', $result->headingpictureMobile);
        $this->assertEquals('https://example.com/tablet.jpg', $result->headingpictureTablet);
    }

    #[Test]
    public function it_fetches_and_limits_calendar_events(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'event-meeting']);

        // Create 4 past events (should be limited to 3)
        CalendarEvent::factory()->count(4)->forMeeting($meeting)->past()->create(['status' => 'confirmed']);

        // Create 7 upcoming events (should be limited to 6)
        CalendarEvent::factory()->count(7)->forMeeting($meeting)->upcoming()->create(['status' => 'confirmed']);

        // Create event for different meeting (should not be included)
        // Ensure meeting exists first to satisfy foreign key
        $otherMeeting = Meeting::factory()->create(['slug' => 'other-meeting']);
        CalendarEvent::factory()->upcoming()->create(['meeting_slug' => 'other-meeting']);

        $this->meetingShowPresenter->method('photos')->willReturn(collect());

        $result = $this->service->get($meeting);

        // Past events are fetched fresh per-request via getPastEventsForMeeting(), not stored on the model
        $this->assertCount(6, $result->upcomingEvents);

        // Test the fresh past events method separately
        $pastEvents = $this->service->getPastEventsForMeeting($meeting);
        $this->assertCount(3, $pastEvents);

        // Verify order of past events (descending)
        $this->assertTrue(
            $pastEvents[0]->start_datetime->gt($pastEvents[1]->start_datetime)
        );

        // Verify order of upcoming events (ascending)
        $this->assertTrue(
            $result->upcomingEvents[0]->start_datetime->lt($result->upcomingEvents[1]->start_datetime)
        );
    }

    #[Test]
    public function it_caches_the_result(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'cached-meeting']);

        // Expect exactly one call to the presenter, even if we call the service twice
        $this->meetingShowPresenter->expects($this->once())
            ->method('photos')
            ->willReturn(collect());

        DB::enableQueryLog();

        // First call - should trigger presenter and database loads
        $this->service->get($meeting);
        $queriesAfterFirstCall = count(DB::getQueryLog());

        // Second call - should be served from cache, no second presenter call, no new queries
        $this->service->get($meeting);
        $this->assertCount($queriesAfterFirstCall, DB::getQueryLog());

        DB::disableQueryLog();
    }

    #[Test]
    public function it_refreshes_upcoming_events_after_the_fresh_window_passes(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'time-sensitive-meeting']);
        $event = CalendarEvent::factory()->forMeeting($meeting)->create([
            'title' => 'Soon-finished event',
            'start_datetime' => now()->addMinute(),
            'end_datetime' => now()->addHours(2),
            'status' => 'confirmed',
        ]);

        $this->meetingShowPresenter->method('photos')->willReturn(collect());

        $initial = $this->service->get($meeting);
        $this->assertTrue($initial->upcomingEvents->contains($event));

        $this->travel(301)->seconds();

        $stale = $this->service->get($meeting);
        $this->assertTrue($stale->upcomingEvents->contains($event));

        \Illuminate\Support\defer()->invoke();

        $refreshed = $this->service->get($meeting);
        $this->assertFalse($refreshed->upcomingEvents->contains($event));
    }

    #[Test]
    public function it_can_forget_by_id(): void
    {
        $meeting = Meeting::factory()->create(['id' => 123]);

        // Expect exactly two calls: one for initial cache, one after forget()
        $this->meetingShowPresenter->expects($this->exactly(2))
            ->method('photos')
            ->willReturn(collect());

        // 1. Initial get (populates cache)
        $this->service->get($meeting);

        // 2. Forget
        $this->service->forget(123);

        // 3. Second get (must re-trigger presenter and queries)
        DB::enableQueryLog();
        $this->service->get($meeting);
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function it_can_forget_by_model(): void
    {
        $meeting = Meeting::factory()->create(['id' => 456]);

        $this->meetingShowPresenter->expects($this->exactly(2))
            ->method('photos')
            ->willReturn(collect());

        // 1. Initial get
        $this->service->get($meeting);

        // 2. Forget
        $this->service->forget($meeting);

        // 3. Second get
        DB::enableQueryLog();
        $this->service->get($meeting);
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function it_can_forget_by_slug(): void
    {
        $meeting = Meeting::factory()->create(['id' => 789, 'slug' => 'forget-me']);

        $this->meetingShowPresenter->expects($this->exactly(2))
            ->method('photos')
            ->willReturn(collect());

        // 1. Initial get
        $this->service->get($meeting);

        // 2. Forget
        $this->service->forgetBySlug('forget-me');

        // 3. Second get
        DB::enableQueryLog();
        $this->service->get($meeting);
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function forget_by_slug_does_nothing_for_invalid_slug(): void
    {
        // An empty or null slug must be a no-op rather than crash.
        $this->expectNotToPerformAssertions();

        $this->service->forgetBySlug('');
        $this->service->forgetBySlug(null);
    }
}
