<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use Illuminate\Support\Facades\Log;
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:preachers,slug'],
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

        $preacher = Preacher::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'bio' => $validated['bio'],
            'is_active' => $validated['isActive'],
        ]);

        Log::warning('New preacher created by admin', [
            'admin_id' => auth()->id(),
            'preacher_id' => $preacher->id,
            'name' => $preacher->name,
            'slug' => $preacher->slug,
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
