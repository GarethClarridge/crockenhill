<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    /**
     * @return array<string, array<int, string|\Illuminate\Validation\Rules\Password>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                PasswordRule::min(12)
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
        ];
    }

    public string $password = '';

    public string $password_confirmation = '';

    public string $status = '';

    public string $error = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function resetPassword(): Redirector|RedirectResponse|null
    {
        $this->validate();

        if ($this->isRateLimited()) {
            return null;
        }

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
            RateLimiter::clear($this->throttleKey());
            $this->status = __($status);

            return redirect('/church/members');
        }

        RateLimiter::hit($this->throttleKey());
        $this->error = __($status);

        return null;
    }

    protected function isRateLimited(): bool
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

    protected function throttleKey(): string
    {
        return Str::transliterate('reset|'.Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password');
    }
}
