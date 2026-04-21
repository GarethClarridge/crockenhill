<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ForgotPassword extends Component
{
    /**
     * Security: Explicit length constraints are enforced on input fields to provide
     * Defense in Depth against Denial of Service (DoS) attempts with oversized payloads.
     */
    #[Validate('required|email|max:255')]
    public string $email = '';

    public string $status = '';

    public string $error = '';

    public function sendResetLink(): void
    {
        $this->error = '';

        if ($this->isRateLimited()) {
            return;
        }

        $this->validate();

        RateLimiter::hit($this->throttleKey());

        Password::sendResetLink(['email' => $this->email]);
        $this->status = __(Password::RESET_LINK_SENT);
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

    /**
     * Get the rate limiting throttle key for the password reset request.
     *
     * Security: Str::limit is applied to the email identifier to provide Defense in Depth
     * against potential Cache Key Injection or Denial of Service (DoS) attacks targeting
     * the cache storage by providing extremely long keys.
     */
    protected function throttleKey(): string
    {
        $email = is_string($this->email) ? $this->email : 'invalid';

        return Str::transliterate('forgot|'.Str::lower(Str::limit($email, 255)).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
