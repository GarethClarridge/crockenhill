<x-admin.list-shell
    title="Preachers"
    description="Manage preachers and their aliases"
    :paginator="$preachers"
    itemsName="preacher"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.preachers.create') }}" variant="primary" icon="plus" inline>
            Add preacher
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, activeFilter, resetFilters">
            <x-input placeholder="Search preachers..." wire:model.live.debounce="search"
                icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

            <x-select placeholder="Active status" wire:model.live="activeFilter"
                :options="[['id' => '1', 'name' => 'Active'], ['id' => '0', 'name' => 'Inactive']]"
                class="w-40" />
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $preachers->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

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
                <tr wire:loading.class="opacity-50 pointer-events-none" wire:target="delete({{ $preacher->id }})" class="hover:bg-gray-50">
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
                            <x-badge variant="success" size="xs">Active</x-badge>
                        @else
                            <x-badge variant="default" size="xs">Inactive</x-badge>
                        @endif
                    </td>
                    {{-- Actions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $preacher->name }}">
                            <x-clipboard-button :url="route('sermons.preacher', $preacher)" hideLabel aria-label="Copy profile link for {{ $preacher->name }}" title="Copy profile link" />
                            <x-button link="{{ route('admin.preachers.edit', $preacher) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit preacher: {{ $preacher->name }}" />
                            <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                wire:click="delete({{ $preacher->id }})"
                                wire:target="delete({{ $preacher->id }})"
                                wire:confirm="Delete '{{ $preacher->name }}'? This will unlink their sermons."
                                aria-label="Delete preacher: {{ $preacher->name }}" />
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="{{ count($headers) + 1 }}"
                    title="No preachers found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <x-button link="{{ route('admin.preachers.create') }}" variant="primary" icon="plus" inline>
                            Add preacher
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
