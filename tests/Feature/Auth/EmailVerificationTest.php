<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function email_can_be_verified(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $verificationUrl = URL::signedRoute('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->actingAs($user)->get($verificationUrl);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('memberHome', [], false).'?verified=1');
    }

    #[Test]
    public function email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $verificationUrl = URL::signedRoute('verification.verify', [
            'id' => $user->id,
            'hash' => 'invalid-hash',
        ]);

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function email_is_not_verified_with_different_user_id(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $otherUser = User::factory()->create(['email_verified_at' => null]);

        $verificationUrl = URL::signedRoute('verification.verify', [
            'id' => $otherUser->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($otherUser->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function guest_cannot_verify_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $verificationUrl = URL::signedRoute('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
