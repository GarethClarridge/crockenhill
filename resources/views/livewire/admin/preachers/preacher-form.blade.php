<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">{{ $title }}</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.preachers.index') }}" variant="outline" inline>
                Cancel
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="check">
                Save
            </x-form-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="Preacher Details">
                <div class="space-y-4">
                    <x-input label="Name" wire:model.live.debounce="name" required />

                    <x-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier (auto-generated from name)" />

                    <x-textarea label="Bio" wire:model="bio" rows="4"
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
                                        wire:confirm="Remove alias '{{ $alias->alias }}'?" />
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-400">No aliases yet.</p>
                    @endif

                    <div class="flex gap-2 pt-2">
                        <x-input wire:model="newAlias" placeholder="New alias (lowercase)" class="flex-1" />
                        <x-form-button variant="outline" wire:click="addAlias" icon="plus">
                            Add
                        </x-form-button>
                    </div>
                </div>
            </x-card>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-card heading="Status">
                <div class="space-y-4">
                    <x-toggle label="Active" wire:model="isActive"
                        hint="Inactive preachers are hidden from public pages" />
                </div>
            </x-card>
        </div>
    </div>
</div>
