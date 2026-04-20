<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Security;

use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AntiEnumerationThrottlingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function registration_is_throttled_before_unique_email_validation(): void
    {
        $existingUser = User::factory()->create(['email' => 'duplicate@example.com']);

        $component = Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'newuser@example.com')
            ->set('password', 'StrongPass123!@#Unique')
            ->set('password_confirmation', 'StrongPass123!@#Unique');

        // Make 3 successful registrations (deleting user after each)
        for ($i = 0; $i < 3; $i++) {
            $email = "newuser{$i}@example.com";
            $component->set('email', $email)
                ->call('register')
                ->assertHasNoErrors();
            User::where('email', $email)->delete();
        }

        // 2. The next attempt should be throttled immediately,
        // even if we use the duplicate email.
        $component->set('email', 'duplicate@example.com')
            ->call('register');

        $error = $component->get('error');
        $this->assertStringContainsString('Too many login attempts', $error);

        // IMPORTANT: It should NOT have the 'unique' validation error
        // because validation should not have run yet.
        $component->assertHasNoErrors(['email' => 'unique']);
    }
}
