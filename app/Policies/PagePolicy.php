<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PagePolicy
{
    use HandlesAuthorization;

    // Note: The Gate::before in AuthServiceProvider will grant access to @crockenhill.org users
    // before these policy methods are checked. These methods reflect the specific
    // Gate::define('edit-pages', ...) logic for other users.

    private function canEditPages(User $user): bool
    {
        // This logic is taken directly from the original Gate::define('edit-pages')
        $emails = [
            '', // Placeholder, effectively means no one unless Gate::before allows
            '',
        ];

        return in_array($user->email, $emails);
    }

    public function viewAny(User $user): bool
    {
        return $this->canEditPages($user);
    }

    public function view(User $user, Page $page): bool
    {
        // Public pages are generally viewable.
        // This policy method is for authorizing specific views within an admin context if needed.
        // Replicating the 'edit-pages' logic here for consistency.
        return $this->canEditPages($user);
    }

    public function create(User $user): bool
    {
        return $this->canEditPages($user);
    }

    public function update(User $user, Page $page): bool
    {
        return $this->canEditPages($user);
    }

    public function delete(User $user, Page $page): bool
    {
        return $this->canEditPages($user);
    }

    public function restore(User $user, Page $page): bool
    {
        return $this->canEditPages($user);
    }

    public function forceDelete(User $user, Page $page): bool
    {
        return $this->canEditPages($user);
    }
}
