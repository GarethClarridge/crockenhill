<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ForgotPassword extends Component
{
    #[Validate('required|email|exists:users,email')]
    public string $email = '';

    public string $status = '';

    public string $error = '';

    public function sendResetLink()
    {
        $this->validate();

        $status = Password::sendResetLink(['email' => $this->email]);
        if ($status === Password::RESET_LINK_SENT) {
            $this->status = __($status);
        } else {
            $this->error = __($status);
        }
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
