<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── show (public) ──────────────────────────────────────────────────────

    #[Test]
    public function public_meeting_show_returns_200(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'youth-group',
            'day' => 'Friday',
            'start_time' => '19:00:00',
            'end_time' => '21:00:00',
            'location' => 'Church Hall',
            'who' => 'All ages',
            'type' => 'Adults',
        ]);

        $response = $this->get('/community/youth-group');
        $response->assertStatus(200);
        $response->assertSee('Church Hall');
    }

    #[Test]
    public function show_returns_404_for_nonexistent_meeting(): void
    {
        $response = $this->get('/community/does-not-exist');
        $response->assertStatus(404);
    }

    #[Test]
    public function public_meeting_show_renders_when_cache_serializes_values(): void
    {
        // Production caches (Redis) serialise the read model and rehydrate it under the
        // cache.serializable_classes allow-list. The test array driver skips that by
        // default, hiding 500s caused by an un-allow-listed (or stale, renamed) class in
        // the cached graph. Opt the array store into the same serialise-and-allow-list
        // path so the second (cache-read) request exercises unserialize() like production.
        config(['cache.stores.array.serialize' => true]);
        Cache::purge('array');

        $meeting = Meeting::factory()->create([
            'slug' => 'sunday-mornings',
            'location' => 'Main Hall',
            'who' => 'Everyone welcome',
            'page_id' => null,
        ]);

        // A confirmed upcoming event forces a CalendarEvent (with its enum + Carbon
        // casts) into the cached graph, so the cache-read request rehydrates the full
        // object tree under the allow-list rather than an empty collection.
        CalendarEvent::factory()->forMeeting($meeting)->upcoming()->create([
            'title' => 'Morning Worship',
        ]);

        // First request populates the cache; the second reads it back via unserialize().
        $this->get('/community/sunday-mornings')->assertOk();

        $this->get('/community/sunday-mornings')
            ->assertOk()
            ->assertSee('Main Hall');
    }
}
