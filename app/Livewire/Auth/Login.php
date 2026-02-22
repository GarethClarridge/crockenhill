<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:1')]
    public string $password = '';

    public bool $remember = false;

    public string $error = '';

    public function login(): Redirector|RedirectResponse|null
    {
        $this->validate();
        $this->error = '';

        if ($this->isRateLimited()) {
            return null;
        }

        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
        ];

        if (Auth::attempt($credentials, $this->remember)) {
            RateLimiter::clear($this->throttleKey());
            Session::regenerate();

            return redirect()->intended('/church/members');
        }

        RateLimiter::hit($this->throttleKey());
        $this->error = trans('auth.failed');

        return null;
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }

    private function isRateLimited(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return false;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());
        $this->error = trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);

        return true;
    }

    private function throttleKey(): string
    {
        return Str::transliterate('login|'.Str::lower($this->email).'|'.request()->ip());
    }
}
