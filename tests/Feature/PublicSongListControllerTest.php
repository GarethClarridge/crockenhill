<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongListControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $initialOutputBufferLevel;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialOutputBufferLevel = ob_get_level();
        config(['service-tracking.enabled' => true]);
        $this->user = User::factory()->create();

        // Clear songs so ordering/pagination is deterministic
        Song::query()->delete();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialOutputBufferLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    // ── auth guard ────────────────────────────────────────────────────────

    #[Test]
    public function index_redirects_guests_to_login(): void
    {
        $response = $this->get('/church/songs');
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function show_redirects_guests_to_login(): void
    {
        $song = Song::factory()->create();

        $response = $this->get("/church/songs/{$song->slug}");
        $response->assertRedirect(route('login'));
    }

    // ── index ──────────────────────────────────────────────────────────────

    #[Test]
    public function index_returns_200_when_enabled(): void
    {
        $response = $this->actingAs($this->user)->get('/church/songs');
        $response->assertStatus(200);
    }

    #[Test]
    public function index_returns_404_when_feature_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $response = $this->actingAs($this->user)->get('/church/songs');
        $response->assertStatus(404);
    }

    #[Test]
    public function index_renders_song_heading(): void
    {
        $response = $this->actingAs($this->user)->get('/church/songs');
        $response->assertStatus(200);
        $response->assertSee('Songs');
    }

    // ── show ───────────────────────────────────────────────────────────────

    #[Test]
    public function show_returns_200_for_existing_song(): void
    {
        $song = Song::factory()->create(['title' => 'How Great Thou Art']);

        $response = $this->actingAs($this->user)->get("/church/songs/{$song->slug}");
        $response->assertStatus(200);
        $response->assertSee('How Great Thou Art');
    }

    #[Test]
    public function show_returns_404_for_nonexistent_song(): void
    {
        $response = $this->actingAs($this->user)->get('/church/songs/no-such-song');
        $response->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_when_feature_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->get("/church/songs/{$song->slug}");
        $response->assertStatus(404);
    }
}
