<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class Register extends Component
{
    public $name = '';
    public $email = '';
    public $password = '';
    public $password_confirmation = '';
    public $error = '';

    public function register()
    {
        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ];
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            $this->error = $validator->errors()->first();
            return;
        }
        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);
        Auth::login($user);
        $user->sendEmailVerificationNotification();
        return redirect()->route('verification.notice');
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
} 