<?php

declare(strict_types=1);

namespace App\Livewire\Christ;

use App\Mail\BibleRequest;
use Illuminate\Support\Facades\Mail;
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
    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    #[Validate('nullable|string|max:1000')]
    public string $message = '';

    public bool $submitted = false;

    public function submit(): void
    {
        $validated = $this->validate();

        Mail::to(config('organization.email_public'))
            ->send(new BibleRequest($validated));

        $this->reset(['name', 'address', 'email', 'phone', 'message']);
        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.christ.bible-request-form');
    }
}
