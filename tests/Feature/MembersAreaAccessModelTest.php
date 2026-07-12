<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Livewire\Auth\Register as RegisterComponent;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembersAreaAccessModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function self_registered_unverified_user_cannot_access_members_or_songs_routes(): void
    {
        Notification::fake();
        config()->set('church.sermons.childrens_talks.public', false);

        $song = Song::factory()->create([
            'title' => 'Members Song',
            'slug' => 'members-song',
        ]);

        $talk = Sermon::factory()->create([
            'title' => 'Members Talk',
            'slug' => 'members-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        Livewire::test(RegisterComponent::class)
            ->set('name', 'New Member Account')
            ->set('email', 'new-member@example.com')
            ->set('password', 'StrongPass123!@#Unique')
            ->set('password_confirmation', 'StrongPass123!@#Unique')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'new-member@example.com')->first();

        $this->assertInstanceOf(User::class, $user);

        if (! $user instanceof User) {
            return;
        }

        $this->assertFalse($user->is_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->get(route('members.home'))->assertRedirect(route('verification.notice'));
        $this->get(route('church.songs.index'))->assertRedirect(route('verification.notice'));
        $this->get(route('church.songs.show', $song))->assertRedirect(route('verification.notice'));
        $this->get(route('childrens-corner.index'))->assertRedirect(route('login'));
        $this->get(route('childrens-corner.show', $talk))->assertRedirect(route('login'));
    }

    #[Test]
    public function verified_member_can_access_members_and_songs_routes(): void
    {
        $song = Song::factory()->create([
            'title' => 'Verified Member Song',
            'slug' => 'verified-member-song',
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin' => false,
        ]);

        $this->actingAs($user);

        $this->get(route('members.home'))->assertOk();
        $this->get(route('church.songs.index'))->assertOk();
        $this->get(route('church.songs.show', $song))->assertOk();
    }

    #[Test]
    public function unverified_authenticated_user_is_redirected_to_verification_notice(): void
    {
        $song = Song::factory()->create([
            'title' => 'Unverified Block Song',
            'slug' => 'unverified-block-song',
        ]);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_admin' => false,
        ]);

        $this->actingAs($user);

        $this->get(route('members.home'))->assertRedirect(route('verification.notice'));
        $this->get(route('church.songs.index'))->assertRedirect(route('verification.notice'));
        $this->get(route('church.songs.show', $song))->assertRedirect(route('verification.notice'));
    }

    #[Test]
    public function guests_cannot_access_account_only_surfaces_when_childrens_corner_is_private(): void
    {
        config()->set('church.sermons.childrens_talks.public', false);

        $song = Song::factory()->create([
            'title' => 'Guest Blocked Song',
            'slug' => 'guest-blocked-song',
        ]);

        $talk = Sermon::factory()->create([
            'title' => 'Guest Blocked Talk',
            'slug' => 'guest-blocked-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get(route('members.home'))->assertRedirect('/login');
        $this->get(route('church.songs.index'))->assertRedirect('/login');
        $this->get(route('church.songs.show', $song))->assertRedirect('/login');
        $this->get(route('childrens-corner.index'))->assertRedirect('/login');
        $this->get(route('childrens-corner.show', $talk))->assertRedirect('/login');
    }
}
