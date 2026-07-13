<?php

declare(strict_types=1);

namespace App\Livewire\Christ;

use App\Mail\BibleRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class BibleRequestForm extends Component
{
    #[Validate('required|string|max:100')]
    public string $name = '';

    #[Validate('required|string|max:300')]
    public string $address = '';

    #[Validate('nullable|email|max:255')]
    public ?string $email = null;

    #[Validate('nullable|string|max:30')]
    public ?string $phone = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $note = null;

    /** Honeypot field — must remain empty. Bots typically fill hidden fields. */
    public string $website = '';

    public bool $submitted = false;

    public string $rateLimitError = '';

    public function submit(): void
    {
        // Honeypot: if filled, this is almost certainly a bot.
        // Silently succeed so the bot receives no feedback that it was detected.
        if ($this->website !== '') {
            $this->reset(['name', 'address', 'email', 'phone', 'note', 'website']);
            $this->submitted = true;

            return;
        }

        if ($this->isRateLimited()) {
            return;
        }

        // Normalize optional fields: wire:model sends '' when cleared, but
        // Laravel's nullable rule only skips validation for null, not ''.
        $this->email = $this->email !== '' ? $this->email : null;
        $this->phone = $this->phone !== '' ? $this->phone : null;
        $this->note = $this->note !== '' ? $this->note : null;

        $validated = $this->validate();

        RateLimiter::hit($this->throttleKey(), 3600);

        Mail::to(config('church.email_public'))
            ->send(new BibleRequest($validated));

        $this->reset(['name', 'address', 'email', 'phone', 'note']);
        $this->submitted = true;
    }

    private function isRateLimited(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 3)) {
            return false;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());
        $this->rateLimitError = 'Too many requests. Please try again in '.(int) ceil($seconds / 60).' minutes, or call us directly.';

        return true;
    }

    private function throttleKey(): string
    {
        return Str::transliterate('bible-request|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.christ.bible-request-form');
    }
}
