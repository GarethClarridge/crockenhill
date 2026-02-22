<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use Illuminate\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    use PageForm, WithNotifications;

    public ?Page $page = null;

    public function save(): void
    {
        $validated = $this->validate();

        $page = Page::create([
            ...$validated,
            'body' => $this->convertMarkdown(),
        ]);

        $this->success('Page created', redirectTo: route('admin.pages.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Create Page',
            'areas' => $this->getAreaOptions(),
        ])->layout('layouts.admin', ['title' => 'Create Page', 'heading' => 'Create Page']);
    }
}
