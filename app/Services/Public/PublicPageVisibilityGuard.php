<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PublicPageVisibilityGuard
{
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
