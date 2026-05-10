<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class EditPage extends Component
{
    use SanitizesLogData, WithAdminAuthorization, WithNotifications;

    public Page $page;

    public PageFormData $form;

    public function mount(Page $page): void
    {
        $this->authorizeAdmin();

        $this->page = $page;
        $this->form->setPage($page);
    }

    /**
     * Update the specified page in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $this->authorizeAdmin();

        $this->form->update();

        $fresh = $this->page->fresh();
        Log::warning('Page updated by admin', [
            'admin_id' => auth()->id(),
            'page_id' => $this->page->id,
            'heading' => $this->sanitizeForLog($fresh instanceof Page ? $fresh->heading : $this->page->heading),
            'slug' => $this->sanitizeForLog($fresh instanceof Page ? $fresh->slug : $this->page->slug),
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
