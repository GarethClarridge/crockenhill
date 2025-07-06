<?php

namespace App\Policies;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SermonPolicy
{
    use HandlesAuthorization;

    // Note: The Gate::before in AuthServiceProvider will grant access to @crockenhill.org users
    // before these policy methods are checked. These methods reflect the specific
    // Gate::define('edit-sermons', ...) logic for other users.

    private function canEditSermons(User $user): bool
    {
        // This logic is taken directly from the original Gate::define('edit-sermons')
        $emails = [
            '', // Placeholder, effectively means no one unless Gate::before allows
            '',
        ];

        return in_array($user->email, $emails);
    }

    public function viewAny(User $user): bool
    {
        return $this->canEditSermons($user);
    }

    public function view(User $user, Sermon $sermon): bool
    {
        // Public sermons are generally viewable.
        // This policy method is for authorizing specific views within an admin context if needed.
        // Replicating the 'edit-sermons' logic here for consistency.
        return $this->canEditSermons($user);
    }

    public function create(User $user): bool
    {
        return $this->canEditSermons($user);
    }

    public function update(User $user, Sermon $sermon): bool
    {
        return $this->canEditSermons($user);
    }

    public function delete(User $user, Sermon $sermon): bool
    {
        return $this->canEditSermons($user);
    }

    public function restore(User $user, Sermon $sermon): bool
    {
        return $this->canEditSermons($user);
    }

    public function forceDelete(User $user, Sermon $sermon): bool
    {
        return $this->canEditSermons($user);
    }
}
