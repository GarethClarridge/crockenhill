<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class VerifyEmail extends Component
{
    public $resent = false;

    public function resend(): void
    {
        if (Auth::user()) {
            Auth::user()->sendEmailVerificationNotification();
            $this->resent = true;
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.auth.verify-email');
    }
}
