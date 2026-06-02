<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use DatabaseTransactions;

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
}
