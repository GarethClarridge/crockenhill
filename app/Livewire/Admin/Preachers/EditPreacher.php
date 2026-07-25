<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Livewire\Traits\WithAdminSave;
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
    use WithAdminSave, WithNotifications;

    public Preacher $preacher;

    public string $name = '';

    public string $slug = '';

    public ?string $bio = null;

    public bool $isActive = true;

    public string $newAlias = '';

    public function mount(Preacher $preacher): void
    {
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
        $modelRules = Preacher::validationRules($this->preacher);

        return [
            'name' => $modelRules['name'],
            'slug' => $modelRules['slug'],
            'bio' => $modelRules['bio'],
            'isActive' => $modelRules['is_active'],
            'newAlias' => 'nullable|string|max:255',
        ];
    }

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    /**
     * Update the specified preacher in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $this->adminSave(
            save: function () use ($validated): array {
                $this->preacher->update([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'bio' => $validated['bio'],
                    'is_active' => $validated['isActive'],
                ]);

                $fresh = $this->preacher->fresh();

                return [
                    'preacher_id' => $this->preacher->id,
                    'name' => $this->sanitizeForLog($fresh instanceof Preacher ? (string) $fresh->name : (string) $this->preacher->name),
                    'slug' => $this->sanitizeForLog($fresh instanceof Preacher ? (string) $fresh->slug : (string) $this->preacher->slug),
                ];
            },
            logAction: 'Preacher updated by admin',
        );

        $this->success('Preacher updated');
    }

    public function addAlias(): void
    {

        $this->authorizeAdmin();

        $this->newAlias = strtolower(trim($this->newAlias));

        $this->validate([
            'newAlias' => PreacherAlias::validationRules()['alias'],
        ], [
            'newAlias.unique' => 'This alias already exists.',
        ]);

        PreacherAlias::query()->create([
            'alias' => $this->newAlias,
            'preacher_id' => $this->preacher->id,
        ]);

        $this->newAlias = '';
        $this->preacher->refresh();
    }

    /**
     * Remove an alias from the preacher.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function removeAlias(int $aliasId): void
    {
        $this->adminSave(
            save: function () use ($aliasId): array {
                $alias = PreacherAlias::query()->where('id', $aliasId)
                    ->where('preacher_id', $this->preacher->id)
                    ->first();

                if ($alias) {
                    $alias->delete();

                    return [
                        'preacher_id' => $this->preacher->id,
                        'preacher_name' => $this->sanitizeForLog((string) $this->preacher->name),
                        'alias_id' => $alias->id,
                        'alias' => $this->sanitizeForLog((string) $alias->alias),
                    ];
                }

                return ['preacher_id' => $this->preacher->id];
            },
            logAction: 'Preacher alias removed by admin',
        );

        $this->preacher->refresh();
    }

    public function recomputeProfile(int $profileId): void
    {

        $this->authorizeAdmin();

        /** @var SpeakerProfile $profile */
        $profile = SpeakerProfile::query()->where('id', $profileId)
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

    /**
     * Deactivate a speaker profile.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function removeProfile(int $profileId): void
    {
        $this->adminSave(
            save: function () use ($profileId): array {
                $profile = SpeakerProfile::query()->where('id', $profileId)
                    ->where('preacher_id', $this->preacher->id)
                    ->first();

                if ($profile) {
                    $profile->update(['is_active' => false]);

                    return [
                        'preacher_id' => $this->preacher->id,
                        'preacher_name' => $this->sanitizeForLog((string) $this->preacher->name),
                        'profile_id' => $profile->id,
                    ];
                }

                return ['preacher_id' => $this->preacher->id];
            },
            logAction: 'Speaker profile deactivated by admin',
        );

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
