<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\ForgotPassword as ForgotPasswordComponent;
use App\Livewire\Auth\ResetPassword as ResetPasswordComponent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function forgot_password_response_is_uniform_for_known_and_unknown_email_addresses(): void
    {
        Notification::fake();

        $knownUser = User::factory()->create();
        $expectedStatus = __(Password::RESET_LINK_SENT);

        Livewire::test(ForgotPasswordComponent::class)
            ->set('email', $knownUser->email)
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('status', $expectedStatus)
            ->assertSet('error', '');

        Livewire::test(ForgotPasswordComponent::class)
            ->set('email', 'unknown@example.com')
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('status', $expectedStatus)
            ->assertSet('error', '');

        Notification::assertSentTo($knownUser, ResetPasswordNotification::class);
    }

    #[Test]
    public function reset_password_component_does_not_validate_email_existence(): void
    {
        Livewire::test(ResetPasswordComponent::class, ['token' => 'invalid-token'])
            ->set('email', 'unknown@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('resetPassword')
            ->assertHasNoErrors('email');
    }
}
