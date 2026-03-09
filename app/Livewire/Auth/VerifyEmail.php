<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class VerifyEmail extends Component
{
    public bool $resent = false;

    public string $error = '';

    public function mount(): Redirector|RedirectResponse|null
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if ($user?->hasVerifiedEmail()) {
            return redirect()->intended('/church/members');
        }

        return null;
    }

    public function resend(): Redirector|RedirectResponse|null
    {
        $this->resent = false;
        $this->error = '';

        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended('/church/members');
        }

        if ($this->isRateLimited()) {
            return null;
        }

        RateLimiter::hit($this->throttleKey());

        $user->sendEmailVerificationNotification();
        $this->resent = true;

        return null;
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
        $userId = Auth::id() ?? 'guest';

        return Str::transliterate('verify-email|'.$userId.'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email');
    }
}
