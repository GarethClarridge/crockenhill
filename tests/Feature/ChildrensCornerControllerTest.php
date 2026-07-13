<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensCornerControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $initialOutputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialOutputBufferLevel = ob_get_level();
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
    public function index_redirects_unauthenticated_guests_when_not_public(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $response = $this->get('/christ/childrens-corner');
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function show_redirects_unauthenticated_guests_when_not_public(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $talk = Sermon::factory()->create([
            'slug' => 'a-talk-for-children',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get('/christ/childrens-corner/a-talk-for-children');
        $response->assertRedirect(route('login'));
    }

    // ── authenticated access ───────────────────────────────────────────────

    #[Test]
    public function authenticated_user_can_access_index(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/christ/childrens-corner');

        $response->assertStatus(200);
    }

    #[Test]
    public function authenticated_user_can_view_a_talk(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $user = User::factory()->create();
        $talk = Sermon::factory()->create([
            'slug' => 'authenticated-talk',
            'title' => 'A Childrens Talk Title',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->actingAs($user)->get('/christ/childrens-corner/authenticated-talk');
        $response->assertStatus(200);
        $response->assertSee('A Childrens Talk Title');
    }

    #[Test]
    public function unverified_user_is_redirected_to_verification_notice(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $user = User::factory()->create(['email_verified_at' => null]);

        $talk = Sermon::factory()->create([
            'slug' => 'unverified-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->actingAs($user)->get('/christ/childrens-corner')->assertRedirect(route('login'));
        $this->actingAs($user)->get('/christ/childrens-corner/unverified-talk')->assertRedirect(route('login'));
    }

    // ── public access when talks are enabled ──────────────────────────────

    #[Test]
    public function index_is_publicly_accessible_when_childrens_talks_are_public(): void
    {
        config(['church.sermons.childrens_talks.public' => true]);

        $response = $this->get('/christ/childrens-corner');
        $response->assertStatus(200);
    }

    #[Test]
    public function index_lists_childrens_talks(): void
    {
        config(['church.sermons.childrens_talks.public' => true]);

        Sermon::factory()->create([
            'title' => 'Noah And The Ark',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get('/christ/childrens-corner');
        $response->assertStatus(200);
        $response->assertSee('Noah And The Ark');
    }

    // ── 404 cases ─────────────────────────────────────────────────────────

    #[Test]
    public function show_returns_404_for_regular_sermon(): void
    {
        config(['church.sermons.childrens_talks.public' => true]);

        $sermon = Sermon::factory()->create([
            'slug' => 'regular-sermon',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/childrens-corner/regular-sermon');
        $response->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_for_nonexistent_slug(): void
    {
        config(['church.sermons.childrens_talks.public' => true]);

        $response = $this->get('/christ/childrens-corner/no-such-talk');
        $response->assertStatus(404);
    }
}
