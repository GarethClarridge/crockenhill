<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use Illuminate\View\View;
use Livewire\Component;

class EditPage extends Component
{
    use WithAdminSave, WithNotifications;

    public Page $page;

    public PageFormData $form;

    public function mount(Page $page): void
    {
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
        $this->adminSave(
            save: function (): array {
                $this->form->update();

                $fresh = $this->page->fresh();

                return [
                    'page_id' => $this->page->id,
                    'heading' => self::sanitizeForLog($fresh instanceof Page ? $fresh->heading : $this->page->heading),
                    'slug' => self::sanitizeForLog($fresh instanceof Page ? $fresh->slug : $this->page->slug),
                ];
            },
            logAction: 'Page updated by admin',
        );

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
