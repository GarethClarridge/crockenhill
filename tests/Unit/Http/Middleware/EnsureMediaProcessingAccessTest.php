<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\ApiTokenAbility;
use App\Http\Middleware\EnsureMediaProcessingAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureMediaProcessingAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeMiddleware(): EnsureMediaProcessingAccess
    {
        return new EnsureMediaProcessingAccess;
    }

    private function makeRequest(?User $user = null, ?string $bearerToken = null): Request
    {
        $request = Request::create('/api/media/audio', 'POST');

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        if ($bearerToken !== null) {
            $request->headers->set('Authorization', "Bearer {$bearerToken}");
        }

        return $request;
    }

    #[Test]
    public function it_allows_verified_admin_without_bearer_token(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $request = $this->makeRequest($admin);
        $middleware = $this->makeMiddleware();

        $response = $middleware->handle($request, fn () => response('passed'));

        $this->assertEquals('passed', $response->getContent());
    }

    #[Test]
    public function it_blocks_unauthenticated_requests(): void
    {
        $request = $this->makeRequest(null);
        $middleware = $this->makeMiddleware();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $middleware->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_blocks_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $request = $this->makeRequest($user);
        $middleware = $this->makeMiddleware();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $middleware->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_blocks_admin_with_unverified_email(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $request = $this->makeRequest($admin);
        $middleware = $this->makeMiddleware();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Your email address is not verified.');

        $middleware->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_allows_verified_admin_with_valid_media_process_token(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $admin->createToken('test-token', [ApiTokenAbility::MEDIA_PROCESS->value]);
        $plaintext = $token->plainTextToken;

        // Resolve the token via Sanctum so tokenCan() works
        $pat = PersonalAccessToken::findToken(explode('|', $plaintext, 2)[1] ?? $plaintext);
        $admin->withAccessToken($pat);

        $request = $this->makeRequest($admin, $plaintext);
        $middleware = $this->makeMiddleware();

        $response = $middleware->handle($request, fn () => response('passed'));

        $this->assertEquals('passed', $response->getContent());
    }

    #[Test]
    public function it_blocks_verified_admin_with_token_missing_media_process_ability(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $admin->createToken('test-token', ['some:other:ability']);
        $plaintext = $token->plainTextToken;

        $pat = PersonalAccessToken::findToken(explode('|', $plaintext, 2)[1] ?? $plaintext);
        $admin->withAccessToken($pat);

        $request = $this->makeRequest($admin, $plaintext);
        $middleware = $this->makeMiddleware();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Missing required token ability: '.ApiTokenAbility::MEDIA_PROCESS->value);

        $middleware->handle($request, fn () => response('passed'));
    }
}
