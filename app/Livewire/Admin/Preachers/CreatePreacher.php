<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class CreatePreacher extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:preachers,name',
            'slug' => 'required|string|max:255|unique:preachers,slug',
            'bio' => 'nullable|string',
            'isActive' => 'boolean',
        ];
    }

    public function mount(): void
    {

        $this->authorizeAdmin();
    }

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    public function save(): void
    {

        $this->authorizeAdmin();

        $validated = $this->validate();

        Preacher::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'bio' => $validated['bio'],
            'is_active' => $validated['isActive'],
        ]);

        $this->success('Preacher created', redirectTo: route('admin.preachers.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.preachers.preacher-form', [
            'title' => 'Create Preacher',
        ])->layout('layouts.admin', ['title' => 'Create Preacher', 'heading' => 'Create Preacher']);
    }
}
