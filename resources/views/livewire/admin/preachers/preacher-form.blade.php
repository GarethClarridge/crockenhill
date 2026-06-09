<x-admin.form-shell :title="$title" save-action="save">
    <x-slot:actions>
        <x-button link="{{ route('admin.preachers.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check" loading-label="Saving preacher...">
            Save preacher
        </x-form-button>
    </x-slot:actions>

    <x-card heading="Preacher details">
        <div class="space-y-4">
            <x-input label="Name" wire:model.live.debounce="name" required maxlength="255" autofocus />

            <div class="flex items-start gap-2">
                <div class="flex-1">
                    <x-input label="Slug" wire:model="slug" required maxlength="255"
                        hint="URL-friendly identifier (auto-generated from name)" />
                </div>
                <x-clipboard-button js-content="$wire.slug" hideLabel label="Copy Slug" title="Copy slug to clipboard" class="mt-7" />
            </div>

            <x-textarea label="Bio" wire:model="bio" rows="4" autogrow
                placeholder="Brief biography..." />
        </div>
    </x-card>

    @if(isset($aliases))
        <x-card heading="Aliases">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">Aliases are used to automatically match sermon preacher names to this preacher record.</p>

                @if($aliases->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach($aliases as $alias)
                            <li class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm font-mono text-gray-700">{{ $alias->alias }}</span>
                                <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                    wire:click="removeAlias({{ $alias->id }})"
                                    wire:confirm="Remove alias '{{ $alias->alias }}'?"
                                    aria-label="Remove alias: {{ $alias->alias }}" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-400">No aliases yet.</p>
                @endif

                <div class="flex gap-2 pt-2">
                    <x-input wire:model="newAlias" placeholder="New alias (lowercase)" class="flex-1" maxlength="255" />
                    <x-form-button variant="outline" wire:click="addAlias" icon="plus">
                        Add
                    </x-form-button>
                </div>
            </div>
        </x-card>
    @endif

    @if(isset($speakerProfiles))
        <x-card heading="Speaker profiles">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">Speaker profiles are used for automatic voice-based preacher identification. Deactivating a profile prevents this preacher from being auto-matched in future.</p>

                @if($speakerProfiles->isNotEmpty())
                    <ul class="space-y-3">
                        @foreach($speakerProfiles as $profile)
                            <li class="flex items-start justify-between py-3 border-b border-gray-100 last:border-0">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-800">{{ $profile->provider }} / {{ $profile->model_version }}</span>
                                        @if($profile->is_active)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Active</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        {{ $profile->sample_count }} approved {{ Str::plural('sample', $profile->sample_count) }}
                                        @if($profile->quality_score)
                                            &middot; quality {{ number_format($profile->quality_score, 3) }}
                                        @endif
                                        &middot; updated {{ $profile->updated_at->diffForHumans() }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 ml-4">
                                    @if($profile->is_active)
                                        <x-form-button variant="outline" size="xs" icon="arrow-path"
                                            wire:click="recomputeProfile({{ $profile->id }})"
                                            wire:loading.attr="disabled"
                                            wire:confirm="Recompute this speaker profile from all approved samples?">
                                            Recompute
                                        </x-form-button>
                                        <x-form-button variant="ghost" size="xs" icon="no-symbol" class="text-red-600"
                                            wire:click="removeProfile({{ $profile->id }})"
                                            wire:confirm="Deactivate this speaker profile? This preacher will no longer be auto-matched."
                                            aria-label="Deactivate profile" />
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-400">No speaker profiles yet. Run <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">speaker-profiles:bootstrap</code> to create one.</p>
                @endif
            </div>
        </x-card>
    @endif

    <x-slot:sidebar>
        <x-card heading="Status">
            <div class="space-y-4">
                <x-toggle label="Active" wire:model="isActive"
                    hint="Inactive preachers are hidden from public pages" />
            </div>
        </x-card>
    </x-slot:sidebar>
</x-admin.form-shell>
