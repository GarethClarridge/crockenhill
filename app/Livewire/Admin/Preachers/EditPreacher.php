<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Support\Str;
use Illuminate\View\View;
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

    /**
     * @return array<string, mixed>
     */
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

    public function recomputeProfile(int $profileId): void
    {
        $profile = SpeakerProfile::where('id', $profileId)
            ->where('preacher_id', $this->preacher->id)
            ->firstOrFail();

        $approvedEmbeddings = SpeakerSample::query()
            ->where('speaker_profile_id', $profile->id)
            ->where('approved', true)
            ->pluck('embedding')
            ->filter(fn ($embedding) => is_array($embedding) && $embedding !== [])
            ->values()
            ->all();

        if ($approvedEmbeddings === []) {
            $this->error('No approved samples to recompute from.');

            return;
        }

        $speakerService = app(SpeakerIdentificationInterface::class);
        $speakerService->updateProfile($profile, $approvedEmbeddings);

        $this->success('Profile recomputed from '.count($approvedEmbeddings).' approved samples.');
        $this->preacher->refresh();
    }

    public function removeProfile(int $profileId): void
    {
        SpeakerProfile::where('id', $profileId)
            ->where('preacher_id', $this->preacher->id)
            ->update(['is_active' => false]);

        $this->success('Speaker profile deactivated. This preacher will no longer be matched automatically.');
        $this->preacher->refresh();
    }

    public function render(): View
    {
        return view('livewire.admin.preachers.preacher-form', [
            'title' => 'Edit Preacher',
            'aliases' => $this->preacher->aliases,
            'speakerProfiles' => $this->preacher->speakerProfiles()->orderByDesc('is_active')->orderByDesc('updated_at')->get(),
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->preacher->name, 'heading' => 'Edit Preacher']);
    }
}
