<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\VerifyEmail;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyEmailRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function resending_verification_email_is_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($user);

        $component = Livewire::test(VerifyEmail::class);

        // First 3 attempts should be fine
        for ($i = 0; $i < 3; $i++) {
            $component->call('resend');
            $this->assertEquals('', $component->get('error'));
        }

        // 4th attempt should be blocked
        $component->call('resend');

        $this->assertNotEmpty($component->get('error'));
        $this->assertStringContainsString('Too many login attempts', $component->get('error'));
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 3);
    }

    #[Test]
    public function verified_users_are_redirected_from_verify_email_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);

        Livewire::test(VerifyEmail::class)
            ->assertRedirect('/church/members');
    }
}
