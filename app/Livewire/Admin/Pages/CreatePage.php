<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Forms\PageFormData;
use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithNotifications;
use Illuminate\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    use WithAdminSave, WithNotifications;

    public PageFormData $form;

    /**
     * Store a newly created page in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $this->adminSave(
            save: function (): array {
                $page = $this->form->store();

                return [
                    'page_id' => $page->id,
                    'heading' => $this->sanitizeForLog($page->heading),
                    'slug' => $this->sanitizeForLog($page->slug),
                ];
            },
            logAction: 'New page created by admin',
        );

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
