<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sermon;
use App\Models\User;

class SermonPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageSermons($user);
    }

    public function view(User $user, Sermon $sermon): bool
    {
        return $this->canManageSermons($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageSermons($user);
    }

    public function update(User $user, Sermon $sermon): bool
    {
        return $this->canManageSermons($user);
    }

    public function delete(User $user, Sermon $sermon): bool
    {
        return $this->canManageSermons($user);
    }

    public function restore(User $user, Sermon $sermon): bool
    {
        return $this->canManageSermons($user);
    }

    public function forceDelete(User $user, Sermon $sermon): bool
    {
        return $this->canManageSermons($user);
    }

    private function canManageSermons(User $user): bool
    {
        return $user->is_admin && $user->hasVerifiedEmail();
    }
}
