<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Register extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255|unique:users')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public string $error = '';

    public function register(): Redirector|RedirectResponse|null
    {
        $this->validate();

        if ($this->isRateLimited()) {
            return null;
        }

        RateLimiter::hit($this->throttleKey());

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        Auth::login($user);
        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }

    protected function isRateLimited(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 3)) {
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

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
