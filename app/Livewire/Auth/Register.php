<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Traits\SanitizesLogData;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Register extends Component
{
    use SanitizesLogData;

    public string $name = '';

    public string $email = '';

    /**
     * @return array<string, array<int, string|Password|Unique|null>>
     *
     * Security: Explicit length constraints are enforced on sensitive fields to provide
     * Defense in Depth against Denial of Service (DoS) attempts with oversized payloads.
     */
    public function rules(): array
    {
        return array_merge(
            User::validationRules(),
            [
                'password' => [
                    'required',
                    'string',
                    'max:100', // Defense in Depth against DoS
                    'confirmed',
                    Password::defaults(),
                ],
            ]
        );
    }

    public string $password = '';

    public string $password_confirmation = '';

    public string $error = '';

    public function register(): Redirector|RedirectResponse|null
    {
        $emailString = $this->email;

        // Security: Validate input length before normalization to prevent DoS on string operations.
        if (strlen($emailString) > 255 || strlen($this->name) > 255) {
            $this->validate();

            return null;
        }

        if ($this->isRateLimited()) {
            return null;
        }

        $this->validate();

        RateLimiter::hit($this->throttleKey());

        $user = User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        // "Members only" currently means "has a user account", so registration signs
        // the user in immediately and verification remains a separate concern.
        Log::info('New user registered', [
            'user_id' => $user->id,
            'name' => $this->sanitizeForLog($user->name),
            'email' => $this->sanitizeForLog($user->email),
            'ip' => request()->ip(),
        ]);

        Auth::login($user);
        Session::regenerate();
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
        return Str::transliterate('register|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
