<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ResetPassword extends Component
{
    public string $token = '';

    #[Validate('required|email|exists:users,email')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public string $status = '';

    public string $error = '';

    public function mount($token)
    {
        $this->token = $token;
    }

    public function resetPassword()
    {
        $this->validate();

        $data = [
            'token' => $this->token,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ];

        $status = Password::reset($data, function ($user, $password) {
            $user->password = Hash::make($password);
            $user->save();
            Auth::login($user);
        });
        if ($status === Password::PASSWORD_RESET) {
            $this->status = __($status);

            return redirect('/church/members');
        } else {
            $this->error = __($status);
        }
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
