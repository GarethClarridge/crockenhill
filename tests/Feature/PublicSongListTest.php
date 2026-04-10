<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongListTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('church.songs.index'))
            ->assertRedirect('/login');
    }

    #[Test]
    public function feature_returns_not_found_when_service_tracking_is_disabled(): void
    {
        config()->set('service-tracking.enabled', false);

        $this->actingAs(User::factory()->create());

        $this->get(route('church.songs.index'))
            ->assertNotFound();
    }

    #[Test]
    public function authenticated_users_can_see_the_songs_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('church.songs.index'))
            ->assertOk();
    }

    #[Test]
    public function header_navigation_shows_songs_link_to_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/church')
            ->assertOk()
            ->assertSee('href="'.route('church.songs.index').'"', false);
    }

    #[Test]
    public function header_navigation_hides_songs_link_from_guests(): void
    {
        $this->get('/church')
            ->assertOk()
            ->assertDontSee('href="'.route('church.songs.index').'"', false);
    }
}
