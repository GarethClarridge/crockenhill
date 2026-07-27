<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class CreatePreacher extends Component
{
    use WithAdminSave, WithNotifications;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $modelRules = Preacher::validationRules();

        return [
            'name' => $modelRules['name'],
            'slug' => $modelRules['slug'],
            'bio' => $modelRules['bio'],
            'isActive' => $modelRules['is_active'],
        ];
    }

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    /**
     * Store a newly created preacher in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $this->adminSave(
            save: function () use ($validated): array {
                $preacher = Preacher::query()->create([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'bio' => $validated['bio'],
                    'is_active' => $validated['isActive'],
                ]);

                return [
                    'preacher_id' => $preacher->id,
                    'name' => $this->sanitizeForLog((string) $preacher->name),
                    'slug' => $this->sanitizeForLog((string) $preacher->slug),
                ];
            },
            logAction: 'New preacher created by admin',
        );

        $this->success('Preacher created', redirectTo: route('admin.preachers.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.preachers.preacher-form', [
            'title' => 'Create Preacher',
        ])->layout('layouts.admin', ['title' => 'Create Preacher', 'heading' => 'Create Preacher']);
    }
}
