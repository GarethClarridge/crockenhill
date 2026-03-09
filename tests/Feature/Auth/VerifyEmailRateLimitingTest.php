<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\VerifyEmail;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyEmailRateLimitingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function resend_is_throttled_after_three_attempts(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(VerifyEmail::class);

        // First 3 attempts should succeed
        for ($i = 0; $i < 3; $i++) {
            $component->call('resend')
                ->assertSet('resent', true)
                ->assertSet('error', '');
        }

        // 4th attempt should be throttled
        $component->call('resend');

        $component->assertSet('resent', false);
        $this->assertNotEmpty($component->get('error'));
        $this->assertStringContainsString('Too many', $component->get('error'));
        $this->assertStringContainsString('attempts', $component->get('error'));

        Notification::assertSentTo($user, VerifyEmailNotification::class, null, 3);
    }

    #[Test]
    public function verified_user_is_redirected_to_members_home(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        // Mount should redirect
        Livewire::test(VerifyEmail::class)
            ->assertRedirect('/church/members');

        Notification::assertNothingSent();
    }
}
