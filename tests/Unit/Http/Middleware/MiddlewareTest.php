<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_admins_to_pass(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $request = Request::create('/admin', 'GET');
        $middleware = new EnsureUserIsAdmin;

        $response = $middleware->handle($request, function () {
            return response('passed');
        });

        $this->assertEquals('passed', $response->getContent());
    }

    #[Test]
    public function it_aborts_for_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);
        $this->actingAs($user);

        $request = Request::create('/admin', 'GET');
        $middleware = new EnsureUserIsAdmin;

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $middleware->handle($request, function () {});
    }

    #[Test]
    public function it_aborts_for_non_admins(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        $request = Request::create('/admin', 'GET');
        $middleware = new EnsureUserIsAdmin;

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $middleware->handle($request, function () {});
    }

    #[Test]
    public function it_aborts_for_guest_users(): void
    {
        $request = Request::create('/admin', 'GET');
        $middleware = new EnsureUserIsAdmin;

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $middleware->handle($request, function () {});
    }
}
