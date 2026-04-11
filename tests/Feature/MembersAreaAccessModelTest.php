<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Enums\SermonContentType;
use App\Livewire\Auth\Register as RegisterComponent;
use App\Models\Page;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembersAreaAccessModelTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function self_registered_unverified_user_can_access_account_only_surfaces(): void
    {
        Notification::fake();
        config()->set('sermons.childrens_talks.public', false);

        $song = Song::factory()->create([
            'title' => 'Members Song',
            'slug' => 'members-song',
        ]);

        $talk = Sermon::factory()->create([
            'title' => 'Members Talk',
            'slug' => 'members-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $membersPage = Page::factory()->create([
            'area' => PageArea::Members,
            'slug' => 'members-only-page',
            'admin' => 'no',
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

        $this->get(route('memberHome'))->assertOk();
        $this->get(route('church.songs.index'))->assertOk();
        $this->get(route('church.songs.show', $song))->assertOk();
        $this->get(route('pages.showPublic', ['area' => 'members', 'slug' => $membersPage->slug]))->assertOk();
        $this->get(route('childrens-corner.index'))->assertOk();
        $this->get(route('childrens-corner.show', $talk))->assertOk();
    }

    #[Test]
    public function guests_cannot_access_account_only_surfaces_when_childrens_corner_is_private(): void
    {
        config()->set('sermons.childrens_talks.public', false);

        $song = Song::factory()->create([
            'title' => 'Guest Blocked Song',
            'slug' => 'guest-blocked-song',
        ]);

        $talk = Sermon::factory()->create([
            'title' => 'Guest Blocked Talk',
            'slug' => 'guest-blocked-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $membersPage = Page::factory()->create([
            'area' => PageArea::Members,
            'slug' => 'guest-blocked-members-page',
            'admin' => 'no',
        ]);

        $this->get(route('memberHome'))->assertRedirect('/login');
        $this->get(route('church.songs.index'))->assertRedirect('/login');
        $this->get(route('church.songs.show', $song))->assertRedirect('/login');
        $this->get(route('pages.showPublic', ['area' => 'members', 'slug' => $membersPage->slug]))->assertRedirect('/login');
        $this->get(route('childrens-corner.index'))->assertRedirect('/login');
        $this->get(route('childrens-corner.show', $talk))->assertRedirect('/login');
    }
}
