<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

trait WithAdminAuthorization
{
    protected function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin === true, 403, 'Unauthorized');
    }
}
