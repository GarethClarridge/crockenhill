<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Playwright freezes the clock months back (PLAYWRIGHT_NOW). A session cookie with an expiry is then
 * already expired in the browser, which drops it, so every login was lost (nightly Deep Checks from
 * 10-18). The Playwright environment uses a browser-session cookie (SESSION_EXPIRE_ON_CLOSE).
 */
class FrozenClockSessionCookieTest extends TestCase
{
    #[Test]
    public function a_frozen_past_clock_gives_the_session_cookie_an_expiry_already_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 12:00:00'));
        config(['session.driver' => 'array', 'session.expire_on_close' => false]);

        $cookie = collect($this->get('/login')->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertLessThan(time(), $cookie->getExpiresTime());
    }

    #[Test]
    public function expire_on_close_keeps_the_session_cookie_whatever_the_clock_says(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 12:00:00'));
        config(['session.driver' => 'array', 'session.expire_on_close' => true]);

        $cookie = collect($this->get('/login')->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime());
    }

    #[Test]
    public function the_playwright_environment_asks_for_a_browser_session_cookie(): void
    {
        $this->assertStringContainsString("SESSION_EXPIRE_ON_CLOSE=true\n", (string) file_get_contents(base_path('.env.playwright.ci')));
    }
}
