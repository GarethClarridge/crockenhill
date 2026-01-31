<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Page;
use Livewire\Component;
use Mary\Traits\Toast;

class CreatePage extends Component
{
    use Toast, PageForm;

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

    public function render()
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Create Page',
            'areas' => $this->getAreaOptions(),
        ])->layout('components.layouts.admin', ['title' => 'Create Page']);
    }
}
