<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class CreatePreacher extends Component
{
    use SanitizesLogData, WithAdminAuthorization, WithNotifications;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $modelRules = Preacher::validationRules();

        return [
            'name' => $modelRules['name'],
            'slug' => $modelRules['slug'],
            'bio' => 'nullable|string',
            'isActive' => 'boolean',
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
            'name' => $this->sanitizeForLog((string) $preacher->name),
            'slug' => $this->sanitizeForLog((string) $preacher->slug),
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
