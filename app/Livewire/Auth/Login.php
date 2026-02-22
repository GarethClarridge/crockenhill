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
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Login extends Component
{
    #[Validate('required|string|email')]
    public string|array $email = '';

    #[Validate('required|string|min:1')]
    public string|array $password = '';

    #[Validate('boolean')]
    public bool|array $remember = false;

    #[Locked]
    public string $error = '';

    public function login(): Redirector|RedirectResponse|null
    {
        $validated = $this->validate();
        $email = (string) $validated['email'];
        $password = (string) $validated['password'];
        $remember = (bool) ($validated['remember'] ?? false);
        $this->error = '';

        if ($this->isRateLimited($email)) {
            return null;
        }

        $credentials = [
            'email' => $email,
            'password' => $password,
        ];

        if (Auth::attempt($credentials, $remember)) {
            RateLimiter::clear($this->throttleKey($email));
            Session::regenerate();

            return redirect()->intended('/church/members');
        }

        RateLimiter::hit($this->throttleKey($email));
        $this->error = trans('auth.failed');

        return null;
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }

    private function isRateLimited(string $email): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($email), 5)) {
            return false;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey($email));
        $this->error = trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);

        return true;
    }

    private function throttleKey(string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.request()->ip());
    }
}
