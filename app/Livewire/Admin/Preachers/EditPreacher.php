<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class EditPreacher extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public Preacher $preacher;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    public string $newAlias = '';

    public function mount(Preacher $preacher): void
    {

        $this->authorizeAdmin();

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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:preachers,slug,'.$this->preacher->id],
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

        $this->authorizeAdmin();

        $validated = $this->validate();

        $this->preacher->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'bio' => $validated['bio'],
            'is_active' => $validated['isActive'],
        ]);

        Log::warning('Preacher updated by admin', [
            'admin_id' => auth()->id(),
            'preacher_id' => $this->preacher->id,
            'name' => ($fresh = $this->preacher->fresh()) instanceof Preacher ? $fresh->name : $this->preacher->name,
            'slug' => ($fresh instanceof Preacher ? $fresh->slug : $this->preacher->slug),
        ]);

        $this->success('Preacher updated');
    }

    public function addAlias(): void
    {

        $this->authorizeAdmin();

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

        $this->authorizeAdmin();

        $alias = PreacherAlias::where('id', $aliasId)
            ->where('preacher_id', $this->preacher->id)
            ->first();

        if ($alias) {
            Log::warning('Preacher alias removed by admin', [
                'admin_id' => auth()->id(),
                'preacher_id' => $this->preacher->id,
                'preacher_name' => $this->preacher->name,
                'alias_id' => $alias->id,
                'alias' => $alias->alias,
            ]);

            $alias->delete();
        }

        $this->preacher->refresh();
    }

    public function recomputeProfile(int $profileId): void
    {

        $this->authorizeAdmin();

        /** @var SpeakerProfile $profile */
        $profile = SpeakerProfile::where('id', $profileId)
            ->where('preacher_id', $this->preacher->id)
            ->firstOrFail();

        $approvedEmbeddings = SpeakerSample::query()
            ->where('speaker_profile_id', $profile->id)
            ->where('approved', true)
            ->pluck('embedding')
            ->map(function ($embedding): array {
                if (! is_array($embedding)) {
                    return [];
                }

                return array_values(array_map('floatval', $embedding));
            })
            ->filter(fn (array $embedding) => $embedding !== [])
            ->values()
            ->all();
        $approvedEmbeddings = array_values($approvedEmbeddings);

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

        $this->authorizeAdmin();

        $profile = SpeakerProfile::where('id', $profileId)
            ->where('preacher_id', $this->preacher->id)
            ->first();

        if ($profile) {
            Log::warning('Speaker profile deactivated by admin', [
                'admin_id' => auth()->id(),
                'preacher_id' => $this->preacher->id,
                'preacher_name' => $this->preacher->name,
                'profile_id' => $profile->id,
            ]);

            $profile->update(['is_active' => false]);
        }

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
