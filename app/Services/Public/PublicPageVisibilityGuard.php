<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Service for enforcing visibility and authentication rules for public-facing pages.
 *
 * This guard ensures that administrative pages are restricted to authorized users
 * and that members-only areas require both authentication and email verification,
 * providing a centralized layer for page-level access control.
 */
class PublicPageVisibilityGuard
{
    /**
     * Enforce visibility rules for the given page.
     *
     * Checks if the page requires administrative access or belongs to the
     * members area. Returns a RedirectResponse if the user must be
     * redirected to login or verification pages, or null if access is granted.
     *
     * @param  Page|null  $page  The page to check visibility for
     * @return RedirectResponse|null A redirect if requirements aren't met, or null
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException When admin access is denied
     */
    public function enforce(?Page $page): ?RedirectResponse
    {
        if (! $page instanceof Page) {
            return null;
        }

        $user = Auth::user();

        if ($page->admin === 'yes' && ($user === null || ! $user->canAccessAdmin())) {
            abort(403, 'Unauthorized action.');
        }

        // Members-area pages require authenticated + verified email, matching the route middleware.
        if ($page->area === PageArea::Members) {
            if ($user === null) {
                return redirect()->guest(route('login'));
            }

            if (! $user->hasVerifiedEmail()) {
                return redirect()->guest(route('verification.notice'));
            }
        }

        return null;
    }
}
