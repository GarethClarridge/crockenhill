<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class ResetPassword extends Component
{
    public $token;

    public $email = '';

    public $password = '';

    public $password_confirmation = '';

    public $status = '';

    public $error = '';

    public function mount($token)
    {
        $this->token = $token;
    }

    public function resetPassword()
    {
        $data = [
            'token' => $this->token,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ];
        $validator = Validator::make($data, [
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            $this->error = $validator->errors()->first();

            return;
        }
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
