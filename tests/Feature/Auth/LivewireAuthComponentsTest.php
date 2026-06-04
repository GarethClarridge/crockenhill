<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Register as RegisterComponent;
use App\Livewire\Auth\VerifyEmail as VerifyEmailComponent;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivewireAuthComponentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function register_component_creates_user_and_redirects_to_verification_notice(): void
    {
        Notification::fake();

        Livewire::test(RegisterComponent::class)
            ->set('name', 'Livewire User')
            ->set('email', 'livewire-user@example.com')
            ->set('password', 'StrongPass123!@#Unique')
            ->set('password_confirmation', 'StrongPass123!@#Unique')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'livewire-user@example.com')->first();

        $this->assertInstanceOf(User::class, $user);

        if (! $user instanceof User) {
            return;
        }

        $this->assertSame('Livewire User', $user->name);
        $this->assertTrue(Hash::check('StrongPass123!@#Unique', $user->password));
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    #[Test]
    public function verify_email_component_resend_sends_notification_for_authenticated_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(VerifyEmailComponent::class)
            ->call('resend')
            ->assertSet('resent', true);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    #[Test]
    public function verify_email_component_resend_does_not_send_notification_for_guest_user(): void
    {
        Notification::fake();

        Livewire::test(VerifyEmailComponent::class)
            ->call('resend')
            ->assertSet('resent', false);

        Notification::assertNothingSent();
    }
}
