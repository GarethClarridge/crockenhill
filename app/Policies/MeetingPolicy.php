<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageMeetings($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->canManageMeetings($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageMeetings($user);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $this->canManageMeetings($user);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->canManageMeetings($user);
    }

    public function restore(User $user, Meeting $meeting): bool
    {
        return $this->canManageMeetings($user);
    }

    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return $this->canManageMeetings($user);
    }

    private function canManageMeetings(User $user): bool
    {
        return $user->canAccessAdmin();
    }
}
