<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Security;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthInputValidationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function login_email_is_limited_to_255_characters(): void
    {
        Livewire::test(Login::class)
            ->set('email', str_repeat('a', 246).'@example.com') // 258 chars total
            ->call('login')
            ->assertHasErrors(['email' => 'max']);
    }

    #[Test]
    public function login_password_is_limited_to_100_characters(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'user@example.com')
            ->set('password', str_repeat('a', 101))
            ->call('login')
            ->assertHasErrors(['password' => 'max']);
    }

    #[Test]
    public function register_email_is_limited_to_255_characters(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', str_repeat('a', 246).'@example.com')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->call('register')
            ->assertHasErrors(['email' => 'max']);
    }

    #[Test]
    public function register_password_is_limited_to_100_characters(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'user@example.com')
            ->set('password', str_repeat('a', 101))
            ->set('password_confirmation', str_repeat('a', 101))
            ->call('register')
            ->assertHasErrors(['password' => 'max']);
    }

    #[Test]
    public function forgot_password_email_is_limited_to_255_characters(): void
    {
        Livewire::test(ForgotPassword::class)
            ->set('email', str_repeat('a', 246).'@example.com')
            ->call('sendResetLink')
            ->assertHasErrors(['email' => 'max']);
    }

    #[Test]
    public function reset_password_email_is_limited_to_255_characters(): void
    {
        Livewire::test(ResetPassword::class, ['token' => 'some-token'])
            ->set('email', str_repeat('a', 246).'@example.com')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->call('resetPassword')
            ->assertHasErrors(['email' => 'max']);
    }

    #[Test]
    public function reset_password_password_is_limited_to_100_characters(): void
    {
        Livewire::test(ResetPassword::class, ['token' => 'some-token'])
            ->set('email', 'user@example.com')
            ->set('password', str_repeat('a', 101))
            ->set('password_confirmation', str_repeat('a', 101))
            ->call('resetPassword')
            ->assertHasErrors(['password' => 'max']);
    }
}
