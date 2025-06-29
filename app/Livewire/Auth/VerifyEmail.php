<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Redirect;

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