<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class EditPage extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public Page $page;

    public PageFormData $form;

    public function mount(Page $page): void
    {
        $this->authorizeAdmin();

        $this->page = $page;
        $this->form->setPage($page);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->form->update();

        Log::warning('Page updated by admin', [
            'admin_id' => auth()->id(),
            'page_id' => $this->page->id,
            'heading' => $this->page->fresh()?->heading ?? $this->page->heading,
            'slug' => $this->page->fresh()?->slug ?? $this->page->slug,
        ]);

        $this->success('Page updated');
    }

    public function render(): View
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Edit Page',
            'areas' => $this->form->areaOptions(),
            'page' => $this->page,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->page->heading, 'heading' => 'Edit Page']);
    }
}
