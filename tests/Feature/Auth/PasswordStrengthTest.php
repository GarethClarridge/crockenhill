<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordStrengthTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function registration_fails_with_weak_password(): void
    {
        Livewire::test(\App\Livewire\Auth\Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'weak')
            ->set('password_confirmation', 'weak')
            ->call('register')
            ->assertHasErrors(['password']);

        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    #[Test]
    public function registration_fails_with_password_lacking_numbers(): void
    {
        Livewire::test(\App\Livewire\Auth\Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'passwordwithoutnumbers')
            ->set('password_confirmation', 'passwordwithoutnumbers')
            ->call('register')
            ->assertHasErrors(['password']);
    }

    #[Test]
    public function registration_succeeds_with_strong_password(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        Livewire::test(\App\Livewire\Auth\Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'StrongPass123!@#Unique')
            ->set('password_confirmation', 'StrongPass123!@#Unique')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }

    #[Test]
    public function password_reset_fails_with_weak_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        Livewire::test(\App\Livewire\Auth\ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'weak')
            ->set('password_confirmation', 'weak')
            ->call('resetPassword')
            ->assertHasErrors(['password']);
    }
}
