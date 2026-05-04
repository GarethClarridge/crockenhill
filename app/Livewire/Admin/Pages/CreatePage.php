<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    use SanitizesLogData, WithAdminAuthorization, WithNotifications;

    public PageFormData $form;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $page = $this->form->store();

        Log::warning('New page created by admin', [
            'admin_id' => auth()->id(),
            'page_id' => $page->id,
            'heading' => $this->sanitizeForLog($page->heading),
            'slug' => $this->sanitizeForLog($page->slug),
        ]);

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
