<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:1')]
    public string $password = '';

    public bool $remember = false;

    public string $error = '';

    public function login()
    {
        $this->validate();

        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
        ];
        if (Auth::attempt($credentials, $this->remember)) {
            Session::regenerate();

            return redirect()->intended('/church/members');
        }
        $this->error = 'The provided credentials do not match our records.';
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
