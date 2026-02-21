<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ForgotPassword extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public string $status = '';

    public string $error = '';

    public function sendResetLink(): void
    {
        $this->validate();
        $this->error = '';

        Password::sendResetLink(['email' => $this->email]);
        $this->status = __(Password::RESET_LINK_SENT);
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
