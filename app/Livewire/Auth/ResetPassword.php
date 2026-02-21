<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class ResetPassword extends Component
{
    public string $token = '';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public string $status = '';

    public string $error = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function resetPassword(): Redirector|\Illuminate\Http\RedirectResponse|null
    {
        $this->validate();

        $data = [
            'token' => $this->token,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ];

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->password = Hash::make($password);
            $user->save();
            Auth::login($user);
        });

        if ($status === Password::PASSWORD_RESET) {
            $this->status = __($status);

            return redirect('/church/members');
        }

        $this->error = __($status);

        return null;
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password');
    }
}
