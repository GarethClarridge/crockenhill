<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use Illuminate\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public PageFormData $form;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->form->store();

        $this->success('Page created', redirectTo: route('admin.pages.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Create Page',
            'areas' => $this->form->areaOptions(),
            'page' => null,
        ])->layout('layouts.admin', ['title' => 'Create Page', 'heading' => 'Create Page']);
    }
}
