<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class Login extends Component
{
    public $email = '';
    public $password = '';
    public $remember = false;
    public $error = '';

    public function login()
    {
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