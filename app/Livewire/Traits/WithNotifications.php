<?php

namespace App\Livewire\Traits;

trait WithNotifications
{
    public function success(string $message, ?string $redirectTo = null): mixed
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
}
