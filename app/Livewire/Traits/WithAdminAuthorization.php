<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

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
