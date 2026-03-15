<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Preachers</h1>
            <p class="text-gray-600">Manage preachers and their aliases</p>
        </div>
        <x-button link="{{ route('admin.preachers.create') }}" variant="primary" icon="plus" inline>
            Add Preacher
        </x-button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4">
        <x-input placeholder="Search preachers..." wire:model.live.debounce="search"
            icon="magnifying-glass" clearable class="w-64" />

        <x-select placeholder="Active status" wire:model.live="activeFilter"
            :options="[['id' => '1', 'name' => 'Active'], ['id' => '0', 'name' => 'Inactive']]"
            class="w-40" />

        <div x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                Clear Filters
            </x-form-button>
        </div>
    </div>

    {{-- Table --}}
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach($headers as $header)
                            <x-admin.sortable-header
                                :column="$header['key']"
                                :label="$header['label']"
                                :sortable="$header['sortable']"
                                :sortBy="$sortBy"
                                :sortDirection="$sortDirection"
                            />
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($preachers as $preacher)
                        <tr class="hover:bg-gray-50">
                            {{-- Name --}}
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $preacher->name }}</p>
                                <p class="text-sm text-gray-500 font-mono">{{ $preacher->slug }}</p>
                            </td>
                            {{-- Sermons --}}
                            <td class="px-4 py-3">
                                <span class="text-sm">{{ $preacher->sermons_count }}</span>
                            </td>
                            {{-- Status --}}
                            <td class="px-4 py-3">
                                @if($preacher->is_active)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                                @endif
                            </td>
                            {{-- Actions --}}
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1 justify-end">
                                    <x-button link="{{ route('admin.preachers.edit', $preacher) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit preacher: {{ $preacher->name }}" />
                                    <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                        wire:click="delete({{ $preacher->id }})"
                                        wire:confirm="Delete '{{ $preacher->name }}'? This will unlink their sermons."
                                        aria-label="Delete preacher: {{ $preacher->name }}" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($headers) + 1 }}" class="px-4 py-8 text-center text-gray-500">
                                No preachers found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $preachers->links() }}
        </div>
    </x-card>
</div>
