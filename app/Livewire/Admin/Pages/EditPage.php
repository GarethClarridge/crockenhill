<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use Livewire\Component;

class EditPage extends Component
{
    use PageForm, WithNotifications;

    public Page $page;

    public function mount(Page $page): void
    {
        $this->page = $page;
        $this->heading = $page->heading;
        $this->slug = $page->slug;
        $this->area = $page->area->value;
        $this->navigation = $page->navigation;
        $this->description = $page->description;
        $this->markdown = $page->markdown ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->page->update([
            ...$validated,
            'body' => $this->convertMarkdown(),
        ]);

        $this->success('Page updated');
    }

    public function render()
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Edit Page',
            'areas' => $this->getAreaOptions(),
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->page->heading, 'heading' => 'Edit Page']);
    }
}
