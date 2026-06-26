<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the `preventRequestForgery(allowSameSite: true)` configuration in
 * bootstrap/app.php — defence-in-depth on top of the existing token check.
 *
 * Notes on the test design:
 *  - The framework's `PreventRequestForgery::handle()` short-circuits to
 *    "accept" when `app->runningUnitTests()` is true, so HTTP-level tests
 *    cannot exercise the real CSRF path. We invoke `handle()` directly on an
 *    anonymous subclass that stubs `runningUnitTests()` to return false,
 *    allowing us to exercise the real CSRF flow behaviorally without reflection.
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
        $request = Request::create('/admin/sermons', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->headers->set('Sec-Fetch-Site', 'same-site');

        $response = $this->callMiddlewareDirectly($request);

        $this->assertEquals(
            'ok',
            $response->getContent(),
            'bootstrap/app.php must call preventRequestForgery(allowSameSite: true) so '
            .'browsers signalling Sec-Fetch-Site: same-site are accepted as origin-verified.'
        );
    }

    #[Test]
    public function same_site_request_is_treated_as_having_a_valid_origin(): void
    {
        $sameSiteRequest = Request::create('/admin/sermons', 'POST');
        $sameSiteRequest->setLaravelSession($this->app['session.store']);
        $sameSiteRequest->headers->set('Sec-Fetch-Site', 'same-site');

        $this->assertEquals(
            'ok',
            $this->callMiddlewareDirectly($sameSiteRequest)->getContent(),
            'With allowSameSite enabled, same-site requests must be accepted without a token fallback.'
        );

        $sameOriginRequest = Request::create('/admin/sermons', 'POST');
        $sameOriginRequest->setLaravelSession($this->app['session.store']);
        $sameOriginRequest->headers->set('Sec-Fetch-Site', 'same-origin');

        $this->assertEquals(
            'ok',
            $this->callMiddlewareDirectly($sameOriginRequest)->getContent(),
            'Same-origin requests must always be accepted, regardless of allowSameSite.'
        );
    }

    #[Test]
    public function cross_site_request_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/sermons', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        $this->expectException(TokenMismatchException::class);

        $this->callMiddlewareDirectly($request);
    }

    /**
     * Invoke the middleware as if it were running outside the unit-test bypass.
     *
     * `PreventRequestForgery::handle()` skips its checks when the framework
     * detects it is running under PHPUnit. We use an anonymous subclass that
     * overrides `runningUnitTests()` to return `false`, ensuring the real CSRF
     * logic executes even during a unit test.
     */
    private function callMiddlewareDirectly(Request $request): Response
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
