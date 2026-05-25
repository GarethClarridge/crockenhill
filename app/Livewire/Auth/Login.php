<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Traits\SanitizesLogData;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Login extends Component
{
    use SanitizesLogData;

    /**
     * Security: Explicit length constraints are enforced on input fields to provide
     * Defense in Depth against Denial of Service (DoS) attempts with oversized payloads.
     *
     * @var string|array<string, mixed>
     */
    #[Validate('required|string|email|max:255')]
    public string|array $email = '';

    /** @var string|array<string, mixed> */
    #[Validate('required|string|min:1|max:100')]
    public string|array $password = '';

    /** @var bool|array<string, mixed> */
    #[Validate('boolean')]
    public bool|array $remember = false;

    public string $error = '';

    public function login(): Redirector|RedirectResponse|null
    {
        $this->error = '';

        $emailString = is_array($this->email) ? '' : (string) $this->email;

        // Security: Validate input length before normalization to prevent DoS on string operations.
        if (strlen($emailString) > 255) {
            $this->validateOnly('email');

            return null;
        }

        if ($this->isRateLimited($emailString)) {
            return null;
        }

        $validated = $this->validate();

        $credentials = [
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
        ];

        $remember = (bool) ($validated['remember'] ?? false);

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            if ($user instanceof User && $user->is_admin) {
                Log::warning('Admin logged in', [
                    'admin_id' => $user->id,
                    'email' => $this->sanitizeForLog($user->email),
                    'ip' => request()->ip(),
                ]);
            }

            RateLimiter::clear($this->throttleKey($credentials['email']));
            Session::regenerate();

            return redirect()->intended('/church/members');
        }

        RateLimiter::hit($this->throttleKey($credentials['email']));

        $user = User::query()->where('email', (string) $credentials['email'])->first();

        if ($user instanceof User && $user->is_admin) {
            Log::warning('Admin login attempt failed', [
                'admin_id' => $user->id,
                'email' => $this->sanitizeForLog($user->email),
                'ip' => request()->ip(),
            ]);
        }

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
        return Str::transliterate('login|'.Str::lower($email).'|'.request()->ip());
    }
}
