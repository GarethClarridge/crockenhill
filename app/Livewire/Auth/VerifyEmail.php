<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class VerifyEmail extends Component
{
    public bool $resent = false;

    public function resend(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $user->sendEmailVerificationNotification();
        $this->resent = true;
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email');
    }
}
