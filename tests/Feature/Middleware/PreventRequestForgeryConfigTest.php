<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the `preventRequestForgery(allowSameSite: true)` configuration in
 * bootstrap/app.php — defence-in-depth on top of the existing token check.
 *
 * Notes on the test design:
 *  - The framework's `PreventRequestForgery::handle()` short-circuits to
 *    "accept" when `app->runningUnitTests()` is true, so HTTP-level tests
 *    cannot exercise the real CSRF path. We invoke `handle()` directly and
 *    stub `runningUnitTests` via the protected static `$skipsRunningTests`
 *    workaround — there isn't one, so we exercise the underlying
 *    `hasValidOrigin()` method through reflection instead.
 *  - The middleware reads its `allowSameSite` flag from a protected static
 *    property set by `PreventRequestForgery::allowSameSite()`. Bootstrap
 *    runs once per test process, so by the time this test executes the
 *    flag has already been flipped to `true`.
 */
class PreventRequestForgeryConfigTest extends TestCase
{
    #[Test]
    public function bootstrap_enables_allow_same_site(): void
    {
        $reflection = new ReflectionClass(PreventRequestForgery::class);
        $property = $reflection->getProperty('allowSameSite');
        $property->setAccessible(true);

        $this->assertTrue(
            $property->getValue(),
            'bootstrap/app.php must call preventRequestForgery(allowSameSite: true) so '
            .'browsers signalling Sec-Fetch-Site: same-site are accepted as origin-verified.'
        );
    }

    #[Test]
    public function same_site_request_is_treated_as_having_a_valid_origin(): void
    {
        $middleware = $this->app->make(PreventRequestForgery::class);

        $hasValidOrigin = (new ReflectionClass($middleware))->getMethod('hasValidOrigin');
        $hasValidOrigin->setAccessible(true);

        $sameSiteRequest = Request::create('/admin/sermons', 'POST');
        $sameSiteRequest->headers->set('Sec-Fetch-Site', 'same-site');

        $this->assertTrue(
            $hasValidOrigin->invoke($middleware, $sameSiteRequest),
            'With allowSameSite enabled, same-site requests must be accepted without a token fallback.'
        );

        $sameOriginRequest = Request::create('/admin/sermons', 'POST');
        $sameOriginRequest->headers->set('Sec-Fetch-Site', 'same-origin');

        $this->assertTrue(
            $hasValidOrigin->invoke($middleware, $sameOriginRequest),
            'Same-origin requests must always be accepted, regardless of allowSameSite.'
        );
    }

    #[Test]
    public function cross_site_request_without_token_is_rejected(): void
    {
        $middleware = $this->app->make(PreventRequestForgery::class);

        $request = Request::create('/admin/sermons', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        $this->expectException(TokenMismatchException::class);

        $this->callMiddlewareDirectly($middleware, $request);
    }

    /**
     * Invoke the middleware as if it were running outside the unit-test bypass.
     *
     * `PreventRequestForgery::handle()` skips its checks when the framework
     * detects it is running under PHPUnit. We rebind the app's `runningInConsole`
     * detector by toggling the protected `$app` reference on a clone of the
     * middleware, so the same handle() method executes the real CSRF flow.
     */
    private function callMiddlewareDirectly(PreventRequestForgery $middleware, Request $request): Response
    {
        $next = static fn (Request $r): Response => new Response('ok');

        $bypassDisabled = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        return $bypassDisabled->handle($request, Closure::fromCallable($next));
    }
}
