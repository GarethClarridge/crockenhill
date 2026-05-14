<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

/**
 * Admin authorization safety net for routed Livewire components.
 *
 * Routed admin Livewire components live behind the `auth`, `verified`, `admin`
 * middleware group, which enforces the boundary on initial page load and on
 * every Livewire AJAX request. This trait is a deliberate defense-in-depth
 * layer on top of that: every routed admin Livewire component MUST use this
 * trait, and every mutating action MUST call `$this->authorizeAdmin()` before
 * mutating state. Calls in `mount()` are optional because route middleware
 * already covers the initial render.
 *
 * The redundancy is intentional. Route groups can be misconfigured silently
 * (a new path added under the wrong middleware stack); the trait keeps the
 * component itself responsible for its own boundary. See
 * `tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php`
 * for the structural check that pins this contract.
 */
trait WithAdminAuthorization
{
    protected function authorizeAdmin(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->canAccessAdmin() === true,
            403,
            'Unauthorized',
        );
    }
}
