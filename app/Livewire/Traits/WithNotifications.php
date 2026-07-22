<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

trait WithNotifications
{
    public function success(string $message, ?string $redirectTo = null): Redirector|RedirectResponse|null
    {
        if ($redirectTo) {
            session()->flash('notification', ['type' => 'success', 'message' => $message]);

            return $this->redirect($redirectTo, navigate: true);
        }

        $this->dispatch('notify', type: 'success', message: $message);

        return null;
    }

    public function error(string $message): void
    {
        $this->dispatch('notify', type: 'error', message: $message);
    }

    public function warning(string $message): void
    {
        $this->dispatch('notify', type: 'warning', message: $message);
    }
}
