<?php

declare(strict_types=1);

namespace App\Services;

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

        if ($page->admin === 'yes' && ($user === null || ! $user->is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        // Members-area pages follow the same rule as the rest of the members area:
        // any authenticated account may view them.
        if ($page->area === PageArea::MEMBERS && $user === null) {
            return redirect()->guest(route('login'));
        }

        return null;
    }
}
