<?php

declare(strict_types=1);

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Smoke test that Livewire's JavaScript runtime actually boots and hydrates
 * components in a real browser.
 *
 * Server-side tests (Livewire::test(), HTTP feature tests) never execute
 * JavaScript, so a frontend/backend version mismatch — e.g. stale published
 * assets in public/vendor/livewire shadowing the dynamic asset route — breaks
 * every Livewire page while the whole PHPUnit suite stays green. This test
 * closes that gap for any failure mode where livewire.js loads but cannot
 * hydrate the server-rendered snapshots.
 */
class LivewireHydrationTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_livewire_runtime_hydrates_components_and_completes_an_update_roundtrip(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login');

            $browser->waitUsing(10, 100, function () use ($browser) {
                return $browser->script(
                    'return typeof window.Livewire !== "undefined" && window.Livewire.all().length > 0'
                )[0];
            }, 'Livewire JS runtime never registered any components — frontend assets may be stale, missing, or incompatible with the installed Livewire version.');

            $unhydratedCount = $browser->script(
                'return Array.from(document.querySelectorAll("*")).filter((el) => el.hasAttribute("wire:id") && ! el.__livewire).length'
            )[0];

            $this->assertSame(
                0,
                $unhydratedCount,
                'Some server-rendered Livewire components were never hydrated client-side (e.g. "Snapshot missing" errors from mismatched frontend assets).'
            );

            $browser->script(<<<'JS'
                window.__livewireRefreshResult = 'pending';
                window.Livewire.all()[0].$wire.$refresh()
                    .then(() => { window.__livewireRefreshResult = 'ok'; })
                    .catch((error) => { window.__livewireRefreshResult = 'failed: ' + error; });
            JS);

            $browser->waitUsing(10, 100, function () use ($browser) {
                return $browser->script('return window.__livewireRefreshResult')[0] === 'ok';
            }, 'Livewire update roundtrip failed — the component could not re-render via the update endpoint.');
        });
    }

    /**
     * Alpine evaluates x-init/x-data expressions at boot; a malformed expression
     * (e.g. Blade conditionals concatenating two statements without a separator)
     * logs a SEVERE console error but leaves hydration intact, so the roundtrip
     * test above stays green. This catches that failure mode.
     */
    public function test_login_page_boots_without_severe_javascript_console_errors(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->driver->manage()->getLog('browser');

            $browser->visit('/login');

            $browser->waitUsing(10, 100, function () use ($browser) {
                return $browser->script(
                    'return typeof window.Livewire !== "undefined" && window.Livewire.all().length > 0'
                )[0];
            }, 'Livewire JS runtime never booted on the login page.');

            $severeEntries = array_values(array_filter(
                $browser->driver->manage()->getLog('browser'),
                fn (array $entry): bool => $entry['level'] === 'SEVERE'
                    && in_array($entry['source'], ['javascript', 'console-api'], true)
            ));

            $this->assertSame(
                [],
                array_column($severeEntries, 'message'),
                'JavaScript errors were logged while booting the login page.'
            );
        });
    }
}
