<?php

declare(strict_types=1);

namespace Tests\Integration\Http\Middleware;

use App\Enums\ApiTokenAbility;
use App\Http\Middleware\EnsureServiceTrackingAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureServiceTrackingAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeMiddleware(): EnsureServiceTrackingAccess
    {
        return new EnsureServiceTrackingAccess;
    }

    private function makeRequest(?User $user = null, ?string $bearerToken = null): Request
    {
        $request = Request::create('/api/services/openlp', 'POST');

        if ($user !== null) {
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

        $this->assertSame('passed', $response->getContent());
    }

    #[Test]
    public function it_blocks_unauthenticated_requests(): void
    {
        $request = $this->makeRequest();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $this->makeMiddleware()->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_blocks_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $request = $this->makeRequest($user);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        $this->makeMiddleware()->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_blocks_admin_with_unverified_email(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $request = $this->makeRequest($admin);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Your email address is not verified.');

        $this->makeMiddleware()->handle($request, fn () => response('passed'));
    }

    #[Test]
    public function it_allows_verified_admin_with_valid_service_upload_token(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $admin->createToken('test-token', [ApiTokenAbility::ServiceUpload->value]);
        $plainTextToken = $token->plainTextToken;

        $pat = PersonalAccessToken::findToken(explode('|', $plainTextToken, 2)[1] ?? $plainTextToken);
        $admin->withAccessToken($pat);

        $request = $this->makeRequest($admin, $plainTextToken);

        $response = $this->makeMiddleware()->handle($request, fn () => response('passed'));

        $this->assertSame('passed', $response->getContent());
    }

    #[Test]
    public function it_blocks_verified_admin_with_token_missing_service_upload_ability(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $admin->createToken('test-token', ['media:process']);
        $plainTextToken = $token->plainTextToken;

        $pat = PersonalAccessToken::findToken(explode('|', $plainTextToken, 2)[1] ?? $plainTextToken);
        $admin->withAccessToken($pat);

        $request = $this->makeRequest($admin, $plainTextToken);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Missing required token ability: '.ApiTokenAbility::ServiceUpload->value);

        $this->makeMiddleware()->handle($request, fn () => response('passed'));
    }
}
