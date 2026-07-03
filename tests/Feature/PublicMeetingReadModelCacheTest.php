<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\PublicMeetingReadModel;
use App\Enums\CalendarEventStatus;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\PublicMeetingReadModelCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        DB::enableQueryLog();

        // First request: should hit the database for the read model
        $this->get('/community/cached-meeting')->assertOk();
        $queriesAfterFirstCall = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queriesAfterFirstCall);

        // Second request: read model should be cached.
        // Page and past events are still fetched fresh, but the read model (upcoming events etc) is cached.
        $this->get('/community/cached-meeting')->assertOk();
        $queriesAfterSecondCall = count(DB::getQueryLog());
        $this->assertGreaterThan($queriesAfterFirstCall, $queriesAfterSecondCall);

        CalendarEvent::factory()->forMeeting($meeting)->upcoming()->create([
            'title' => 'Fresh event',
        ]);

        // Third request: read model should be invalidated and re-fetched
        $this->get('/community/cached-meeting')
            ->assertOk()
            ->assertSee('Fresh event');

        $queriesAfterThirdCall = count(DB::getQueryLog());
        $this->assertGreaterThan($queriesAfterSecondCall, $queriesAfterThirdCall);
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

    #[Test]
    public function page_less_meeting_read_model_survives_allow_listed_unserialization(): void
    {
        // Mirrors the live /community/sunday-mornings record: a meeting with no linked
        // page and no photos. Production caches (Redis) serialise the read model and
        // rehydrate it under the cache.serializable_classes allow-list, a path the
        // array driver used in tests skips by default.
        $meeting = Meeting::factory()->create([
            'slug' => 'sunday-mornings',
            'page_id' => null,
        ]);

        $this->assertReadModelSurvivesAllowListedUnserialization($meeting);
    }

    #[Test]
    public function meeting_read_model_with_page_and_events_survives_allow_listed_unserialization(): void
    {
        // The richer graph: a linked Page (with PageArea enum + Carbon timestamps) and
        // an upcoming CalendarEvent (with CalendarEventStatus enum). Every nested class
        // must appear in cache.serializable_classes or it rehydrates as an incomplete
        // class and the public page 500s on the next cache read.
        $page = Page::factory()->create([
            'slug' => 'serialised-meeting-page',
            'area' => 'community',
            'heading' => 'Serialised Meeting Page',
            'admin' => 'no',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'serialised-meeting',
            'page_id' => $page->id,
        ]);

        CalendarEvent::factory()->forMeeting($meeting)->upcoming()->create([
            'title' => 'Upcoming gathering',
        ]);

        $this->assertReadModelSurvivesAllowListedUnserialization($meeting);
    }

    /**
     * Assert the cached read model round-trips through PHP serialization under the
     * production cache.serializable_classes allow-list without any node degrading to
     * __PHP_Incomplete_Class.
     *
     * Detection uses print_r() rather than re-serialising: re-serialising an incomplete
     * object re-emits its original class name, so serialize() can never reveal the
     * failure, whereas print_r() renders incomplete nodes literally as
     * "__PHP_Incomplete_Class" and recurses through nested objects, arrays, and
     * collections.
     */
    private function assertReadModelSurvivesAllowListedUnserialization(Meeting $meeting): void
    {
        $readModel = app(PublicMeetingReadModelCache::class)->get($meeting);

        $restored = unserialize(
            serialize($readModel),
            ['allowed_classes' => config('cache.serializable_classes')],
        );

        $this->assertInstanceOf(PublicMeetingReadModel::class, $restored);
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', print_r($restored, true));
    }
}
