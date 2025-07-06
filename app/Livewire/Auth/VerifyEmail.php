<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class VerifyEmail extends Component
{
    public $resent = false;

    public function resend()
    {
        if (Auth::user()) {
            Auth::user()->sendEmailVerificationNotification();
            $this->resent = true;
        }
    }

    public function render()
    {
        return view('livewire.auth.verify-email');
    }
}
