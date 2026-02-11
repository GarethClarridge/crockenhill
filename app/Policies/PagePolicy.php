<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Page $page): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, Page $page): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Page $page): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, Page $page): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Page $page): bool
    {
        return $user->is_admin;
    }
}
