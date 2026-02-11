<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sermon;
use App\Models\User;

class SermonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Sermon $sermon): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, Sermon $sermon): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Sermon $sermon): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, Sermon $sermon): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Sermon $sermon): bool
    {
        return $user->is_admin;
    }
}
