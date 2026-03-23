<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Traits;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WithAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_verified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $authorizer = new class
        {
            use WithAdminAuthorization;

            public function authorize(): void
            {
                $this->authorizeAdmin();
            }
        };

        $authorizer->authorize();

        $this->assertTrue(true);
    }

    #[Test]
    public function it_aborts_for_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);
        $this->actingAs($user);

        $authorizer = new class
        {
            use WithAdminAuthorization;

            public function authorize(): void
            {
                $this->authorizeAdmin();
            }
        };

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized');

        $authorizer->authorize();
    }
}
