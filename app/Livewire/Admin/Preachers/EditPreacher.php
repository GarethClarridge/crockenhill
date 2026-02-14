<?php

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Support\Str;
use Livewire\Component;

class EditPreacher extends Component
{
    use WithNotifications;

    public Preacher $preacher;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    public string $newAlias = '';

    public function mount(Preacher $preacher): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');

        $this->preacher = $preacher;
        $this->name = $preacher->name;
        $this->slug = $preacher->slug;
        $this->bio = $preacher->bio;
        $this->isActive = $preacher->is_active;
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:preachers,name,'.$this->preacher->id,
            'slug' => 'required|string|max:255|unique:preachers,slug,'.$this->preacher->id,
            'bio' => 'nullable|string',
            'isActive' => 'boolean',
            'newAlias' => 'nullable|string|max:255',
        ];
    }

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->preacher->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'bio' => $validated['bio'],
            'is_active' => $validated['isActive'],
        ]);

        $this->success('Preacher updated');
    }

    public function addAlias(): void
    {
        $this->validateOnly('newAlias');

        $alias = strtolower(trim($this->newAlias));

        if ($alias === '') {
            return;
        }

        PreacherAlias::firstOrCreate(
            ['alias' => $alias],
            ['preacher_id' => $this->preacher->id]
        );

        $this->newAlias = '';
        $this->preacher->refresh();
    }

    public function removeAlias(int $aliasId): void
    {
        PreacherAlias::where('id', $aliasId)
            ->where('preacher_id', $this->preacher->id)
            ->delete();

        $this->preacher->refresh();
    }

    public function render()
    {
        return view('livewire.admin.preachers.preacher-form', [
            'title' => 'Edit Preacher',
            'aliases' => $this->preacher->aliases,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->preacher->name, 'heading' => 'Edit Preacher']);
    }
}
