<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class ForgotPassword extends Component
{
    public $email = '';
    public $status = '';
    public $error = '';

    public function sendResetLink()
    {
        $validator = Validator::make(['email' => $this->email], [
            'email' => 'required|email|exists:users,email',
        ]);
        if ($validator->fails()) {
            $this->error = $validator->errors()->first();
            return;
        }
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