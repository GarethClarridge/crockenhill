<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithNotifications;
use Illuminate\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    use WithNotifications;

    public PageFormData $form;

    public function save(): void
    {
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
